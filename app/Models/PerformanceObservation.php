<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nilai KPI terstruktur per (dataset, indikator, unit, periode).
 *
 * Target dan realisasi bertipe: numeric, date, text, atau kosong. Kolom *_type
 * menandai varian yang terisi; representasi sumber tidak boleh hilang hanya
 * karena tersedia kolom numeric.
 *
 * Tabel ini hidup BERDAMPINGAN dengan performance_metrics selama transisi
 * (read-model flag legacy/dual/b). Jangan hapus tabel legacy sebelum exit
 * criteria deprecation terpenuhi.
 */
#[Fillable([
    'dataset_id', 'indicator_id', 'org_unit_id', 'division_id', 'period',
    'target_value', 'target_text', 'target_date', 'target_type',
    'realisasi_value', 'realisasi_text', 'realisasi_date', 'realisasi_type',
    'pencapaian', 'nilai', 'ket', 'source_sheet', 'source_row',
    'sheet_period', 'period_conflict',
])]
class PerformanceObservation extends Model
{
    use HasFactory;

    /** Tipe nilai target/realisasi. Sumber: numeric, date, text, atau kosong. */
    public const TYPE_NUMERIC = 'numeric';

    public const TYPE_DATE = 'date';

    public const TYPE_TEXT = 'text';

    public const TYPE_EMPTY = 'empty';

    protected function casts(): array
    {
        return [
            'target_value' => 'decimal:4',
            'realisasi_value' => 'decimal:4',
            'pencapaian' => 'decimal:4',
            'nilai' => 'decimal:4',
            // 'date:Y-m-d' menyimpan + mengembalikan string Y-m-d tanpa
            // konversi timezone (pola sama dengan PerformanceMetric::period).
            'period' => 'date:Y-m-d',
            'target_date' => 'date:Y-m-d',
            'realisasi_date' => 'date:Y-m-d',
            'source_row' => 'integer',
            'period_conflict' => 'boolean',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }
}
