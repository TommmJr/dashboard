<?php

namespace App\Http\Controllers;

use App\Enums\DatasetStatus;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use App\Services\DatasetImportService;
use App\Services\KpiReportConverter;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class DatasetController extends Controller
{
    public function create()
    {
        $user = Auth::user()->loadMissing('division');

        $divisions = $user->division_id === null
            ? Division::query()->orderBy('name')->get(['id', 'name', 'description'])
            : collect();

        return view('datasets.create', compact('divisions', 'user'));
    }

    /**
     * Terima file dan simpan sebagai dataset milik divisi yang sesuai.
     */
    public function store(Request $request, DatasetImportService $importService)
    {
        $maxKb = config('dataset_import.max_size_kb');
        $extensions = implode(',', config('dataset_import.extensions'));
        $user = Auth::user();

        $divisionRule = $user->division_id === null
            ? 'required|exists:divisions,id'
            : 'prohibited';

        $request->validate([
            'file' => "required|file|extensions:{$extensions}|mimetypes:application/zip,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/plain,text/csv,application/csv|max:{$maxKb}",
            'division_id' => $divisionRule,
        ]);

        $divisionId = $user->division_id ?? $request->integer('division_id');

        ['dataset' => $dataset, 'result' => $result] = $this->storeAndImport(
            $request->file('file'), $divisionId, $user, $importService,
        );

        if ($result === null) {
            return redirect()
                ->route('datasets.show', $dataset)
                ->with('error', 'File tidak dapat diproses. Pastikan sesuai template.');
        }

        if ($dataset->status === DatasetStatus::FAILED) {
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
     * Import laporan KPI mentah dari mentor dalam 1 klik, tanpa command line.
     * Semua sheet unit pada workbook adalah dimensi pembanding dalam satu
     * laporan milik TJBT, sehingga hasilnya digabung menjadi SATU dataset.
     */
    public function importKpiReport(Request $request, KpiReportConverter $converter, DatasetImportService $importService)
    {
        $user = Auth::user();

        abort_unless($user->division_id === null, 403, 'Import laporan KPI hanya untuk user tanpa divisi tetap.');

        $maxKb = config('dataset_import.max_size_kb');

        $request->validate([
            'file' => "required|file|extensions:xlsx|mimetypes:application/zip,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet|max:{$maxKb}",
            // Date picker form selalu mengirim Y-m-d; format dipatok agar
            // tidak ada silent-rolling (pola sama dengan kpi:convert --period).
            'period' => 'required|date_format:Y-m-d',
        ]);

        try {
            $perUnit = $converter->extractPerUnit(
                $request->file('file')->getRealPath(),
                $request->string('period')->toString(),
            );
        } catch (Throwable $e) {
            Log::warning('Import laporan KPI gagal dibaca', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'File tidak dapat dibaca. Pastikan file Excel laporan KPI dari mentor tidak rusak.');
        }

        $owner = Division::where('slug', 'perencanaan-pengusahaan')->firstOrFail();
        $failed = [];
        $validRows = [];

        foreach ($perUnit as $unit => $outcome) {
            if ($outcome instanceof Throwable) {
                $failed[] = "{$unit}: layout sheet tidak dikenali — periksa file dari mentor.";

                continue;
            }

            if ($outcome === []) {
                $failed[] = "{$unit}: tidak ada baris data yang bisa dibaca.";

                continue;
            }

            array_push($validRows, ...$outcome);
        }

        $imported = [];

        if ($validRows !== []) {
            $convertedPath = $converter->write($validRows);

            try {
                $file = new UploadedFile($convertedPath, 'laporan-kpi-'.$request->string('period')->toString().'.xlsx',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

                ['dataset' => $dataset, 'result' => $result] = $this->storeAndImport(
                    $file, $owner->id, $user, $importService,
                );

                if ($result === null || $dataset->status === DatasetStatus::FAILED) {
                    $failed[] = $owner->name.': gagal diproses — '.($dataset->error_message ?? 'coba periksa file.');
                } else {
                    $imported[] = "{$owner->name}: {$result['imported']} baris";
                }
            } finally {
                @unlink($convertedPath);
            }
        }

        $message = $imported !== []
            ? 'Berhasil mengimpor '.count($imported)." dataset.\n".implode("\n", $imported)
            : 'Tidak ada unit yang berhasil diimpor.';

        return redirect()
            ->route('datasets.create')
            ->with($imported !== [] ? 'success' : 'error', $message)
            ->with('kpi_failed', $failed);
    }

    /**
     * Simpan satu file upload + jalankan pipeline impor. Dipakai oleh
     * store() dan importKpiReport(). Exception dari import() ditangkap di sini
     * supaya dataset gagal tidak menggantung pada status UPLOADING.
     *
     * @return array{dataset: Dataset, result: array{imported: int, skipped: int, warnings: int}|null}
     */
    private function storeAndImport(UploadedFile $file, int $divisionId, User $user, DatasetImportService $importService): array
    {
        // Ekstensi dari whitelist (bukan dari client) + sanitasi nama file asli
        // agar karakter berbahaya tidak tersimpan di DB (MEDIUM-04).
        $allowedExts = config('dataset_import.extensions', ['xlsx', 'csv']);
        $ext = strtolower($file->getClientOriginalExtension());
        $safeExt = in_array($ext, $allowedExts, true) ? $ext : 'bin';
        $storedName = Str::random(32).'.'.$safeExt;
        $safeOriginalName = mb_substr(
            preg_replace('/[^\w\s\-_.()\p{L}]/u', '', $file->getClientOriginalName()),
            0, 255
        );

        $file->storeAs('datasets', $storedName, 'local');

        $dataset = Dataset::create([
            'division_id' => $divisionId,
            'user_id' => $user->id,
            'filename' => $storedName,
            'original_name' => $safeOriginalName ?: 'dataset.'.$safeExt,
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

            return ['dataset' => $dataset->fresh(), 'result' => null];
        }

        Log::info('Dataset import selesai', [
            'dataset_id' => $dataset->id,
            'imported' => $result['imported'],
            'skipped' => $result['skipped'],
        ]);

        return ['dataset' => $dataset->fresh(), 'result' => $result];
    }

    /**
     * Daftar dataset.
     *
     * - admin, management, editor: melihat semua divisi.
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
        $allowedStatuses = [
            Dataset::STATUS_UPLOADING,
            Dataset::STATUS_VALIDATING,
            Dataset::STATUS_READY,
            Dataset::STATUS_FAILED,
            Dataset::STATUS_ARCHIVED,
        ];

        if (is_string($status) && in_array($status, $allowedStatuses, true)) {
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
     * Export baris metric dataset ke .xlsx.
     * Scoping: ikut aturan show() — seesAllDivisions atau staf divisi sama.
     * Tidak tercatat di audit log (aksi baca, bukan tulis).
     */
    public function export(Dataset $dataset)
    {
        $user = Auth::user();
        abort_unless(
            $this->seesAllDivisions($user) || ($user->division_id !== null && $user->division_id === $dataset->division_id),
            403,
        );

        $metrics = $dataset->metrics()->orderBy('period')->get(['metric_name', 'value', 'period']);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data');

        // Header
        $sheet->fromArray(['No', 'Metric Name', 'Value', 'Period'], null, 'A1');
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);

        // Rows
        foreach ($metrics as $i => $metric) {
            $sheet->fromArray([
                $i + 1,
                $metric->metric_name,
                (float) $metric->value,
                is_string($metric->period) ? substr($metric->period, 0, 10) : $metric->period->format('Y-m-d'),
            ], null, 'A'.($i + 2));
        }

        // Auto-size columns
        foreach (['A', 'B', 'C', 'D'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'export_').'.xlsx';
        (new Xlsx($spreadsheet))->save($tempPath);
        $spreadsheet->disconnectWorksheets();

        $baseName = pathinfo($dataset->original_name, PATHINFO_FILENAME);
        $filename = $baseName.'-export.xlsx';

        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /**
     * Archive dataset (PATCH): staf pemilik divisi boleh archive dataset
     * divisinya; admin bebas. Dataset archived keluar dari agregasi chart
     * tetapi data tetap di DB (reversible via upload ulang).
     */
    public function archive(Dataset $dataset)
    {
        $this->authorizeDivisionOwnership($dataset);

        abort_if($dataset->status === DatasetStatus::ARCHIVED, 422, 'Dataset sudah diarsipkan.');

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
        $originalName = $dataset->original_name;
        $filePath = 'datasets/'.$dataset->filename;

        // Cek dan hapus file dari storage. Jika file masih ada tapi gagal
        // dihapus, batalkan operasi agar tidak terjadi orphan record DB.
        if (Storage::disk('local')->exists($filePath)) {
            $deleted = Storage::disk('local')->delete($filePath);

            if (! $deleted) {
                // Selalu ke index (bukan back()): back() dari halaman detail
                return redirect()
                    ->route('datasets.index')
                    ->with('error', 'Gagal menghapus file dari storage. Dataset tidak dihapus.');
            }
        }

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
     */
    private function seesAllDivisions($user): bool
    {
        return Gate::forUser($user)->allows('dataset.delete')
            || $user->hasRole(Role::MANAGEMENT)
            || $user->hasRole(Role::EDITOR_DASHBOARD);
    }
}
