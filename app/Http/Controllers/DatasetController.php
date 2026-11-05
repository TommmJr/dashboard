<?php

namespace App\Http\Controllers;

use App\Enums\DatasetStatus;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use App\Services\DatasetImportService;
use App\Services\KpiReportConverter;
use App\Services\StructuredKpiImporter;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

        // Hanya admin yang dapat memilih divisi tujuan pada form upload.
        // Staf berdivisi terikat ke divisinya sendiri, dan staf tanpa divisi
        // tidak diberikan hak memilih divisi mana pun.
        $divisions = $user->hasRole(Role::ADMIN)
            ? Division::query()->orderBy('name')->get(['id', 'name', 'description'])
            : collect();

        return view('datasets.create', [
            'divisions' => $divisions,
            'user' => $user,
            'canImportKpi' => $this->canImportKpi($user),
            'kpiPeriods' => $this->importedKpiPeriods($user),
        ]);
    }

    private function canImportKpi(User $user, ?Division $owner = null): bool
    {
        $owner ??= Division::where('slug', 'perencanaan-pengusahaan')->first();

        return $user->hasRole(Role::ADMIN)
            || ($owner !== null && $user->hasRole(Role::STAFF_DIVISI) && $user->division_id !== null && $user->division_id === $owner->id);
    }

    /**
     * Periode yang sudah pernah diimpor pada laporan KPI, untuk ditampilkan
     * di form import. Mencegah salah ketik: `period` berasal dari form,
     * bukan dari isi file, jadi kalau periode sudah ada isinya berarti
     * merevisi (dataset lama diarsipkan).
     *
     * Hanya ditampilkan ke pihak yang boleh mengimpor KPI saja. Period lewat
     * DB::table + format eksplisit Y-m-d, sama seperti PerformanceChartController:
     * cast 'date' pada model menggeser tanggal saat serialisasi (timezone).
     *
     * @return Collection<int, string>
     */
    private function importedKpiPeriods(User $user)
    {
        $owner = Division::where('slug', 'perencanaan-pengusahaan')->first();

        if ($owner === null || ! $this->canImportKpi($user, $owner)) {
            return collect();
        }

        return DB::table('performance_metrics')
            ->join('datasets', 'datasets.id', '=', 'performance_metrics.dataset_id')
            ->where('datasets.status', Dataset::STATUS_READY)
            ->where('datasets.division_id', $owner->id)
            ->distinct()
            ->orderByDesc('performance_metrics.period')
            ->pluck('performance_metrics.period')
            ->map(fn ($period) => Carbon::parse($period)->format('Y-m-d'))
            ->values();
    }

    /**
     * Terima file dan simpan sebagai dataset milik divisi yang sesuai.
     */
    public function store(Request $request, DatasetImportService $importService)
    {
        $maxKb = config('dataset_import.max_size_kb');
        $extensions = implode(',', config('dataset_import.extensions'));
        $user = Auth::user();

        $canSelectDivision = $user->hasRole(Role::ADMIN);

        if (! $canSelectDivision) {
            abort_if($user->division_id === null, 403, 'Staf tanpa divisi tidak dapat mengunggah dataset.');
        }

        $divisionRule = $canSelectDivision
            ? 'required|exists:divisions,id'
            : 'prohibited';

        $request->validate([
            'file' => "required|file|extensions:{$extensions}|mimetypes:application/zip,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/plain,text/csv,application/csv|max:{$maxKb}",
            'division_id' => $divisionRule,
        ]);

        $divisionId = $canSelectDivision
            ? $request->integer('division_id')
            : $user->division_id;

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
     * laporan milik TJBT, sehingga hasilnya digabung menjadi SATU dataset
     * milik divisi Perencanaan Pengusahaan.
     */
    public function importKpiReport(
        Request $request,
        KpiReportConverter $converter,
        DatasetImportService $importService,
        StructuredKpiImporter $structuredImporter,
    ) {
        $user = Auth::user();

        $owner = Division::where('slug', 'perencanaan-pengusahaan')->firstOrFail();

        // Admin dan staf divisi PEMILIK data KPI boleh impor.
        // Pengguna non-privileged tanpa divisi atau staf divisi lain ditolak:
        // mereka tidak boleh memasukkan data ke divisi yang bukan miliknya (scoping divisi).
        $canImport = $user->hasRole(Role::ADMIN)
            || ($user->hasRole(Role::STAFF_DIVISI) && $user->division_id !== null && $user->division_id === $owner->id);

        abort_unless(
            $canImport,
            403,
            'Import laporan KPI hanya untuk admin atau staf divisi Perencanaan Pengusahaan.',
        );

        $maxKb = config('dataset_import.max_size_kb');

        $request->validate([
            'file' => "required|file|extensions:xlsx|mimetypes:application/zip,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet|max:{$maxKb}",
            // Date picker form selalu mengirim Y-m-d; format dipatok agar
            // tidak ada silent-rolling (pola sama dengan kpi:convert --period).
            'period' => 'required|date_format:Y-m-d',
            // Diajukan hanya bila import akan mengarsipkan dataset periode
            // yang sama. BUKAN kontrol keamanan, hanya pengaman salah ketik.
            'confirm_archive' => ['nullable', 'boolean'],
        ]);

        $period = $request->string('period')->toString();

        // Pre-flight: periode sudah ada = merevisi, bukan menambah. Arsipkan
        // adalah satu arah (tidak ada route un-archive), jadi minta persetujuan
        // eksplisit SEBELUM file dibaca dan dataset ditulis. Dicek di sini, bukan
        // dengan memindahkan query arsip ke atas: query itu juga menyumbang
        // pesan "diarsipkan otomatis", dan harus tetap kosong saat import gagal.
        $willSupersede = Dataset::query()
            ->where('division_id', $owner->id)
            ->where('status', Dataset::STATUS_READY)
            ->whereHas('metrics', fn ($query) => $query->where('period', $period))
            ->exists();

        if ($willSupersede && ! $request->boolean('confirm_archive')) {
            return back()
                ->withInput()
                ->withErrors([
                    'period' => "Periode {$period} sudah pernah diimpor. Mengimpor periode yang sama akan mengarsipkan dataset lama (data lama tetap tersimpan). Periksa kembali periodenya, lalu centang konfirmasi bila ini memang revisi.",
                ]);
        }

        // Path file asli dipegang di sini (bukan dibaca ulang dari request di
        // dalam blok import) supaya jalur tulis terstruktur memakai berkas yang
        // sama persis dengan yang divalidasi dan dibaca extractPerUnit().
        $originalPath = $request->file('file')->getRealPath();

        try {
            $perUnit = $converter->extractPerUnit(
                $originalPath,
                $request->string('period')->toString(),
            );
        } catch (Throwable $e) {
            Log::warning('Import laporan KPI gagal dibaca', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'File tidak dapat dibaca. Pastikan file Excel tidak rusak.');
        }

        $failed = [];
        $validRows = [];

        foreach ($perUnit as $unit => $outcome) {
            if ($outcome instanceof Throwable) {
                $failed[] = "{$unit}: layout sheet tidak dikenali — periksa file.";

                continue;
            }

            if ($outcome === []) {
                $failed[] = "{$unit}: tidak ada baris data yang bisa dibaca.";

                continue;
            }

            array_push($validRows, ...$outcome);
        }

        $imported = [];
        $superseded = collect();

        if ($validRows !== []) {
            $convertedPath = $converter->write($validRows);

            try {
                $file = new UploadedFile($convertedPath, 'laporan-kpi-'.$period.'.xlsx',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

                ['dataset' => $dataset, 'result' => $result] = $this->storeAndImport(
                    $file, $owner->id, $user, $importService,
                );

                if ($result === null || $dataset->status === DatasetStatus::FAILED) {
                    $failed[] = $owner->name.': gagal diproses — '.($dataset->error_message ?? 'coba periksa file.');
                } else {
                    $structuredSuccess = false;
                    $structuredCounts = null;

                    try {
                        $structuredCounts = $structuredImporter->import(
                            $originalPath, $dataset, $period,
                        );

                        if (($structuredCounts['observations'] ?? 0) > 0) {
                            $structuredSuccess = true;
                        } else {
                            $failed[] = $owner->name.': tidak ada observasi terstruktur yang dihasilkan dari file.';
                        }
                    } catch (Throwable $e) {
                        Log::warning('Tulis terstruktur KPI gagal (membatalkan status READY dan pengarsipan)', [
                            'user_id' => $user->id,
                            'dataset_id' => $dataset->id,
                            'error' => $e->getMessage(),
                        ]);

                        $failed[] = $owner->name.': penulisan tabel terstruktur gagal — '.$e->getMessage();
                    }

                    if (! $structuredSuccess) {
                        // Gagalkan dataset baru dan bersihkan metrik legacy agar tidak menjadi ghost READY
                        $dataset->metrics()->delete();
                        $dataset->update([
                            'status' => DatasetStatus::FAILED,
                            'error_message' => 'Penulisan tabel terstruktur gagal. Dataset lama tetap dipertahankan aktif.',
                        ]);
                    } else {
                        // Hanya mengarsipkan dataset lama jika dataset baru terbukti READY dan lengkap
                        $superseded = Dataset::query()
                            ->where('division_id', $owner->id)
                            ->where('status', Dataset::STATUS_READY)
                            ->whereKeyNot($dataset->id)
                            ->whereHas('metrics', fn ($query) => $query->where('period', $period))
                            ->orderBy('id')
                            ->get();

                        $archivalSuccess = true;

                        if ($superseded->isNotEmpty()) {
                            try {
                                DB::transaction(function () use ($superseded) {
                                    foreach ($superseded as $previous) {
                                        $previous->update(['status' => Dataset::STATUS_ARCHIVED]);
                                    }
                                });
                            } catch (Throwable $e) {
                                $archivalSuccess = false;
                                Log::warning('Pengarsipan dataset lama gagal; kompensasi membatalkan dataset baru ke FAILED', [
                                    'user_id' => $user->id,
                                    'dataset_id' => $dataset->id,
                                    'error' => $e->getMessage(),
                                ]);

                                $dataset->observations()->delete();
                                $dataset->metrics()->delete();
                                $dataset->update([
                                    'status' => DatasetStatus::FAILED,
                                    'error_message' => 'Gagal mengarsipkan dataset sebelumnya: '.$e->getMessage(),
                                ]);

                                $failed[] = $owner->name.': gagal mengarsipkan dataset lama — '.$e->getMessage();
                                $superseded = collect();
                            }
                        }

                        if ($archivalSuccess) {
                            $imported[] = "{$owner->name}: {$result['imported']} baris".sprintf(
                                ' (+%d indikator, %d observasi terstruktur)',
                                $structuredCounts['indicators'],
                                $structuredCounts['observations'],
                            );
                        }
                    }
                }
            } finally {
                @unlink($convertedPath);
            }
        }

        if ($imported !== []) {
            $message = 'Berhasil mengimpor '.count($imported)." dataset.\n".implode("\n", $imported);
            if ($superseded->isNotEmpty()) {
                $message .= "\n\nDataset periode {$period} sebelumnya diarsipkan otomatis (".$superseded->count().' dataset) agar data tidak berlipat.';
            }
            $flashKey = $failed !== [] ? 'error' : 'success';
        } else {
            $message = 'Gagal mengimpor laporan KPI. Data lama tetap dipertahankan.';
            $flashKey = 'error';
        }

        return redirect()
            ->route('datasets.create')
            ->with($flashKey, $message)
            ->with('kpi_failed', $failed);
    }

    /**
     * Simpan satu file upload + jalankan pipeline impor. Dipakai oleh
     * store() dan importKpiReport(). Exception dari import() ditangkap di sini
     * supaya dataset gagal tidak menggantung pada status UPLOADING.
     *
     * @return array{dataset: Dataset, result: array{imported: int, skipped: int, warnings: int}|null}
     */
    private function storeAndImport(
        UploadedFile $file,
        int $divisionId,
        User $user,
        DatasetImportService $importService,
        bool $recordAudit = true,
    ): array {
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
            $result = $importService->import($file, $user, $dataset, $recordAudit);
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
        $hasObservations = $dataset->observations()->exists();

        return view('datasets.show', compact('dataset', 'metrics', 'hasObservations'));
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
