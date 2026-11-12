<?php

namespace App\Console\Commands;

use App\Exceptions\AssetSyncException;
use App\Models\User;
use App\Services\AssetSheetsSyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

#[Signature('aset:sync {--dry-run : Validate and parse without saving dataset} {--period= : Explicit period Y-m-d} {--user= : User ID or email for import attribution}')]
#[Description('Sync asset metrics from Google Sheets into Manajemen Aset dataset')]
class SyncAssetDataCommand extends Command
{
    /**
     * Eksekusi perintah sinkronisasi data aset dari Google Sheets.
     */
    public function handle(AssetSheetsSyncService $syncService): int
    {
        $this->components->info('Memulai sinkronisasi data aset dari Google Sheets.');

        $dryRun = (bool) $this->option('dry-run');
        $period = $this->option('period') ? (string) $this->option('period') : null;
        $userOption = $this->option('user') ? (string) $this->option('user') : null;

        if ($period !== null && ! $this->isValidPeriod($period)) {
            $this->components->error('Opsi --period harus berformat Y-m-d, contoh: --period=2026-10-31');

            return self::FAILURE;
        }

        $user = null;
        if ($userOption !== null) {
            $user = is_numeric($userOption)
                ? User::find((int) $userOption)
                : User::where('email', $userOption)->first();

            if (! $user) {
                $this->components->error("Pengguna tidak ditemukan untuk opsi --user: {$userOption}");

                return self::FAILURE;
            }
        }

        try {
            $result = $syncService->sync($dryRun, $period, $user);

            if ($result['status'] === 'DRY_RUN') {
                $this->components->info("DRY-RUN selesai: Periode {$result['period']}, Tab 1a: {$result['rows_1a']} baris, Tab 1b: {$result['rows_1b']} baris, total {$result['total_rows']} metrik valid.");
                $this->components->info('Tidak ada dataset yang disimpan ke database (mode dry-run).');
            } else {
                $datasetId = $result['dataset']?->id;
                $this->components->info("Sinkronisasi berhasil: Dataset [ID: {$datasetId}] untuk periode {$result['period']} tersimpan (Tab 1a: {$result['rows_1a']} baris, Tab 1b: {$result['rows_1b']} baris, total {$result['total_rows']} metrik).");
            }

            return self::SUCCESS;
        } catch (AssetSyncException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $sanitized = AssetSyncException::sanitize($e->getMessage());
            $this->components->error("Terjadi kesalahan saat sinkronisasi data aset: {$sanitized}");

            return self::FAILURE;
        }
    }

    /**
     * Validasi format tanggal Y-m-d.
     */
    private function isValidPeriod(string $period): bool
    {
        try {
            $d = Carbon::createFromFormat('Y-m-d', $period);

            return $d && $d->format('Y-m-d') === $period;
        } catch (Throwable) {
            return false;
        }
    }
}
