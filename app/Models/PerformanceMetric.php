<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceMetric extends Model
{
    use Auditable, HasFactory;

    protected $fillable = ['metric_name', 'value', 'period', 'unit_id', 'division_id', 'dataset_id'];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            // 'date:Y-m-d' menyimpan + mengembalikan string Y-m-d tanpa
            // konversi timezone (berbeda dari 'date' yang dianggap UTC).
            // Mencegah geser tanggal di env Asia/Jakarta.
            'period' => 'date:Y-m-d',
        ];
    }

    /**
     * Relasi division pemilik data (digunakan untuk scoping akses).
     *
     * Catatan: unit_id merujuk ke users.id (siapa pengunggah) dan TETAP
     * dipertahankan untuk audit trail. division_id adalah scope otorisasi
     * per data point — inilah yang dipakai untuk filtering pada chart.
     *
     * @return BelongsTo<Division, $this>
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
     *
     * @return BelongsTo<Dataset, $this>
     */
    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }
}
