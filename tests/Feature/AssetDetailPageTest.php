<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Division;
use App\Models\PerformanceMetric;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetDetailPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_guest_diarahkan_ke_halaman_login(): void
    {
        $this->get(route('dashboard.asset-detail'))
            ->assertRedirect(route('login'));
    }

    public function test_staf_managemen_aset_bisa_mengakses_halaman_detail_aset(): void
    {
        $division = Division::factory()->create(['name' => 'Managemen Aset', 'slug' => 'managemen-aset']);
        $user = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);

        $this->seedAsetSampleData($division);

        $this->actingAs($user)
            ->get(route('dashboard.asset-detail'))
            ->assertOk()
            ->assertSee('Rincian Trafo & Stok Aset', false)
            ->assertSee('Bedah Detail Trafo per Level Tegangan & UPT', false)
            ->assertSee('Total Kapasitas Trafo')
            ->assertSee('Fisik Trafo Terpasang')
            ->assertSee('Panjang Transmisi')
            ->assertSee('Total Tower')
            ->assertSee('id="asset-detail-trafo-chart"', false)
            ->assertSee('height: 360px', false);
    }

    public function test_staf_data_aset_bisa_mengakses_halaman_detail_aset(): void
    {
        $division = Division::factory()->create(['name' => 'Data Aset', 'slug' => 'data-aset']);
        $user = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);

        $this->seedAsetSampleData($division);

        $this->actingAs($user)
            ->get(route('dashboard.asset-detail'))
            ->assertOk()
            ->assertSee('Rincian Trafo & Stok Aset', false);
    }

    public function test_admin_bisa_mengakses_halaman_detail_aset(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);
        $division = Division::factory()->create(['name' => 'Managemen Aset', 'slug' => 'managemen-aset']);
        $this->seedAsetSampleData($division);

        $this->actingAs($admin)
            ->get(route('dashboard.asset-detail'))
            ->assertOk()
            ->assertSee('Rincian Trafo & Stok Aset', false)
            ->assertSee('TOTAL UIT JBT');
    }

    public function test_role_lain_ditolak_403_fail_closed(): void
    {
        $management = User::factory()->withRole(Role::MANAGEMENT)->create(['division_id' => null]);
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create(['division_id' => null]);

        $pengusahaanDiv = Division::factory()->create([
            'name' => 'Perencanaan Pengusahaan',
            'slug' => 'perencanaan-pengusahaan',
        ]);
        $stafPengusahaan = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $pengusahaanDiv->id]);

        $this->actingAs($management)
            ->get(route('dashboard.asset-detail'))
            ->assertForbidden();

        $this->actingAs($editor)
            ->get(route('dashboard.asset-detail'))
            ->assertForbidden();

        $this->actingAs($stafPengusahaan)
            ->get(route('dashboard.asset-detail'))
            ->assertForbidden();
    }

    public function test_link_sidebar_hanya_tampil_untuk_admin_dan_staf_aset(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $asetDiv = Division::factory()->create(['name' => 'Managemen Aset', 'slug' => 'managemen-aset']);
        $stafAset = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $asetDiv->id]);

        $pengusahaanDiv = Division::factory()->create(['name' => 'Perencanaan Pengusahaan', 'slug' => 'perencanaan-pengusahaan']);
        $stafPengusahaan = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $pengusahaanDiv->id]);

        $management = User::factory()->withRole(Role::MANAGEMENT)->create(['division_id' => null]);
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create(['division_id' => null]);

        // Admin melihat link di sidebar
        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="sidebar-asset-detail-link"', false)
            ->assertSee('Rincian Trafo & Aset', false);

        // Staf Managemen Aset melihat link di sidebar
        $this->actingAs($stafAset)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="sidebar-asset-detail-link"', false)
            ->assertSee('Rincian Trafo & Aset', false);

        // Role lain TIDAK melihat link di sidebar
        $this->actingAs($stafPengusahaan)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('id="sidebar-asset-detail-link"', false);

        $this->actingAs($management)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('id="sidebar-asset-detail-link"', false);

        $this->actingAs($editor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('id="sidebar-asset-detail-link"', false);
    }

    public function test_detail_halaman_memisahkan_kapasitas_mva_dan_jumlah_fisik(): void
    {
        $division = Division::factory()->create(['name' => 'Managemen Aset', 'slug' => 'managemen-aset']);
        $user = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);

        $this->seedAsetSampleData($division);

        $response = $this->actingAs($user)->get(route('dashboard.asset-detail'))->assertOk();

        // Header kolom terpisah
        $response->assertSee('Kapasitas Daya (MVA)')
            ->assertSee('Fisik (Bank)')
            ->assertSee('Fisik (Unit / Set)')
            ->assertSee('Saluran Transmisi (SU vs SK)')
            ->assertSee('Tower Transmisi per Tegangan')
            ->assertSee('Eksplorasi Matriks Seluruh Atribut Aset');

        // Nilai kanonis UPT
        foreach (['BANDUNG', 'BEKASI', 'BOGOR', 'CIREBON', 'KARAWANG', 'PURWOKERTO', 'SALATIGA', 'SEMARANG'] as $upt) {
            $response->assertSee($upt);
        }

        // Nilai MVA dan Fisik tampil terpisah
        $response->assertSee('2.500')
            ->assertSee('15')
            ->assertSee('5');
    }

    private function seedAsetSampleData(Division $division): void
    {
        $dataset = Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_READY,
        ]);

        $period = '2026-08-31';

        // 1. Tab 1b (UPT level)
        $tab1bRows = [
            ['BANDUNG', 'BANDUNG BARAT', 'Trafo 500/150 KV | MVA', 2500.0],
            ['BANDUNG', 'BANDUNG BARAT', 'Trafo 500/150 KV | Bank', 15.0],
            ['BANDUNG', 'BANDUNG BARAT', 'Trafo 500/150 KV | Unit/Set', 5.0],
            ['BANDUNG', 'BANDUNG BARAT', 'Transmisi 500 KV | SU | kms', 500.0],
            ['BANDUNG', 'BANDUNG BARAT', 'Tower 500 KV | SU', 800.0],
            ['BEKASI', 'BEKASI', 'Trafo 500/150 KV | MVA', 2500.0],
            ['BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', 15.0],
            ['BEKASI', 'BEKASI', 'Trafo 500/150 KV | Unit/Set', 5.0],
            ['BEKASI', 'BEKASI', 'Transmisi 500 KV | SU | kms', 500.0],
            ['BEKASI', 'BEKASI', 'Tower 500 KV | SU', 800.0],
        ];

        foreach ($tab1bRows as [$upt, $ultg, $attr, $val]) {
            PerformanceMetric::factory()->create([
                'metric_name' => "ASET | {$upt} | {$ultg} | {$attr}",
                'dataset_id' => $dataset->id,
                'division_id' => $division->id,
                'period' => $period,
                'value' => $val,
            ]);
        }

        // 2. Tab 1a (UIT level)
        $tab1aRows = [
            ['Trafo 500/150 KV | MVA', 20500.0],
            ['Trafo 500/150 KV | Bank', 123.0],
            ['Trafo 500/150 KV | Unit/Set', 41.0],
            ['Transmisi 500 KV | SU | kms', 4213.5],
            ['Tower 500 KV | SU', 6336.0],
        ];

        foreach ($tab1aRows as [$attr, $val]) {
            PerformanceMetric::factory()->create([
                'metric_name' => "ASET | UIT | {$attr}",
                'dataset_id' => $dataset->id,
                'division_id' => $division->id,
                'period' => $period,
                'value' => $val,
            ]);
        }
    }
}
