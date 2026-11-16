<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\PerformanceMetric;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Service untuk memproses dual-file web upload aset (Tab 1b UPT dan Tab 1a UIT).
 *
 * Menggabungkan metrik tingkat UPT dan tingkat UIT JBT ke dalam SATU dataset
 * milik divisi Manajemen Aset secara atomis di dalam DB::transaction.
 */
class AssetDualUploadService
{
    public function __construct(
        private readonly AssetStockConverter $converter1b,
        private readonly AssetUitReader $reader1a,
    ) {}

    /**
     * Upload dan proses dua berkas aset (1b UPT dan 1a UIT) secara atomis.
     *
     * @param  UploadedFile|string  $file1b  Berkas Tab 1b (level UPT)
     * @param  UploadedFile|string  $file1a  Berkas Tab 1a (level UIT)
     * @param  User  $uploader  Pengguna yang melakukan unggah
     * @param  string|null  $period  Periode data Y-m-d (opsional, jika null dideteksi otomatis)
     * @param  Dataset|null  $existingDataset  Record placeholder yang diperbarui alih-alih membuat baru (opsional)
     * @param  string|null  $displayName  Nama tampilan dataset ramah klien (opsional)
     * @return array{dataset: Dataset, rows_1b: int, rows_1a: int, total_rows: int}
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @throws Throwable
     */
    public function upload(
        UploadedFile|string $file1b,
        UploadedFile|string $file1a,
        User $uploader,
        ?string $period = null,
        ?Dataset $existingDataset = null,
        ?string $displayName = null,
    ): array {
        // Dynamic division resolution via slug (dilarang hardcode ID)
        $assetDivision = Division::where('slug', 'managemen-aset')->firstOrFail();
        $assetDivisionId = $assetDivision->id;

        // Otorisasi fail-closed: hanya Admin atau Staf Divisi managemen-aset
        $canUpload = $uploader->hasRole(Role::ADMIN)
            || ($uploader->hasRole(Role::STAFF_DIVISI) && $uploader->division_id !== null && (int) $uploader->division_id === (int) $assetDivisionId);

        abort_unless($canUpload, 403, 'Hanya admin atau staf divisi Manajemen Aset yang dapat mengunggah dataset Manajemen Aset.');

        $path1b = $file1b instanceof UploadedFile ? $file1b->getRealPath() : $file1b;
        $path1a = $file1a instanceof UploadedFile ? $file1a->getRealPath() : $file1a;

        if (! file_exists($path1b)) {
            throw new InvalidArgumentException("Berkas Tab 1b tidak ditemukan: {$path1b}");
        }
        if (! file_exists($path1a)) {
            throw new InvalidArgumentException("Berkas Tab 1a tidak ditemukan: {$path1a}");
        }

        $allowedExts = config('dataset_import.extensions', ['xlsx', 'csv']);

        $ext1b = $file1b instanceof UploadedFile
            ? strtolower($file1b->getClientOriginalExtension())
            : strtolower(pathinfo($path1b, PATHINFO_EXTENSION));
        $safeExt1b = in_array($ext1b, $allowedExts, true) ? $ext1b : 'xlsx';

        $ext1a = $file1a instanceof UploadedFile
            ? strtolower($file1a->getClientOriginalExtension())
            : strtolower(pathinfo($path1a, PATHINFO_EXTENSION));
        $safeExt1a = in_array($ext1a, $allowedExts, true) ? $ext1a : 'xlsx';

        $storedName1b = Str::random(32).'.'.$safeExt1b;
        $storedName1a = Str::random(32).'.'.$safeExt1a;

        $safeOriginalName1b = $file1b instanceof UploadedFile
            ? mb_substr(preg_replace('/[^\w\s\-_.()\p{L}]/u', '', $file1b->getClientOriginalName()), 0, 120)
            : basename($path1b);
        $safeOriginalName1a = $file1a instanceof UploadedFile
            ? mb_substr(preg_replace('/[^\w\s\-_.()\p{L}]/u', '', $file1a->getClientOriginalName()), 0, 120)
            : basename($path1a);

        $compositeOriginalName = $safeOriginalName1b.' + '.$safeOriginalName1a;
        $resolvedOriginalName = $displayName ?? $compositeOriginalName;
        $mimeType = $file1b instanceof UploadedFile ? ($file1b->getMimeType() ?: 'application/octet-stream') : 'application/octet-stream';
        $fileSize = ($file1b instanceof UploadedFile ? $file1b->getSize() : filesize($path1b))
            + ($file1a instanceof UploadedFile ? $file1a->getSize() : filesize($path1a));

        $storedFiles = [];

        try {
            return DB::transaction(function () use (
                $file1b, $file1a, $path1b, $path1a, $uploader, $assetDivisionId, $period,
                $storedName1b, $storedName1a, $resolvedOriginalName, $mimeType, $fileSize, $existingDataset, &$storedFiles
            ) {
                // Simpan berkas fisik ke disk local
                $storedPath1b = 'datasets/'.$storedName1b;
                if ($file1b instanceof UploadedFile) {
                    Storage::disk('local')->putFileAs('datasets', $file1b, $storedName1b);
                } else {
                    Storage::disk('local')->put($storedPath1b, (string) file_get_contents($path1b));
                }
                $storedFiles[] = $storedPath1b;

                $storedPath1a = 'datasets/'.$storedName1a;
                if ($file1a instanceof UploadedFile) {
                    Storage::disk('local')->putFileAs('datasets', $file1a, $storedName1a);
                } else {
                    Storage::disk('local')->put($storedPath1a, (string) file_get_contents($path1a));
                }
                $storedFiles[] = $storedPath1a;

                $datasetAttributes = [
                    'division_id' => $assetDivisionId,
                    'user_id' => $uploader->id,
                    'filename' => $storedName1b,
                    'original_name' => $resolvedOriginalName,
                    'mime_type' => $mimeType,
                    'size' => $fileSize,
                    'status' => Dataset::STATUS_VALIDATING,
                    'error_message' => null,
                ];

                if ($existingDataset !== null) {
                    $existingDataset->update($datasetAttributes);
                    $dataset = $existingDataset;
                } else {
                    $dataset = Dataset::create($datasetAttributes);
                }

                // Ekstraksi 1a (UIT) terlebih dahulu untuk mendeteksi atau memvalidasi periode
                $rows1a = $this->reader1a->read($path1a, $period);
                if (empty($rows1a)) {
                    throw new RuntimeException('Tidak ada baris data valid yang dapat dibaca dari Tab 1a.');
                }

                $resolvedPeriod = $period ?? $rows1a[0]['period'] ?? $rows1a[0][2];

                // Ekstraksi 1b (UPT) menggunakan periode yang telah teresolusi
                $rows1b = $this->converter1b->extract($path1b, $resolvedPeriod);
                if (empty($rows1b)) {
                    throw new RuntimeException('Tidak ada baris data valid yang dapat dibaca dari Tab 1b.');
                }

                // Susun array metric inserts untuk digabung ke dalam satu dataset
                $inserts = [];
                $seenKeys = [];

                // Masukkan metrik tingkat UPT dari Tab 1b
                foreach ($rows1b as $row) {
                    $metricName = trim((string) $row[0]);
                    $val = (float) $row[1];
                    $p = trim((string) $row[2]);

                    $key = mb_strtolower($metricName).'|'.$p;
                    if (isset($seenKeys[$key])) {
                        continue;
                    }
                    $seenKeys[$key] = true;

                    $inserts[] = [
                        'metric_name' => $metricName,
                        'value' => $val,
                        'period' => $p,
                        'unit_id' => $uploader->id,
                        'division_id' => $assetDivisionId,
                        'dataset_id' => $dataset->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                // Masukkan metrik tingkat UIT dari Tab 1a
                foreach ($rows1a as $row) {
                    $metricName = trim((string) ($row['metric_name'] ?? $row[0]));
                    $val = (float) ($row['value'] ?? $row[1]);
                    $p = trim((string) ($row['period'] ?? $row[2]));

                    $key = mb_strtolower($metricName).'|'.$p;
                    if (isset($seenKeys[$key])) {
                        continue;
                    }
                    $seenKeys[$key] = true;

                    $inserts[] = [
                        'metric_name' => $metricName,
                        'value' => $val,
                        'period' => $p,
                        'unit_id' => $uploader->id,
                        'division_id' => $assetDivisionId,
                        'dataset_id' => $dataset->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if ($inserts !== []) {
                    foreach (array_chunk($inserts, 500) as $chunk) {
                        PerformanceMetric::insert($chunk);
                    }
                }

                $effectivePeriods = array_values(array_unique(array_filter(
                    array_column($inserts, 'period'),
                    static fn (mixed $value): bool => is_string($value) && $value !== '',
                )));
                if ($effectivePeriods === [] && is_string($resolvedPeriod) && $resolvedPeriod !== '') {
                    $effectivePeriods = [$resolvedPeriod];
                }

                // Arsipkan dataset READY lama pada divisi managemen-aset dan periode
                // yang sama secara atomis di dalam transaksi agar tidak menumpuk.
                $superseded = $effectivePeriods === []
                    ? collect()
                    : Dataset::query()
                        ->where('division_id', $assetDivisionId)
                        ->where('status', Dataset::STATUS_READY)
                        ->whereKeyNot($dataset->id)
                        ->whereHas('metrics', fn ($q) => $q->whereIn('period', $effectivePeriods))
                        ->lockForUpdate()
                        ->orderBy('id')
                        ->get();

                foreach ($superseded as $previous) {
                    $previous->update(['status' => Dataset::STATUS_ARCHIVED]);
                }

                // Update status Dataset ke READY
                $dataset->update([
                    'status' => Dataset::STATUS_READY,
                    'error_message' => null,
                ]);

                // Catat audit log untuk impor aset
                AuditLog::record(
                    AuditLog::ACTION_IMPORT,
                    $dataset,
                    null,
                    [
                        'rows_imported' => count($inserts),
                        'rows_1b' => count($rows1b),
                        'rows_1a' => count($rows1a),
                        'period' => $resolvedPeriod,
                        'archived_count' => $superseded->count(),
                    ],
                    $uploader->id,
                );

                Log::info('Dual-file upload aset berhasil diproses', [
                    'dataset_id' => $dataset->id,
                    'user_id' => $uploader->id,
                    'total_rows' => count($inserts),
                    'period' => $resolvedPeriod,
                    'archived_count' => $superseded->count(),
                ]);

                return [
                    'dataset' => $dataset->fresh(),
                    'rows_1b' => count($rows1b),
                    'rows_1a' => count($rows1a),
                    'total_rows' => count($inserts),
                ];
            });
        } catch (Throwable $e) {
            // Hapus file fisik dari storage jika terjadi kegagalan (cegah orphan file)
            foreach ($storedFiles as $filePath) {
                if (Storage::disk('local')->exists($filePath)) {
                    Storage::disk('local')->delete($filePath);
                }
            }

            Log::warning('Dual-file upload aset gagal dan di-rollback penuh', [
                'user_id' => $uploader->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
