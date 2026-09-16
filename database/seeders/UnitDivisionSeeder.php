<?php

namespace Database\Seeders;

use App\Models\Division;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Divisi unit pemilik data kinerja.
 *
 * Pemilik data aplikasi adalah TJBT (Perencanaan Pengusahaan). Nama lain
 * pada sheet `REKAP` laporan KPI mentor adalah dimensi pembanding di dalam
 * laporan, bukan division owner yang membutuhkan akun dan scope RBAC sendiri.
 *
 * `name` = kode unit (mis. "TJBT"), bukan nama panjang, karena kode itulah yang
 * dipakai KpiReportConverter sebagai prefix metric_name dan menjadi label
 * ringkas di chart. Nama panjang disimpan di `description`.
 *
 * Idempoten (firstOrCreate by slug). Dipanggil dari DatabaseSeeder dan dapat
 * dijalankan ulang secara eksplisit:
 *
 *     php artisan db:seed --class=UnitDivisionSeeder
 *
 * Sheet `TRANS` dan 13 entitas pembanding sengaja TIDAK dibuat sebagai
 * division: memasukkannya akan mencampur pemilik data dengan dimensi laporan.
 */
class UnitDivisionSeeder extends Seeder
{
    /**
     * Kode owner => nama lengkap.
     *
     * @var array<string, string>
     */
    public const UNITS = [
        'TJBT' => 'Unit Induk Transmisi Jawa Bagian Tengah',
    ];

    public function run(): void
    {
        foreach (self::UNITS as $code => $name) {
            Division::firstOrCreate(
                ['slug' => Str::slug($code)],
                ['name' => $code, 'description' => $name],
            );
        }
    }
}
