<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Indikator KPI milik satu unit organisasi.
 *
 * Identitas per-unit: unique(org_unit_id, name). Kode yang sama dapat muncul
 * di unit berbeda dengan arti berbeda, jadi name TIDAK global-unique.
 *
 * parent_id adalah self-reference load-bearing: bobot hanya berada di baris
 * induk, baris anak kosong. Hierarki datang dari workbook, bukan dari asumsi
 * "baris sebelumnya = induk".
 */
#[Fillable([
    'org_unit_id', 'name', 'parent_id', 'aggregation_code', 'polarity',
    'formula', 'satuan', 'satuan_normalized', 'bobot', 'bobot_value',
    'bobot_min', 'bobot_max', 'category', 'is_group', 'pencapaian_formula_kind',
])]
class Indicator extends Model
{
    use Auditable, HasFactory;

    protected function casts(): array
    {
        return [
            'is_group' => 'boolean',
            'bobot_value' => 'decimal:4',
            'bobot_min' => 'decimal:4',
            'bobot_max' => 'decimal:4',
        ];
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(PerformanceObservation::class);
    }
}
