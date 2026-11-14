<?php

namespace App\Http\Controllers;

use App\Enums\DatasetStatus;
use App\Exceptions\AssetSyncException;
use App\Models\AuditLog;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use App\Services\AssetDualUploadService;
use App\Services\AssetSheetsSyncService;
use App\Services\AssetStockReport;
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
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
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

        $assetDivision = Division::where('slug', 'managemen-aset')->first();
        $canUploadAsset = $user->hasRole(Role::ADMIN)
            || ($assetDivision !== null && $user->hasRole(Role::STAFF_DIVISI) && $user->division_id !== null && (int) $user->division_id === (int) $assetDivision->id);

        return view('datasets.create', [
            'divisions' => $divisions,
            'user' => $user,
            'canImportKpi' => $this->canImportKpi($user),
            'kpiPeriods' => $this->importedKpiPeriods($user),
            'canUploadAsset' => $canUploadAsset,
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

        $metricPeriods = DB::table('performance_metrics')
            ->join('datasets', 'datasets.id', '=', 'performance_metrics.dataset_id')
            ->where('datasets.status', Dataset::STATUS_READY)
            ->where('datasets.division_id', $owner->id)
            ->pluck('performance_metrics.period');

        $observationPeriods = DB::table('performance_observations')
            ->join('datasets', 'datasets.id', '=', 'performance_observations.dataset_id')
            ->where('datasets.status', Dataset::STATUS_READY)
            ->where('datasets.division_id', $owner->id)
            ->pluck('performance_observations.period');

        return $metricPeriods->concat($observationPeriods)
            ->map(fn ($period) => Carbon::parse($period)->format('Y-m-d'))
            ->unique()
            ->sortDesc()
            ->values();
    }

    /**
     * Terima file dan simpan sebagai dataset milik divisi yang sesuai.
     */
    public function store(
        Request $request,
        DatasetImportService $importService,
        AssetDualUploadService $assetDualService,
    ) {
        $maxKb = config('dataset_import.max_size_kb');
        $extensions = implode(',', config('dataset_import.extensions'));
        $user = Auth::user();

        $canSelectDivision = $user->hasRole(Role::ADMIN);

        if (! $canSelectDivision) {
            abort_if($user->division_id === null, 403, 'Staf tanpa divisi tidak dapat mengunggah dataset.');
        }

        $assetDivisionId = Division::where('slug', 'managemen-aset')->value('id');

        $divisionId = $canSelectDivision
            ? $request->integer('division_id')
            : $user->division_id;

        $isAssetTarget = ($assetDivisionId !== null && (int) $divisionId === (int) $assetDivisionId);

        // Fail-closed authorization jika menargetkan divisi Manajemen Aset
        if ($isAssetTarget || ($request->filled('division_id') && (int) $request->integer('division_id') === (int) $assetDivisionId)) {
            $canUploadAsset = $user->hasRole(Role::ADMIN)
                || ($user->hasRole(Role::STAFF_DIVISI) && $user->division_id !== null && (int) $user->division_id === (int) $assetDivisionId);

            abort_unless($canUploadAsset, 403, 'Hanya admin atau staf divisi Manajemen Aset yang dapat mengunggah dataset Manajemen Aset.');
        }

        // Deteksi apakah ini dual upload aset atau ditargetkan ke Manajemen Aset
        $hasDualFile = $request->hasFile('file_1b') || $request->hasFile('file_1a') || $request->hasFile('file_uit') || $request->input('upload_type') === 'asset';

        if ($isAssetTarget) {
            $file = $request->file('file');
            $hasGenericTemplate = ($file instanceof UploadedFile && $this->isGenericTemplate($file));

            if ($hasDualFile || ! $hasGenericTemplate) {
                return $this->uploadAssetDual($request, $assetDualService);
            }
        } elseif ($hasDualFile) {
            return $this->uploadAssetDual($request, $assetDualService);
        }

        $divisionRule = $canSelectDivision
            ? 'required|exists:divisions,id'
            : 'prohibited';

        $request->validate([
            'file' => "required|file|extensions:{$extensions}|mimetypes:application/zip,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/plain,text/csv,application/csv|max:{$maxKb}",
            'division_id' => $divisionRule,
        ]);

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
     * Upload dan proses dual berkas rekapitulasi aset (Tab 1b & Tab 1a).
     */
    public function uploadAssetDual(Request $request, AssetDualUploadService $service)
    {
        $user = Auth::user();
        $assetDivision = Division::where('slug', 'managemen-aset')->firstOrFail();
        $assetDivisionId = $assetDivision->id;

        $canUploadAsset = $user->hasRole(Role::ADMIN)
            || ($user->hasRole(Role::STAFF_DIVISI) && $user->division_id !== null && (int) $user->division_id === (int) $assetDivisionId);

        abort_unless($canUploadAsset, 403, 'Hanya admin atau staf divisi Manajemen Aset yang dapat mengunggah dataset Manajemen Aset.');

        $maxKb = config('dataset_import.max_size_kb', 10240);
        $extensions = implode(',', config('dataset_import.extensions', ['xlsx', 'csv']));

        // Cek kehadiran kedua berkas: file_1b (atau file) dan file_1a (atau file_uit)
        $has1b = $request->hasFile('file_1b') || $request->hasFile('file');
        $has1a = $request->hasFile('file_1a') || $request->hasFile('file_uit');

        if (! $has1b || ! $has1a) {
            $rules = [];
            if (! $has1b) {
                $rules[$request->has('file') ? 'file' : 'file_1b'] = 'required';
            }
            if (! $has1a) {
                $rules[$request->has('file_uit') ? 'file_uit' : 'file_1a'] = 'required';
            }

            $messages = [
                'file_1b.required' => 'Berkas Tab 1b (level UPT) wajib diunggah untuk divisi Manajemen Aset.',
                'file.required' => 'Berkas Tab 1b (level UPT) wajib diunggah untuk divisi Manajemen Aset.',
                'file_1a.required' => 'Berkas Tab 1a (level UIT JBT) wajib diunggah untuk divisi Manajemen Aset.',
                'file_uit.required' => 'Berkas Tab 1a (level UIT JBT) wajib diunggah untuk divisi Manajemen Aset.',
            ];

            $request->validate($rules, $messages);
        }

        $key1b = $request->hasFile('file_1b') ? 'file_1b' : 'file';
        $key1a = $request->hasFile('file_1a') ? 'file_1a' : 'file_uit';

        $request->validate([
            $key1b => "required|file|extensions:{$extensions}|mimetypes:application/zip,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/plain,text/csv,application/csv|max:{$maxKb}",
            $key1a => "required|file|extensions:{$extensions}|mimetypes:application/zip,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/plain,text/csv,application/csv|max:{$maxKb}",
            'period' => 'nullable|date_format:Y-m-d',
        ]);

        $file1b = $request->file($key1b);
        $file1a = $request->file($key1a);

        try {
            $result = $service->upload(
                $file1b,
                $file1a,
                $user,
                $request->filled('period') ? $request->string('period')->toString() : null
            );

            $dataset = $result['dataset'];

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => "Berhasil mengimpor {$result['total_rows']} baris metrik aset.",
                    'dataset' => $dataset,
                    'result' => $result,
                ], 200);
            }

            return redirect()
                ->route('datasets.show', $dataset)
                ->with('success', "Berhasil mengimpor {$result['total_rows']} baris metrik aset ({$result['rows_1b']} baris UPT, {$result['rows_1a']} baris UIT).");
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('Dual-file web upload aset gagal', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Berkas rekap aset tidak dapat diproses: '.$e->getMessage(),
                    'error' => $e->getMessage(),
                ], 422);
            }

            return redirect()
                ->route('datasets.create')
                ->with('error', 'Berkas rekap aset tidak dapat diproses: '.$e->getMessage());
        }
    }

    /**
     * Sinkronisasi data aset dari Google Sheets (Tab 1b & Tab 1a).
     */
    public function syncAssetSheets(Request $request, AssetSheetsSyncService $syncService)
    {
        $user = Auth::user();
        $assetDivision = Division::where('slug', 'managemen-aset')->first();
        $assetDivisionId = $assetDivision?->id;

        $canSyncAsset = $user->hasRole(Role::ADMIN)
            || ($assetDivisionId !== null && $user->hasRole(Role::STAFF_DIVISI) && $user->division_id !== null && (int) $user->division_id === (int) $assetDivisionId);

        abort_unless($canSyncAsset, 403, 'Hanya admin atau staf divisi Manajemen Aset yang dapat menyinkronkan dataset Manajemen Aset.');

        try {
            $result = $syncService->sync(dryRun: false, period: null, user: $user);

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => "Sinkronisasi data aset dari Google Sheets berhasil (Periode: {$result['period']}).",
                    'dataset' => $result['dataset'],
                    'result' => $result,
                ], 200);
            }

            return redirect()
                ->route('datasets.index')
                ->with('success', "Sinkronisasi data aset dari Google Sheets berhasil (Periode: {$result['period']}).");
        } catch (AssetSyncException $e) {
            $errorMessage = AssetSyncException::sanitize($e->getMessage());

            Log::warning('Sinkronisasi data aset dari Google Sheets gagal', [
                'user_id' => $user->id,
                'error' => $errorMessage,
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Gagal menyinkronkan data aset: '.$errorMessage,
                    'error' => $errorMessage,
                ], 422);
            }

            return back()
                ->with('error', 'Gagal menyinkronkan data aset: '.$errorMessage);
        } catch (Throwable $e) {
            Log::error('Kesalahan tak terduga saat sinkronisasi data aset', [
                'user_id' => $user->id,
                'exception' => get_class($e),
                'error' => AssetSyncException::sanitize($e->getMessage()),
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Terjadi kesalahan sistem saat sinkronisasi data aset. Hubungi administrator.',
                ], 500);
            }

            return back()
                ->with('error', 'Terjadi kesalahan sistem saat sinkronisasi data aset. Hubungi administrator.');
        }
    }

    /**
     * Cek apakah berkas yang diunggah adalah template generik (header metric_name).
     */
    private function isGenericTemplate(UploadedFile $file): bool
    {
        $ext = strtolower($file->getClientOriginalExtension());

        if ($ext === 'csv') {
            $handle = @fopen($file->getRealPath(), 'r');
            if ($handle === false) {
                return false;
            }
            $firstLine = fgets($handle);
            fclose($handle);

            if ($firstLine === false) {
                return false;
            }

            return str_contains(mb_strtolower($firstLine), 'metric_name');
        }

        try {
            $reader = IOFactory::createReaderForFile($file->getRealPath());
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($file->getRealPath());
            $firstVal = (string) $spreadsheet->getActiveSheet()->getCell('A1')->getValue();
            $spreadsheet->disconnectWorksheets();

            return mb_strtolower(trim($firstVal)) === 'metric_name';
        } catch (Throwable) {
            return false;
        }
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
            ->where(function ($query) use ($period) {
                $query->whereHas('metrics', fn ($q) => $q->where('period', $period))
                    ->orWhereHas('observations', fn ($q) => $q->where('period', $period));
            })
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
            $dataset = null;

            try {
                $file = new UploadedFile($convertedPath, 'laporan-kpi-'.$period.'.xlsx',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

                ['dataset' => $dataset, 'result' => $result] = $this->storeAndImport(
                    $file, $owner->id, $user, $importService, recordAudit: false, successStatus: Dataset::STATUS_VALIDATING,
                );

                if ($result === null || $dataset->status === DatasetStatus::FAILED) {
                    $failed[] = $owner->name.': gagal diproses — '.($dataset->error_message ?? 'coba periksa file.');
                    $this->cleanupFailedDataset($dataset);
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
                        // Gagalkan dataset baru, bersihkan metrik & observasi, dan hapus berkas fisik storage
                        $this->rollbackAndCleanupKpiDataset(
                            $dataset,
                            'Penulisan tabel terstruktur gagal. Dataset lama tetap dipertahankan aktif.',
                        );
                    } else {
                        // Hanya mengarsipkan dataset lama jika dataset baru terbukti lengkap.
                        // Seluruh transisi status (arsip lama, aktivasi READY, dan audit log)
                        // dibungkus dalam SATU transaksi atomis dengan penguncian (lockForUpdate)
                        // demi mencegah race condition atau dataset menggantung.
                        $archivalSuccess = true;

                        try {
                            DB::transaction(function () use ($dataset, $owner, $period, &$superseded, $result, $structuredCounts, $user) {
                                $superseded = Dataset::query()
                                    ->where('division_id', $owner->id)
                                    ->where('status', Dataset::STATUS_READY)
                                    ->whereKeyNot($dataset->id)
                                    ->where(function ($query) use ($period) {
                                        $query->whereHas('metrics', fn ($q) => $q->where('period', $period))
                                            ->orWhereHas('observations', fn ($q) => $q->where('period', $period));
                                    })
                                    ->lockForUpdate()
                                    ->orderBy('id')
                                    ->get();

                                foreach ($superseded as $previous) {
                                    $previous->update(['status' => Dataset::STATUS_ARCHIVED]);
                                }

                                $dataset->update(['status' => Dataset::STATUS_READY]);

                                AuditLog::record(
                                    AuditLog::ACTION_IMPORT,
                                    $dataset,
                                    null,
                                    [
                                        'rows_imported' => $result['imported'],
                                        'rows_skipped' => $result['skipped'],
                                        'org_units_count' => $structuredCounts['org_units'] ?? 0,
                                        'indicators_count' => $structuredCounts['indicators'] ?? 0,
                                        'observations_count' => $structuredCounts['observations'] ?? 0,
                                    ],
                                    $user->id,
                                );
                            });
                        } catch (Throwable $e) {
                            $archivalSuccess = false;
                            Log::warning('Pengarsipan dataset lama atau aktivasi status READY gagal; kompensasi membatalkan dataset baru ke FAILED', [
                                'user_id' => $user->id,
                                'dataset_id' => $dataset->id,
                                'error' => $e->getMessage(),
                            ]);

                            $this->rollbackAndCleanupKpiDataset(
                                $dataset,
                                'Gagal mengarsipkan dataset sebelumnya atau mengaktifkan status READY: '.$e->getMessage(),
                            );

                            $failed[] = $owner->name.': gagal mengarsipkan dataset lama — '.$e->getMessage();
                            $superseded = collect();
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
            } catch (Throwable $unexpected) {
                Log::error('Kegagalan tak terduga pada alur import laporan KPI', [
                    'user_id' => $user->id,
                    'error' => $unexpected->getMessage(),
                ]);

                if ($dataset instanceof Dataset) {
                    $this->rollbackAndCleanupKpiDataset(
                        $dataset,
                        'Terjadi kesalahan saat memproses laporan KPI: '.$unexpected->getMessage(),
                    );
                }

                $failed[] = $owner->name.': terjadi kesalahan sistem — '.$unexpected->getMessage();
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
        string $successStatus = Dataset::STATUS_READY,
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

        try {
            $dataset = Dataset::create([
                'division_id' => $divisionId,
                'user_id' => $user->id,
                'filename' => $storedName,
                'original_name' => $safeOriginalName ?: 'dataset.'.$safeExt,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'status' => Dataset::STATUS_UPLOADING,
            ]);
        } catch (Throwable $e) {
            Storage::disk('local')->delete('datasets/'.$storedName);
            throw $e;
        }

        // Metadata saja — JANGAN log isi baris (data kinerja sensitif).
        Log::info('Dataset import dimulai', [
            'dataset_id' => $dataset->id,
            'original_name' => $dataset->original_name,
            'size' => $dataset->size,
            'user_id' => $user->id,
        ]);

        try {
            $result = $importService->import($file, $user, $dataset, $recordAudit, $successStatus);
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
     * Rollback persistensi DB dan bersihkan file fisik storage saat impor KPI gagal.
     */
    private function rollbackAndCleanupKpiDataset(Dataset $dataset, string $errorMessage): void
    {
        try {
            Dataset::withoutEvents(function () use ($dataset, $errorMessage) {
                DB::transaction(function () use ($dataset, $errorMessage) {
                    $dataset->observations()->delete();
                    $dataset->metrics()->delete();
                    $dataset->update([
                        'status' => DatasetStatus::FAILED,
                        'error_message' => $errorMessage,
                    ]);
                });
            });
        } catch (Throwable $dbError) {
            Log::warning('Gagal rollback transaksi DB untuk dataset KPI gagal', [
                'dataset_id' => $dataset->id,
                'error' => $dbError->getMessage(),
            ]);
        }

        $this->cleanupFailedDataset($dataset);
    }

    /**
     * Hapus berkas fisik dataset dari storage agar tidak meninggalkan file yatim (orphan).
     */
    private function cleanupFailedDataset(Dataset $dataset): void
    {
        try {
            if ($dataset->filename) {
                Storage::disk('local')->delete('datasets/'.$dataset->filename);
            }
        } catch (Throwable $fileError) {
            Log::warning('Gagal menghapus file fisik dataset dari storage', [
                'dataset_id' => $dataset->id,
                'filename' => $dataset->filename,
                'error' => $fileError->getMessage(),
            ]);
        }
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
            ->withExists([
                'metrics as is_asset_dataset' => fn ($q) => $q->where('metric_name', 'like', 'ASET | %'),
            ])
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

        $isAssetDataset = $dataset->metrics()->where('metric_name', 'like', 'ASET | %')->exists();
        $assetAnalysis = $isAssetDataset ? $this->buildAssetAnalysis($dataset) : null;

        return view('datasets.show', compact('dataset', 'metrics', 'hasObservations', 'isAssetDataset', 'assetAnalysis'));
    }

    /**
     * Susun analisis inspeksi & rekonsiliasi data sync Aset (Tab 1a UIT vs Tab 1b 8 UPT).
     *
     * @return array<string, mixed>
     */
    private function buildAssetAnalysis(Dataset $dataset): array
    {
        $canonicalUpts = AssetStockReport::CANONICAL_UPTS;
        $metrics = $dataset->metrics()
            ->where('metric_name', 'like', 'ASET | %')
            ->orderBy('metric_name')
            ->get(['metric_name', 'value', 'period']);

        $uitRows = [];
        $uitByNormalized = [];
        $uptMatrixMap = [];
        $uptRowCount = 0;

        foreach ($metrics as $metric) {
            $name = (string) $metric->metric_name;
            $value = (float) $metric->value;
            $period = $metric->period instanceof \DateTimeInterface
                ? $metric->period->format('Y-m-d')
                : substr((string) $metric->period, 0, 10);

            if (str_starts_with($name, 'ASET | UIT | ')) {
                $parts = explode(' | ', $name, 3);
                $remainder = trim((string) ($parts[2] ?? ''));
                if ($remainder === '') {
                    continue;
                }

                $subParts = explode(' | ', $remainder, 2);
                $kelompok = trim($subParts[0]);
                $satuan = trim((string) ($subParts[1] ?? ''));
                $kategori = $this->classifyAssetCategory($remainder);

                $uitRows[] = [
                    'metric_name' => $name,
                    'kelompok' => $kelompok,
                    'satuan' => $satuan !== '' ? $satuan : '-',
                    'label' => $remainder,
                    'kategori' => $kategori,
                    'value' => round($value, 2),
                    'period' => $period,
                ];

                $normKey = $this->normalizeAssetAttributeKey($remainder);
                if (! isset($uitByNormalized[$normKey])) {
                    $uitByNormalized[$normKey] = [
                        'label' => $remainder,
                        'kategori' => $kategori,
                        'value' => 0.0,
                    ];
                }
                $uitByNormalized[$normKey]['value'] += $value;

                continue;
            }

            $parts = explode(' | ', $name, 4);
            $upt = trim((string) ($parts[1] ?? ''));
            $atribut = trim((string) ($parts[3] ?? ''));
            if ($upt === '' || $atribut === '') {
                continue;
            }

            $uptRowCount++;

            if (! isset($uptMatrixMap[$atribut])) {
                $uptValues = [];
                foreach ($canonicalUpts as $canonicalUpt) {
                    $uptValues[$canonicalUpt] = 0.0;
                }

                $uptMatrixMap[$atribut] = [
                    'atribut' => $atribut,
                    'kategori' => $this->classifyAssetCategory($atribut),
                    'upt_values' => $uptValues,
                    'upt_sum' => 0.0,
                ];
            }

            if (array_key_exists($upt, $uptMatrixMap[$atribut]['upt_values'])) {
                $uptMatrixMap[$atribut]['upt_values'][$upt] += $value;
                $uptMatrixMap[$atribut]['upt_sum'] += $value;
            }
        }

        $uptByNormalized = [];
        $hasTrafo150_20In1b = false;

        foreach ($uptMatrixMap as $atribut => $entry) {
            if (str_contains($atribut, '150/20')) {
                $hasTrafo150_20In1b = true;
            }

            foreach ($canonicalUpts as $canonicalUpt) {
                $uptMatrixMap[$atribut]['upt_values'][$canonicalUpt] = round($entry['upt_values'][$canonicalUpt], 2);
            }
            $uptMatrixMap[$atribut]['upt_sum'] = round($entry['upt_sum'], 2);

            $normKey = $this->normalizeAssetAttributeKey($atribut);
            if (! isset($uptByNormalized[$normKey])) {
                $uptByNormalized[$normKey] = [
                    'labels' => [],
                    'kategori' => $entry['kategori'],
                    'value' => 0.0,
                ];
            }
            $uptByNormalized[$normKey]['labels'][] = $atribut;
            $uptByNormalized[$normKey]['value'] += $entry['upt_sum'];
        }

        $uptMatrix = array_values($uptMatrixMap);
        usort($uptMatrix, static fn (array $a, array $b): int => [$a['kategori'], $a['atribut']] <=> [$b['kategori'], $b['atribut']]);

        $reconciliation = [];
        $towerUitTotal = 0.0;
        $towerUptTotal = 0.0;
        $hasTower = false;

        $transmisiUitTotal = 0.0;
        $transmisiUptTotal = 0.0;
        $hasTransmisi = false;

        $trafoMvaUitTotal = 0.0;
        $trafoMvaUptTotal = 0.0;
        $hasTrafoMva = false;

        foreach ($uitRows as $row) {
            $label = $row['label'];
            $lower = mb_strtolower($label);

            if (str_contains($lower, 'tower') && preg_match('/\bsu\b/i', $label)) {
                $towerUitTotal += $row['value'];
                $hasTower = true;
            } elseif (str_contains($lower, 'transmisi') && preg_match('/\bsu\b/i', $label) && (str_contains($lower, 'kms') || str_contains($lower, 'su'))) {
                $transmisiUitTotal += $row['value'];
                $hasTransmisi = true;
            } elseif (str_contains($lower, 'trafo') && str_contains($lower, 'mva')) {
                if (! str_contains($label, '150/20') || $hasTrafo150_20In1b) {
                    $trafoMvaUitTotal += $row['value'];
                    $hasTrafoMva = true;
                }
            }
        }

        foreach ($uptMatrix as $entry) {
            $atribut = $entry['atribut'];
            $lower = mb_strtolower($atribut);

            if (str_contains($lower, 'tower') && preg_match('/\bsu\b/i', $atribut)) {
                $towerUptTotal += $entry['upt_sum'];
                $hasTower = true;
            } elseif (str_contains($lower, 'transmisi') && preg_match('/\bsu\b/i', $atribut) && str_contains($lower, 'kms')) {
                $transmisiUptTotal += $entry['upt_sum'];
                $hasTransmisi = true;
            } elseif (str_contains($lower, 'trafo') && str_contains($lower, 'mva')) {
                $trafoMvaUptTotal += $entry['upt_sum'];
                $hasTrafoMva = true;
            }
        }

        $summaryTotalsSpec = [
            ['show' => $hasTower, 'label' => 'Total Tower SU', 'kategori' => 'Tower', 'uit' => $towerUitTotal, 'upt' => $towerUptTotal],
            ['show' => $hasTransmisi, 'label' => 'Total Transmisi SU kms', 'kategori' => 'Transmisi', 'uit' => $transmisiUitTotal, 'upt' => $transmisiUptTotal],
            ['show' => $hasTrafoMva, 'label' => 'Total Kapasitas Trafo MVA', 'kategori' => 'Trafo', 'uit' => $trafoMvaUitTotal, 'upt' => $trafoMvaUptTotal],
        ];

        foreach ($summaryTotalsSpec as $spec) {
            if (! $spec['show']) {
                continue;
            }
            $uitVal = round($spec['uit'], 2);
            $uptVal = round($spec['upt'], 2);
            $delta = round($uitVal - $uptVal, 2);
            $reconciliation[] = [
                'label' => $spec['label'],
                'uit_label' => $spec['label'].' (Tab 1a)',
                'upt_label' => $spec['label'].' (Akumulasi 8 UPT)',
                'kategori' => $spec['kategori'],
                'is_summary' => true,
                'uit_value' => $uitVal,
                'upt_sum' => $uptVal,
                'delta' => $delta,
                'is_matched' => abs($delta) < 0.01,
            ];
        }

        foreach ($uitByNormalized as $normKey => $uitItem) {
            if (! isset($uptByNormalized[$normKey])) {
                continue;
            }

            $uptItem = $uptByNormalized[$normKey];
            $uitVal = round($uitItem['value'], 2);
            $uptVal = round($uptItem['value'], 2);
            $delta = round($uitVal - $uptVal, 2);

            $reconciliation[] = [
                'label' => $uitItem['label'],
                'uit_label' => $uitItem['label'],
                'upt_label' => implode(', ', $uptItem['labels']),
                'kategori' => $uitItem['kategori'],
                'is_summary' => false,
                'uit_value' => $uitVal,
                'upt_sum' => $uptVal,
                'delta' => $delta,
                'is_matched' => abs($delta) < 0.01,
            ];
        }

        $matchedCount = count(array_filter($reconciliation, static fn (array $r): bool => $r['is_matched']));
        $reconciledCount = count($reconciliation);

        return [
            'canonical_upts' => $canonicalUpts,
            'uit_rows' => $uitRows,
            'upt_matrix' => $uptMatrix,
            'reconciliation' => $reconciliation,
            'summary' => [
                'uit_row_count' => count($uitRows),
                'upt_row_count' => $uptRowCount,
                'upt_attribute_count' => count($uptMatrix),
                'total_sync_rows' => count($uitRows) + $uptRowCount,
                'reconciled_count' => $reconciledCount,
                'matched_count' => $matchedCount,
                'mismatched_count' => $reconciledCount - $matchedCount,
                'tower_uit' => round($towerUitTotal, 2),
                'tower_upt' => round($towerUptTotal, 2),
                'tower_matched' => abs($towerUitTotal - $towerUptTotal) < 0.01,
                'transmisi_uit' => round($transmisiUitTotal, 2),
                'transmisi_upt' => round($transmisiUptTotal, 2),
                'transmisi_matched' => abs($transmisiUitTotal - $transmisiUptTotal) < 0.01,
                'trafo_mva_uit' => round($trafoMvaUitTotal, 2),
                'trafo_mva_upt' => round($trafoMvaUptTotal, 2),
                'trafo_mva_matched' => abs($trafoMvaUitTotal - $trafoMvaUptTotal) < 0.01,
            ],
        ];
    }

    /**
     * Klasifikasikan atribut aset ke 5 kategori standar.
     */
    private function classifyAssetCategory(string $name): string
    {
        $lower = mb_strtolower($name);

        if (str_contains($lower, 'tower')) {
            return 'Tower';
        }
        if (str_contains($lower, 'transmisi')) {
            return 'Transmisi';
        }
        if (str_contains($lower, 'trafo')) {
            return 'Trafo';
        }
        if (str_contains($lower, 'bay') || str_contains($lower, 'gardu') || str_contains($lower, 'gi/gitet') || preg_match('/\b(gi|gitet)\b/', $lower)) {
            return 'Gardu Induk & Bay';
        }

        return 'Peralatan Lain';
    }

    /**
     * Normalisasikan nama atribut aset untuk pencocokan Tab 1a (UIT) vs Tab 1b (8 UPT).
     */
    private function normalizeAssetAttributeKey(string $attribute): string
    {
        $cleaned = trim($attribute);
        $cleaned = (string) preg_replace('/^Trafo\s+Tenaga\s+[^|]+\|\s*/i', '', $cleaned);

        $rawSegments = array_values(array_filter(
            array_map(static fn (string $part): string => trim((string) preg_replace('/\s+/', ' ', $part)), explode('|', $cleaned)),
            static fn (string $part): bool => $part !== '',
        ));

        $expandedSegments = [];
        foreach ($rawSegments as $segment) {
            if (preg_match('/^(.+?)\s*\(([^)]+)\)$/', $segment, $matches)) {
                $expandedSegments[] = trim($matches[1]);
                $expandedSegments[] = trim($matches[2]);
            } else {
                $expandedSegments[] = $segment;
            }
        }

        $deduped = [];
        foreach ($expandedSegments as $segment) {
            $lower = mb_strtolower($segment);
            if ($deduped === [] || end($deduped) !== $lower) {
                $deduped[] = $lower;
            }
        }

        if (isset($deduped[0]) && str_starts_with($deduped[0], 'tower ') && count($deduped) >= 3 && end($deduped) === 'unit') {
            array_pop($deduped);
        }

        return implode(' | ', $deduped);
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
