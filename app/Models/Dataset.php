<?php

namespace App\Models;

use App\Enums\DatasetStatus;
use App\Models\Concerns\Auditable;
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

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(PerformanceMetric::class);
    }

    /**
     * Observation KPI terstruktur dari dataset ini (model baru, hidup
     * berdampingan dengan metrics() selama transisi read-model).
     */
    public function observations(): HasMany
    {
        return $this->hasMany(PerformanceObservation::class);
    }
}
