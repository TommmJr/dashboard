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

        $this->get('/dashboard/asset-detail/trafo')
            ->assertRedirect(route('login'));

        $this->get('/dashboard/asset-detail/mva')
            ->assertRedirect(route('login'));

        $this->get('/dashboard/asset-detail/transmisi')
            ->assertRedirect(route('login'));

        $this->get('/dashboard/asset-detail/tower')
            ->assertRedirect(route('login'));
    }

    public function test_staf_managemen_aset_bisa_mengakses_seluruh_sub_halaman_detail_aset(): void
    {
        $division = Division::factory()->create(['name' => 'Managemen Aset', 'slug' => 'managemen-aset']);
        $user = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);

        $this->seedAsetSampleData($division);

        // 1. Root default (trafo)
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

        // 2. Trafo explicit
        $this->actingAs($user)
            ->get('/dashboard/asset-detail/trafo')
            ->assertOk()
            ->assertSee('id="asset-detail-trafo-chart"', false);

        // 3. MVA
        $this->actingAs($user)
            ->get('/dashboard/asset-detail/mva')
            ->assertOk()
            ->assertSee('Distribusi Daya (MVA)', false);

        // 4. Transmisi
        $this->actingAs($user)
            ->get('/dashboard/asset-detail/transmisi')
            ->assertOk()
            ->assertSee('Jaringan Transmisi (kms)', false);

        // 5. Tower
        $this->actingAs($user)
            ->get('/dashboard/asset-detail/tower')
            ->assertOk()
            ->assertSee('Sebaran Tower (Unit)', false);
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

    public function test_sub_halaman_tidak_dikenal_mengembalikan_404(): void
    {
        $division = Division::factory()->create(['name' => 'Managemen Aset', 'slug' => 'managemen-aset']);
        $user = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);

        $this->actingAs($user)
            ->get('/dashboard/asset-detail/unknown-section')
            ->assertNotFound();
    }

    public function test_admin_ditolak_403_fail_closed_pada_seluruh_rute_detail_aset(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);
        $division = Division::factory()->create(['name' => 'Managemen Aset', 'slug' => 'managemen-aset']);
        $this->seedAsetSampleData($division);

        $this->actingAs($admin)
            ->get(route('dashboard.asset-detail'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get('/dashboard/asset-detail/trafo')
            ->assertForbidden();

        $this->actingAs($admin)
            ->get('/dashboard/asset-detail/mva')
            ->assertForbidden();

        $this->actingAs($admin)
            ->get('/dashboard/asset-detail/transmisi')
            ->assertForbidden();

        $this->actingAs($admin)
            ->get('/dashboard/asset-detail/tower')
            ->assertForbidden();
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

        foreach ([$management, $editor, $stafPengusahaan] as $otherUser) {
            $this->actingAs($otherUser)
                ->get(route('dashboard.asset-detail'))
                ->assertForbidden();

            $this->actingAs($otherUser)
                ->get('/dashboard/asset-detail/trafo')
                ->assertForbidden();

            $this->actingAs($otherUser)
                ->get('/dashboard/asset-detail/mva')
                ->assertForbidden();

            $this->actingAs($otherUser)
                ->get('/dashboard/asset-detail/transmisi')
                ->assertForbidden();

            $this->actingAs($otherUser)
                ->get('/dashboard/asset-detail/tower')
                ->assertForbidden();
        }
    }

    public function test_link_sidebar_hanya_tampil_untuk_staf_aset_dan_tidak_tampil_untuk_admin(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $asetDiv = Division::factory()->create(['name' => 'Managemen Aset', 'slug' => 'managemen-aset']);
        $stafAset = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $asetDiv->id]);

        $pengusahaanDiv = Division::factory()->create(['name' => 'Perencanaan Pengusahaan', 'slug' => 'perencanaan-pengusahaan']);
        $stafPengusahaan = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $pengusahaanDiv->id]);

        $management = User::factory()->withRole(Role::MANAGEMENT)->create(['division_id' => null]);
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create(['division_id' => null]);

        // Staf Managemen Aset melihat link di sidebar
        $this->actingAs($stafAset)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="sidebar-asset-detail-link"', false)
            ->assertSee('id="sidebar-asset-mva-link"', false)
            ->assertSee('id="sidebar-asset-transmisi-link"', false)
            ->assertSee('id="sidebar-asset-tower-link"', false);

        // Admin TIDAK melihat link di sidebar dashboard atau builder
        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('id="sidebar-asset-detail-link"', false)
            ->assertDontSee('id="sidebar-asset-mva-link"', false)
            ->assertDontSee('id="sidebar-asset-transmisi-link"', false)
            ->assertDontSee('id="sidebar-asset-tower-link"', false)
            ->assertDontSee('Rincian Trafo & Aset', false)
            ->assertDontSee('Eksplorasi Aset', false);

        $this->actingAs($admin)
            ->get(route('dashboard.builder'))
            ->assertOk()
            ->assertDontSee('id="sidebar-asset-detail-link"', false)
            ->assertDontSee('id="sidebar-asset-mva-link"', false)
            ->assertDontSee('id="sidebar-asset-transmisi-link"', false)
            ->assertDontSee('id="sidebar-asset-tower-link"', false)
            ->assertDontSee('Rincian Trafo & Aset', false)
            ->assertDontSee('Eksplorasi Aset', false);

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

    public function test_halaman_detail_aset_menampilkan_hirarki_kepemilikan_uit_jbt_dan_distribusi_wilayah_upt(): void
    {
        $division = Division::factory()->create(['name' => 'Managemen Aset', 'slug' => 'managemen-aset']);
        $user = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);

        $this->seedAsetSampleData($division);

        $this->actingAs($user)
            ->get(route('dashboard.asset-detail'))
            ->assertOk()
            ->assertSee('Kepemilikan Aset UIT JBT', false)
            ->assertSee('Unit Induk Transmisi Jawa Bagian Tengah (UIT JBT)', false)
            ->assertSee('Rekapitulasi Kepemilikan Aset Induk UIT JBT (Tab 1a)', false)
            ->assertSee('Distribusi Wilayah Aset UIT JBT', false)
            ->assertSee('Distribusi Wilayah Aset UIT JBT pada 8 UPT (Tab 1b)', false)
            ->assertSee('EKSPLORASI ASET UIT JBT', false)
            ->assertSee('Kepemilikan Induk & Sebaran 8 UPT', false);

        foreach (['mva', 'transmisi', 'tower'] as $section) {
            $this->actingAs($user)
                ->get(route('dashboard.asset-detail', ['section' => $section]))
                ->assertOk()
                ->assertSee('Kepemilikan Aset UIT JBT', false)
                ->assertSee('Distribusi Wilayah Aset UIT JBT pada 8 UPT (Tab 1b)', false);
        }
    }

    public function test_halaman_detail_aset_tidak_merender_filter_upt_ganda_di_toolbar_atas_dan_mempertahankan_filter_matriks_bawah(): void
    {
        $division = Division::factory()->create(['name' => 'Managemen Aset', 'slug' => 'managemen-aset']);
        $user = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);

        $this->seedAsetSampleData($division);

        foreach (['trafo', 'mva', 'transmisi', 'tower'] as $section) {
            $this->actingAs($user)
                ->get(route('dashboard.asset-detail', ['section' => $section]))
                ->assertOk()
                ->assertSee('Bedah Trafo & Tegangan', false)
                ->assertSee('Distribusi Daya (MVA)', false)
                ->assertSee('Jaringan Transmisi (kms)', false)
                ->assertSee('Sebaran Tower (Unit)', false)
                ->assertDontSee('setUptFilter', false)
                ->assertDontSee('activeUptFilter', false)
                ->assertDontSee('asset-upt-filter', false)
                ->assertSee('Eksplorasi Matriks Seluruh Atribut Aset', false)
                ->assertSee('x-model="selectedUpt"', false)
                ->assertSee('Semua UPT (8 UPT)', false)
                ->assertSee('selectedGroup', false)
                ->assertSee('Cari atribut aset...', false);
        }
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
