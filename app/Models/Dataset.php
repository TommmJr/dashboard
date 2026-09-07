<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model Dataset - satu file unggahan (Excel/CSV) berisi data kinerja.
 *
 * Lifecycle status:
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
    use HasFactory;

    public const STATUS_UPLOADING = 'UPLOADING';

    public const STATUS_VALIDATING = 'VALIDATING';

    public const STATUS_READY = 'READY';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_ARCHIVED = 'ARCHIVED';

    protected $casts = [
        'size' => 'integer',
        'validation_report' => 'array',
    ];

    /**
     * Scope: dataset yang dihitung dalam agregasi chart (bukan archived).
     */
    public function scopeChartable($query)
    {
        return $query->where('status', self::STATUS_READY);
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
}
