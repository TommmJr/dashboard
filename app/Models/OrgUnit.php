<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Unit organisasi sumber KPI (mis. TJBT, MES, RST).
 *
 * Nama unit berasal dari judul sheet XLSX dan dipertahankan verbatim sebagai
 * identity source: unique(name) cukup, tanpa slug/kode yang tidak punya sumber.
 */
#[Fillable(['name'])]
class OrgUnit extends Model
{
    use HasFactory;

    public function indicators(): HasMany
    {
        return $this->hasMany(Indicator::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(PerformanceObservation::class);
    }
}
