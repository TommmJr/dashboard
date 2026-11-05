<?php

namespace Database\Seeders;

use App\Models\Division;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Divisi organisasi pemilik data kinerja.
 *
 * Unit pada sheet `REKAP` laporan KPI mentor tetap menjadi dimensi pembanding,
 * bukan division owner. Division di sini hanya sub-bidang organisasi yang
 * memang memiliki data dan akun staf sendiri.
 *
 * Idempoten (firstOrCreate by slug). Dipanggil dari DatabaseSeeder dan dapat
 * dijalankan ulang secara eksplisit:
 *
 *     php artisan db:seed --class=UnitDivisionSeeder
 *
 * Sheet `TRANS` dan entitas pembanding lain sengaja TIDAK dibuat sebagai
 * division: memasukkannya akan mencampur pemilik data dengan dimensi laporan.
 */
class UnitDivisionSeeder extends Seeder
{
    /**
     * Nama division => nama lengkap.
     *
     * @var array<string, string>
     */
    public const UNITS = [
        'Perencanaan Pengusahaan' => 'Perencanaan Pengusahaan',
        'Managemen Aset' => 'Managemen Aset',
    ];

    public function run(): void
    {
        foreach (self::UNITS as $name => $description) {
            Division::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'description' => $description],
            );
        }
    }
}
