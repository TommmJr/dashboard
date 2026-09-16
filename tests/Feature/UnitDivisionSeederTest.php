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

    public function test_seeder_membuat_satu_divisi_owner_data(): void
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

    public function test_owner_menggunakan_slug_tjbt(): void
    {
        $this->seed(UnitDivisionSeeder::class);

        $this->assertSame('TJBT', Division::where('slug', 'tjbt')->sole()->name);
    }

    public function test_entitas_pembanding_tidak_dijadikan_division(): void
    {
        $this->seed(UnitDivisionSeeder::class);

        $this->assertFalse(Division::where('slug', 'trans')->exists());
        $this->assertFalse(Division::where('slug', 'tjbb')->exists());
        $this->assertFalse(Division::where('slug', 'p3b-sum')->exists());
    }
}
