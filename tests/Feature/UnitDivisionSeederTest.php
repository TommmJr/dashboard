<?php

namespace Tests\Feature;

use App\Models\Division;
use Database\Seeders\UnitDivisionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Divisi organisasi nyata pemilik data kinerja.
 */
class UnitDivisionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_membuat_dua_divisi_organisasi_nyata(): void
    {
        $this->seed(UnitDivisionSeeder::class);

        $this->assertSame(count(UnitDivisionSeeder::UNITS), Division::count());

        $pengusahaan = Division::where('slug', 'perencanaan-pengusahaan')->sole();
        $dataAset = Division::where('slug', 'data-aset')->sole();
        $this->assertSame('Perencanaan Pengusahaan', $pengusahaan->name);
        $this->assertSame('Data Aset', $dataAset->name);
    }

    public function test_seeder_idempoten_saat_dijalankan_ulang(): void
    {
        $this->seed(UnitDivisionSeeder::class);
        $this->seed(UnitDivisionSeeder::class);

        $this->assertSame(count(UnitDivisionSeeder::UNITS), Division::count());
    }

    public function test_divisi_menggunakan_slug_organisasi(): void
    {
        $this->seed(UnitDivisionSeeder::class);

        $this->assertSame('Perencanaan Pengusahaan', Division::where('slug', 'perencanaan-pengusahaan')->sole()->name);
        $this->assertSame('Data Aset', Division::where('slug', 'data-aset')->sole()->name);
    }

    public function test_entitas_pembanding_tidak_dijadikan_division(): void
    {
        $this->seed(UnitDivisionSeeder::class);

        $this->assertFalse(Division::where('slug', 'trans')->exists());
        $this->assertFalse(Division::where('slug', 'tjbb')->exists());
        $this->assertFalse(Division::where('slug', 'p3b-sum')->exists());
    }
}
