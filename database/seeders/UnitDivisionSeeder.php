<?php

namespace Database\Seeders;

use App\Models\Division;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Divisi unit pemilik data kinerja.
 *
 * Sumber: sheet `REKAP` laporan KPI mentor (satu sheet per unit). Divisi
 * fungsional dan Unit Induk diperlakukan sama karena di laporan itu keduanya
 * memang sejajar di bawah direktorat.
 *
 * `name` = kode unit (mis. "TJBT"), bukan nama panjang, karena kode itulah yang
 * dipakai KpiReportConverter sebagai prefix metric_name dan menjadi label
 * ringkas di chart. Nama panjang disimpan di `description`.
 *
 * Idempoten (firstOrCreate by slug). TIDAK dipanggil dari DatabaseSeeder supaya
 * data organisasi riil tidak tercipta otomatis tanpa review. Jalankan eksplisit:
 *
 *     php artisan db:seed --class=UnitDivisionSeeder
 *
 * Sheet `TRANS` sengaja TIDAK diikutkan: dia level direktorat (rollup, B4
 * kosong), bukan unit — memasukkannya ke peringkat unit akan mencampur dua
 * level organisasi.
 */
class UnitDivisionSeeder extends Seeder
{
    /**
     * Kode unit => nama lengkap.
     *
     * @var array<string, string>
     */
    public const UNITS = [
        'RST' => 'Divisi Perencanaan Strategis Transmisi',
        'TEK' => 'Divisi Transisi Energi dan Keberlanjutan',
        'RSL' => 'Divisi Perencanaan Sistem Ketenagalistrikan',
        'TSJ' => 'Divisi Transmisi Sumatera, Jawa, Madura dan Bali',
        'TKS' => 'Divisi Transmisi Kalimantan, Sulawesi, Maluku, Papua dan Nusa Tenggara',
        'MES' => 'Divisi Manajemen Aset, Enjiniring dan Sistem Manajemen Terintegrasi',
        'OSL' => 'Divisi Operasi Sistem Ketenagalistrikan',
        'TJBM' => 'Unit Induk Transmisi Jawa Bagian Timur dan Bali',
        'P3B KAL' => 'Unit Induk Penyaluran dan Pusat Pengatur Beban Kalimantan',
        'TJBT' => 'Unit Induk Transmisi Jawa Bagian Tengah',
        'P2B' => 'Unit Induk Pusat Pengatur Beban Jawa, Madura dan Bali',
        'TJBB' => 'Unit Induk Transmisi Jawa Bagian Barat',
        'P3B SUL' => 'Unit Induk Penyaluran dan Pusat Pengatur Beban Sulawesi',
        'P3B SUM' => 'Unit Induk Penyaluran dan Pusat Pengatur Beban Sumatera',
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
