<?php

namespace App\Models;

use App\Enums\DatasetStatus;
use App\Models\Concerns\Auditable;
use App\Services\AssetSheetsSyncService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model Dataset - satu file unggahan (Excel/CSV) berisi data kinerja.
 *
 * Lifecycle status (lihat App\Enums\DatasetStatus):
 * - UPLOADING  : file baru diterima, belum diproses.
 * - VALIDATING : baris sedang divalidasi & diimpor.
 * - READY      : valid, masuk agregasi chart.
 * - FAILED     : impor gagal; error_message menjelaskan penyebabnya.
 * - ARCHIVED   : diarsipkan staf pemilik; TIDAK masuk agregasi chart,
 *                data tetap di DB (reversible, tanpa jejak hilang).
 */
#[Fillable(['division_id', 'user_id', 'filename', 'original_name', 'mime_type', 'size', 'status', 'error_message', 'validation_report'])]
class Dataset extends Model
{
    use Auditable, HasFactory;

    // Backward-compatible constants — delegate ke enum agar kode yang
    // masih pakai Dataset::STATUS_* tidak perlu diubah sekaligus.
    public const STATUS_UPLOADING = DatasetStatus::UPLOADING->value;

    public const STATUS_VALIDATING = DatasetStatus::VALIDATING->value;

    public const STATUS_READY = DatasetStatus::READY->value;

    public const STATUS_FAILED = DatasetStatus::FAILED->value;

    public const STATUS_ARCHIVED = DatasetStatus::ARCHIVED->value;

    protected $casts = [
        'size' => 'integer',
        'validation_report' => 'array',
        'status' => DatasetStatus::class,
    ];

    /**
     * Scope: dataset yang dihitung dalam agregasi chart (bukan archived).
     */
    public function scopeChartable($query)
    {
        return $query->where('status', DatasetStatus::READY);
    }

    /**
     * @return BelongsTo<Division, $this>
     */
    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<PerformanceMetric, $this>
     */
    public function metrics(): HasMany
    {
        return $this->hasMany(PerformanceMetric::class);
    }

    /**
     * Observation KPI terstruktur dari dataset ini (model baru, hidup
     * berdampingan dengan metrics() selama transisi read-model).
     *
     * @return HasMany<PerformanceObservation, $this>
     */
    public function observations(): HasMany
    {
        return $this->hasMany(PerformanceObservation::class);
    }

    /**
     * Nama tampilan dataset yang bersih dari label teknis historis tanpa mengubah nilai DB.
     */
    public function displayOriginalName(): string
    {
        $trimmed = trim((string) $this->original_name);

        if ($trimmed === '') {
            return 'Tidak tercatat';
        }

        if (in_array($trimmed, AssetSheetsSyncService::LEGACY_DISPLAY_NAMES, true)) {
            return AssetSheetsSyncService::fallbackDatasetName();
        }

        return $trimmed;
    }

    /**
     * Petakan MIME type penyimpanan menjadi label sumber atau format yang mudah dipahami pengguna.
     */
    public function sourceTypeLabel(bool $isAssetDataset = false): string
    {
        $mime = strtolower(trim((string) $this->mime_type));
        $name = strtolower(trim((string) $this->original_name));
        $filename = strtolower(trim((string) $this->filename));
        $ext = strtolower((string) pathinfo($filename !== '' ? $filename : $name, PATHINFO_EXTENSION));
        $isAssetScope = $isAssetDataset || in_array($this->division?->slug, ['managemen-aset', 'data-aset'], true);

        if (str_contains($name, 'sinkronisasi google sheets') || str_starts_with($filename, 'sync_pending_')) {
            return 'Sinkronisasi Google Sheets';
        }

        if ($mime === 'application/octet-stream') {
            if ($isAssetScope) {
                return 'Sinkronisasi Google Sheets';
            }

            return match ($ext) {
                'xlsx' => 'Excel (.xlsx)',
                'xls' => 'Excel (.xls)',
                'csv' => 'CSV (.csv)',
                default => 'Berkas Data',
            };
        }

        if (in_array($mime, ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'], true)) {
            return 'Excel (.xlsx)';
        }

        if ($mime === 'application/vnd.ms-excel') {
            return 'Excel (.xls)';
        }

        if (in_array($mime, ['text/csv', 'application/csv', 'text/plain'], true)) {
            return 'CSV (.csv)';
        }

        return match ($ext) {
            'xlsx' => 'Excel (.xlsx)',
            'xls' => 'Excel (.xls)',
            'csv' => 'CSV (.csv)',
            default => 'Tidak tercatat',
        };
    }
}
