<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceMetric extends Model
{
    use HasFactory;

    protected $fillable = ['metric_name', 'value', 'period', 'unit_id', 'division_id', 'dataset_id'];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'period' => 'date',
        ];
    }

    /**
     * Relasi division pemilik data (digunakan untuk scoping akses).
     *
     * Catatan: unit_id merujuk ke users.id (siapa pengunggah) dan TETAP
     * dipertahankan untuk audit trail. division_id adalah scope otorisasi
     * per data point — inilah yang dipakai untuk filtering pada chart.
     */
    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    /**
     * Dataset sumber baris ini. Dipakai untuk:
     * - preview data per dataset (halaman show),
     * - eksklusi baris dari chart ketika dataset berstatus ARCHIVED,
     * - cascade delete ketika dataset dihapus permanen oleh admin.
     */
    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }
}
