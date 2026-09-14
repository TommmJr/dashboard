<?php

namespace Tests\Feature;

use App\Models\Division;
use Database\Seeders\UnitDivisionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Divisi unit pemilik data kinerja (sumber: sheet REKAP laporan KPI).
 */
class UnitDivisionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_membuat_satu_divisi_untuk_setiap_unit(): void
    {
        $this->seed(UnitDivisionSeeder::class);

        $this->assertSame(count(UnitDivisionSeeder::UNITS), Division::count());

        $tjbt = Division::where('slug', 'tjbt')->sole();
        $this->assertSame('TJBT', $tjbt->name);
        $this->assertSame('Unit Induk Transmisi Jawa Bagian Tengah', $tjbt->description);
    }

    public function test_seeder_idempoten_saat_dijalankan_ulang(): void
    {
        $this->seed(UnitDivisionSeeder::class);
        $this->seed(UnitDivisionSeeder::class);

        $this->assertSame(count(UnitDivisionSeeder::UNITS), Division::count());
    }

    public function test_slug_unit_berkode_spasi_tetap_valid(): void
    {
        $this->seed(UnitDivisionSeeder::class);

        // "P3B SUM" -> slug "p3b-sum".
        $this->assertSame('P3B SUM', Division::where('slug', 'p3b-sum')->sole()->name);
    }

    public function test_sheet_trans_level_direktorat_tidak_dijadikan_unit(): void
    {
        $this->seed(UnitDivisionSeeder::class);

        $this->assertFalse(Division::where('slug', 'trans')->exists());
    }
}
