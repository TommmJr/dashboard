<?php

namespace App\Services;

use App\Enums\DatasetStatus;
use App\Models\Dataset;
use App\Models\Division;
use App\Support\DatasetFreshness;
use Carbon\CarbonInterface;
use FilesystemIterator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Penyusun ringkasan kesehatan operasional sistem khusus Admin.
 *
 * Seluruh metrik bersifat read-only dan fail-safe: kegagalan koneksi
 * atau pembacaan berkas tidak pernah membocorkan pesan galat mentah,
 * path server, atau detail kredensial ke antarmuka.
 */
class SystemHealthService
{
    /** @var array<int, string> */
    private const ASSET_DIVISION_SLUGS = ['managemen-aset', 'data-aset'];

    /** @var array<int, string> */
    private const MONTHS_SHORT = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
        5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu',
        9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    /**
     * Ambil snapshot status kesehatan sistem saat ini.
     *
     * @return array{
     *     checked_at_label: string,
     *     database: array{
     *         connected: bool,
     *         status_label: string,
     *         tone: string,
     *         driver_label: string,
     *         latency_ms: ?int,
     *         detail_label: string
     *     },
     *     queue: array{
     *         available: bool,
     *         pending_count: int,
     *         failed_count: int,
     *         status_label: string,
     *         tone: string,
     *         summary_label: string
     *     },
     *     asset_sync: array{
     *         has_record: bool,
     *         status_label: string,
     *         tone: string,
     *         last_run_label: string,
     *         detail_label: string
     *     },
     *     storage: array{
     *         available: bool,
     *         file_count: int,
     *         total_bytes: int,
     *         total_size_label: string,
     *         status_label: string,
     *         tone: string,
     *         file_count_label: string,
     *         detail_label: string
     *     }
     * }
     */
    public function snapshot(): array
    {
        return [
            'checked_at_label' => $this->formatWib(Carbon::now(DatasetFreshness::TIMEZONE)),
            'database' => $this->databaseHealth(),
            'queue' => $this->queueHealth(),
            'asset_sync' => $this->assetSyncHealth(),
            'storage' => $this->storageHealth(),
        ];
    }

    /**
     * @return array{
     *     connected: bool,
     *     status_label: string,
     *     tone: string,
     *     driver_label: string,
     *     latency_ms: ?int,
     *     detail_label: string
     * }
     */
    private function databaseHealth(): array
    {
        $startedAt = microtime(true);

        try {
            $connection = DB::connection();
            $connection->getPdo();
            $latencyMs = max(1, (int) round((microtime(true) - $startedAt) * 1000));
            $driverName = method_exists($connection, 'getDriverName') ? (string) $connection->getDriverName() : '';
            $driverLabel = match (strtolower($driverName)) {
                'mysql', 'mariadb' => 'MySQL',
                'sqlite' => 'SQLite',
                'pgsql' => 'PostgreSQL',
                default => 'Basis Data Utama',
            };

            return [
                'connected' => true,
                'status_label' => 'Terhubung',
                'tone' => 'healthy',
                'driver_label' => $driverLabel,
                'latency_ms' => $latencyMs,
                'detail_label' => "{$driverLabel} ({$latencyMs} md)",
            ];
        } catch (Throwable $e) {
            Log::warning('Pemeriksaan kesehatan koneksi basis data gagal', [
                'exception' => $e::class,
            ]);

            return [
                'connected' => false,
                'status_label' => 'Terganggu',
                'tone' => 'danger',
                'driver_label' => 'Basis Data',
                'latency_ms' => null,
                'detail_label' => 'Koneksi tidak tersedia',
            ];
        }
    }

    /**
     * @return array{
     *     available: bool,
     *     pending_count: int,
     *     failed_count: int,
     *     status_label: string,
     *     tone: string,
     *     summary_label: string
     * }
     */
    private function queueHealth(): array
    {
        try {
            $pendingCount = (int) DB::table('jobs')->count();
            $failedCount = (int) DB::table('failed_jobs')->count();

            if ($failedCount > 0) {
                $statusLabel = 'Perlu Perhatian';
                $tone = 'danger';
            } elseif ($pendingCount > 0) {
                $statusLabel = 'Sedang Berjalan';
                $tone = 'info';
            } else {
                $statusLabel = 'Normal';
                $tone = 'healthy';
            }

            return [
                'available' => true,
                'pending_count' => $pendingCount,
                'failed_count' => $failedCount,
                'status_label' => $statusLabel,
                'tone' => $tone,
                'summary_label' => number_format($pendingCount, 0, ',', '.').' antrean tertunda, '
                    .number_format($failedCount, 0, ',', '.').' tugas gagal',
            ];
        } catch (Throwable $e) {
            Log::warning('Pemeriksaan kesehatan antrean latar belakang gagal', [
                'exception' => $e::class,
            ]);

            return [
                'available' => false,
                'pending_count' => 0,
                'failed_count' => 0,
                'status_label' => 'Tidak Tersedia',
                'tone' => 'danger',
                'summary_label' => 'Status antrean tidak dapat dibaca',
            ];
        }
    }

    /**
     * @return array{
     *     has_record: bool,
     *     status_label: string,
     *     tone: string,
     *     last_run_label: string,
     *     detail_label: string
     * }
     */
    private function assetSyncHealth(): array
    {
        try {
            $divisions = Division::query()
                ->whereIn('slug', self::ASSET_DIVISION_SLUGS)
                ->pluck('id', 'slug');

            $targetDivisionId = $divisions['managemen-aset'] ?? $divisions['data-aset'] ?? null;
            if ($targetDivisionId === null) {
                return [
                    'has_record' => false,
                    'status_label' => 'Belum Ada Riwayat',
                    'tone' => 'neutral',
                    'last_run_label' => 'Belum pernah dijalankan',
                    'detail_label' => 'Divisi aset belum terdaftar',
                ];
            }

            $syncNames = [
                AssetSheetsSyncService::DISPLAY_NAME,
                'Google Sheets Sync (Failed)',
            ];

            $latestSync = Dataset::query()
                ->where('division_id', (int) $targetDivisionId)
                ->whereIn('original_name', $syncNames)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first(['id', 'status', 'original_name', 'created_at']);

            if ($latestSync === null) {
                $latestSync = Dataset::query()
                    ->where('division_id', (int) $targetDivisionId)
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->first(['id', 'status', 'original_name', 'created_at']);
            }

            if ($latestSync === null) {
                return [
                    'has_record' => false,
                    'status_label' => 'Belum Ada Riwayat',
                    'tone' => 'neutral',
                    'last_run_label' => 'Belum pernah dijalankan',
                    'detail_label' => 'Menunggu sinkronisasi pertama',
                ];
            }

            $statusEnum = $latestSync->status instanceof DatasetStatus
                ? $latestSync->status
                : DatasetStatus::tryFrom((string) $latestSync->status);

            $statusLabel = $statusEnum?->label() ?? 'Tidak Diketahui';
            $tone = match ($statusEnum) {
                DatasetStatus::READY => 'healthy',
                DatasetStatus::FAILED => 'danger',
                DatasetStatus::UPLOADING, DatasetStatus::VALIDATING => 'info',
                default => 'neutral',
            };

            $lastRunLabel = $latestSync->created_at !== null
                ? $this->formatWib($latestSync->created_at)
                : 'Waktu belum tercatat';

            $detailLabel = $latestSync->original_name === 'Google Sheets Sync (Failed)'
                ? 'Sinkronisasi Google Sheets'
                : (string) $latestSync->original_name;

            return [
                'has_record' => true,
                'status_label' => $statusLabel,
                'tone' => $tone,
                'last_run_label' => $lastRunLabel,
                'detail_label' => $detailLabel,
            ];
        } catch (Throwable $e) {
            Log::warning('Pemeriksaan riwayat sinkronisasi aset gagal', [
                'exception' => $e::class,
            ]);

            return [
                'has_record' => false,
                'status_label' => 'Tidak Tersedia',
                'tone' => 'danger',
                'last_run_label' => 'Tidak dapat dibaca',
                'detail_label' => 'Riwayat sinkronisasi belum tersedia',
            ];
        }
    }

    /**
     * @return array{
     *     available: bool,
     *     file_count: int,
     *     total_bytes: int,
     *     total_size_label: string,
     *     status_label: string,
     *     tone: string,
     *     file_count_label: string,
     *     detail_label: string
     * }
     */
    private function storageHealth(): array
    {
        try {
            $compute = function (): array {
                $disk = Storage::disk('local');

                if (! $disk->exists('datasets')) {
                    return [
                        'available' => true,
                        'file_count' => 0,
                        'total_bytes' => 0,
                        'total_size_label' => '0 B',
                        'status_label' => 'Kosong',
                        'tone' => 'neutral',
                        'file_count_label' => '0 berkas',
                        'detail_label' => 'Direktori penyimpanan belum berisi berkas',
                    ];
                }

                $fileCount = 0;
                $totalBytes = 0;
                $directoryPath = $disk->path('datasets');

                if (is_dir($directoryPath)) {
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($directoryPath, FilesystemIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::LEAVES_ONLY
                    );

                    /** @var SplFileInfo $fileInfo */
                    foreach ($iterator as $fileInfo) {
                        if ($fileInfo->isFile()) {
                            $fileCount++;
                            $totalBytes += (int) $fileInfo->getSize();
                        }
                    }
                } else {
                    foreach ($disk->allFiles('datasets') as $file) {
                        $fileCount++;
                        $totalBytes += (int) $disk->size($file);
                    }
                }

                $fmtCount = number_format($fileCount, 0, ',', '.');
                $sizeLabel = $this->formatBytes($totalBytes);

                return [
                    'available' => true,
                    'file_count' => $fileCount,
                    'total_bytes' => $totalBytes,
                    'total_size_label' => $sizeLabel,
                    'status_label' => $fileCount > 0 ? 'Tersedia' : 'Kosong',
                    'tone' => $fileCount > 0 ? 'healthy' : 'neutral',
                    'file_count_label' => $fmtCount.' berkas',
                    'detail_label' => "{$fmtCount} berkas tersimpan ({$sizeLabel})",
                ];
            };

            if (app()->runningUnitTests()) {
                return $compute();
            }

            /** @var array{available: bool, file_count: int, total_bytes: int, total_size_label: string, status_label: string, tone: string, file_count_label: string, detail_label: string} $cached */
            $cached = Cache::remember('system_health_storage_datasets_v1', 60, $compute);

            return $cached;
        } catch (Throwable $e) {
            Log::warning('Pemeriksaan penyimpanan dataset privat gagal', [
                'exception' => $e::class,
            ]);

            return [
                'available' => false,
                'file_count' => 0,
                'total_bytes' => 0,
                'total_size_label' => '0 B',
                'status_label' => 'Tidak Tersedia',
                'tone' => 'danger',
                'file_count_label' => '0 berkas',
                'detail_label' => 'Penyimpanan lokal tidak dapat dibaca',
            ];
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) max(0, $bytes);
        $index = 0;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return number_format($value, $index === 0 ? 0 : 1, ',', '.').' '.$units[$index];
    }

    private function formatWib(CarbonInterface $timestamp): string
    {
        $wib = $timestamp->copy()->timezone(DatasetFreshness::TIMEZONE);
        $month = self::MONTHS_SHORT[(int) $wib->format('n')] ?? $wib->format('M');

        return $wib->format('d').' '.$month.' '.$wib->format('Y, H.i').' WIB';
    }
}
