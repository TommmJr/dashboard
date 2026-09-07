<?php

namespace App\Http\Controllers;

use App\Models\Dataset;
use App\Models\Role;
use App\Services\DatasetImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class DatasetController extends Controller
{
    /**
     * Form upload dataset baru (Tahap 4). Gate dataset.import di route.
     */
    public function create()
    {
        return view('datasets.create');
    }

    /**
     * Unduh template .xlsx generik (kolom dari config/dataset_import.php).
     */
    public function template(DatasetImportService $importService)
    {
        $tempPath = $importService->buildTemplate();

        return response()->download($tempPath, 'template-dataset.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /**
     * Terima file, buat record Dataset (UPLOADING), jalankan pipeline impor.
     *
     * Jika pipeline melempar exception (file korup, bukan Excel, dst.),
     * dataset ditandai FAILED + error_message — tidak ada status menggantung.
     */
    public function store(Request $request, DatasetImportService $importService)
    {
        $maxKb = config('dataset_import.max_size_kb');
        $extensions = implode(',', config('dataset_import.extensions'));

        // mimetypes (bukan mimes): cek isi file, bukan hanya ekstensi.
        // ZIP-based (xlsx) dan teks (csv) diterima; sisanya ditolak di
        // lapisan validasi sebelum menyentuh pipeline.
        $request->validate([
            'file' => "required|file|extensions:{$extensions}|mimetypes:application/zip,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/plain,text/csv,application/csv|max:{$maxKb}",
        ]);

        $user = Auth::user();
        $file = $request->file('file');

        // Simpan file ke storage lokal (audit trail + kebutuhan delete).
        $storedName = Str::random(32).'.'.$file->getClientOriginalExtension();
        $file->storeAs('datasets', $storedName, 'local');

        $dataset = Dataset::create([
            'division_id' => $user->division_id,
            'user_id' => $user->id,
            'filename' => $storedName,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'status' => Dataset::STATUS_UPLOADING,
        ]);

        // Metadata saja — JANGAN log isi baris (data kinerja sensitif).
        Log::info('Dataset import dimulai', [
            'dataset_id' => $dataset->id,
            'original_name' => $dataset->original_name,
            'size' => $dataset->size,
            'user_id' => $user->id,
        ]);

        try {
            $result = $importService->import($file, $user, $dataset);
        } catch (Throwable $e) {
            $dataset->update([
                'status' => Dataset::STATUS_FAILED,
                'error_message' => 'File tidak dapat diproses: format tidak dikenali atau korup.',
            ]);

            Log::warning('Dataset import gagal total', [
                'dataset_id' => $dataset->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->route('datasets.show', $dataset)
                ->with('error', 'File tidak dapat diproses. Pastikan sesuai template.');
        }

        Log::info('Dataset import selesai', [
            'dataset_id' => $dataset->id,
            'imported' => $result['imported'],
            'skipped' => $result['skipped'],
        ]);

        $dataset = $dataset->fresh();

        if ($dataset->status === Dataset::STATUS_FAILED) {
            return redirect()
                ->route('datasets.show', $dataset)
                ->with('error', $dataset->error_message);
        }

        $message = "Berhasil mengimpor {$result['imported']} baris.";
        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} baris dilewati karena tidak valid.";
        }
        if ($result['warnings'] > 0) {
            $message .= " {$result['warnings']} baris ditandai WARNING (outlier).";
        }

        return redirect()
            ->route('datasets.show', $dataset)
            ->with('success', $message);
    }

    /**
     * Daftar dataset.
     *
     * Scoping (keputusan archive-vs-delete, plan Fase 1):
     * - admin, management, editor: melihat semua divisi.
     * - staf_divisi: hanya dataset divisinya sendiri.
     * Default menyembunyikan ARCHIVED; filter via ?status=ARCHIVED dll.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        $query = Dataset::query()
            ->with(['division:id,name', 'user:id,name'])
            ->latest();

        if (! $this->seesAllDivisions($user)) {
            $query->where('division_id', $user->division_id);
        }

        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        } else {
            $query->where('status', '!=', Dataset::STATUS_ARCHIVED);
        }

        $datasets = $query->paginate(15)->withQueryString();

        return view('datasets.index', compact('datasets'));
    }

    /**
     * Detail dataset + preview baris metric yang terimpor.
     */
    public function show(Dataset $dataset)
    {
        $user = Auth::user();
        abort_unless(
            $this->seesAllDivisions($user) || ($user->division_id !== null && $user->division_id === $dataset->division_id),
            403,
        );

        $dataset->load(['division:id,name', 'user:id,name']);
        $metrics = $dataset->metrics()->orderBy('period')->paginate(20);

        return view('datasets.show', compact('dataset', 'metrics'));
    }

    /**
     * Archive dataset (PATCH): staf pemilik divisi boleh archive dataset
     * divisinya; admin bebas. Dataset archived keluar dari agregasi chart
     * tetapi data tetap di DB (reversible via upload ulang).
     */
    public function archive(Dataset $dataset)
    {
        $this->authorizeDivisionOwnership($dataset);

        abort_if($dataset->status === Dataset::STATUS_ARCHIVED, 422, 'Dataset sudah diarsipkan.');

        $dataset->update(['status' => Dataset::STATUS_ARCHIVED]);

        return redirect()
            ->route('datasets.index')
            ->with('success', "Dataset \"{$dataset->original_name}\" berhasil diarsipkan.");
    }

    /**
     * Delete permanen (admin only, gate dataset.delete di route).
     * Baris metric ikut terhapus via cascade (audit: jejak file sumber
     * hilang bersama datanya, hanya boleh dilakukan admin).
     */
    public function destroy(Dataset $dataset)
    {
        Storage::disk('local')->delete('datasets/'.$dataset->filename);
        $dataset->delete();

        return redirect()
            ->route('datasets.index')
            ->with('success', "Dataset \"{$dataset->original_name}\" dihapus permanen.");
    }

    /**
     * Staf hanya boleh menyentuh dataset divisinya sendiri; role lain yang
     * lolos gate (admin) bebas. Tanpa division_id yang cocok = 403.
     */
    private function authorizeDivisionOwnership(Dataset $dataset): void
    {
        $user = Auth::user();

        if ($this->seesAllDivisions($user)) {
            return;
        }

        abort_unless($user->division_id !== null && $user->division_id === $dataset->division_id, 403);
    }

    /**
     * Role tanpa pembatasan divisi: admin selalu; role lain mengikuti
     * permission dataset.delete (hanya admin saat ini) — selebihnya
     * management/editor melihat semua divisi (dashboard transparan),
     * staf_divisi dibatasi divisinya.
     */
    private function seesAllDivisions($user): bool
    {
        return Gate::forUser($user)->allows('dataset.delete')
            || $user->hasRole(Role::MANAGEMENT)
            || $user->hasRole(Role::EDITOR_DASHBOARD);
    }
}
