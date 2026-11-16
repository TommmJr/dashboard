<?php

namespace App\Jobs;

use App\Enums\DatasetStatus;
use App\Exceptions\AssetSyncException;
use App\Models\Dataset;
use App\Models\User;
use App\Services\AssetSheetsSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Menjalankan sinkronisasi data aset dari Google Sheets secara asinkron.
 */
class SyncAssetSheetsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly int $datasetId,
        public readonly int $userId,
    ) {}

    /**
     * Eksekusi sinkronisasi aset pada worker queue.
     */
    public function handle(AssetSheetsSyncService $syncService): void
    {
        $dataset = Dataset::find($this->datasetId);
        $user = User::find($this->userId);

        if (! $dataset || ! $user) {
            Log::warning('SyncAssetSheetsJob dibatalkan karena dataset atau pengguna tidak ditemukan', [
                'dataset_id' => $this->datasetId,
                'user_id' => $this->userId,
            ]);

            return;
        }

        try {
            $syncService->sync(
                dryRun: false,
                period: null,
                user: $user,
                targetDataset: $dataset,
            );

            $fresh = $dataset->fresh();
            if ($fresh !== null && $fresh->status !== DatasetStatus::READY) {
                $fresh->update([
                    'status' => DatasetStatus::READY,
                    'error_message' => null,
                ]);
            }
        } catch (Throwable $e) {
            $this->markDatasetFailed($dataset, $e);

            throw $e;
        }
    }

    /**
     * Tangani kegagalan terminal job (mis. timeout atau crash worker) agar placeholder tidak menggantung.
     */
    public function failed(?Throwable $exception): void
    {
        $dataset = Dataset::find($this->datasetId);
        if (! $dataset) {
            return;
        }

        $this->markDatasetFailed($dataset, $exception);
    }

    private function markDatasetFailed(Dataset $dataset, ?Throwable $exception): void
    {
        $fresh = $dataset->fresh();
        if (! $fresh || $fresh->status === DatasetStatus::FAILED) {
            return;
        }

        $rawMessage = $exception instanceof AssetSyncException
            ? $exception->getMessage()
            : 'Terjadi kesalahan sistem saat sinkronisasi data aset. Hubungi administrator.';

        $fresh->update([
            'status' => DatasetStatus::FAILED,
            'error_message' => AssetSyncException::sanitize($rawMessage),
        ]);
    }
}
