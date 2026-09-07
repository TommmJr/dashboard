<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceMetric extends Model
{
    protected $fillable = ['metric_name', 'value', 'period', 'unit_id', 'division_id'];

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
}
