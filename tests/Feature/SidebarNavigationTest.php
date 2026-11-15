<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_sidebar_contract_for_authenticated_roles(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();
        $management = User::factory()->withRole(Role::MANAGEMENT)->create();
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Dashboard Builder')
            ->assertSee(route('dashboard.builder'), false)
            ->assertDontSee('Segera')
            ->assertSee('id="main-sidebar"', false)
            ->assertSee('aria-controls="main-sidebar"', false)
            ->assertSee('Buka menu', false);

        $this->actingAs($editor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Dashboard Builder')
            ->assertSee(route('dashboard.builder'), false)
            ->assertDontSee('Segera');

        foreach ([$management, $staff] as $user) {
            $this->actingAs($user)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertDontSee('Dashboard Builder')
                ->assertDontSee(route('dashboard.builder'), false)
                ->assertDontSee('Segera');
        }
    }

    public function test_sidebar_renders_clean_single_line_menu_without_subdescriptions_and_hides_scrollbars(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertOk();

        // Scrollbar-hiding & overflow-prevention classes
        $response->assertSee('[&::-webkit-scrollbar]:hidden', false);
        $response->assertSee('[-ms-overflow-style:none]', false);
        $response->assertSee('[scrollbar-width:none]', false);
        $response->assertSee('overflow-x-hidden', false);
        $response->assertSee('whitespace-nowrap overflow-hidden text-ellipsis', false);

        // Sub-description captions removed
        $response->assertDontSee('Ringkasan operasional');
        $response->assertDontSee('Kelola berkas divisi');
        $response->assertDontSee('Impor & sinkronisasi');
        $response->assertDontSee('Akun & hak akses');
        $response->assertDontSee('Jejak aktivitas sistem');
        $response->assertDontSee('Atur tata letak & widget');

        // Headers for admin
        $response->assertSee('MONITORING');
        $response->assertSee('MANAJEMEN DATA');
        $response->assertSee('TATA KELOLA & AKSES', false);
        $response->assertSee('KONFIGURASI');

        // Admin has 0 division deep-dive links
        $response->assertDontSee('id="sidebar-kpi-detail-link"', false);
        $response->assertDontSee('id="sidebar-kpi-analytics-link"', false);
        $response->assertDontSee('id="sidebar-asset-detail-link"', false);
    }

    public function test_staff_divisions_see_correct_scoped_headers_and_no_subdescriptions(): void
    {
        $manset = Division::create(['name' => 'Managemen Aset', 'slug' => 'managemen-aset']);
        $pengusahaan = Division::create(['name' => 'Perencanaan Pengusahaan', 'slug' => 'perencanaan-pengusahaan']);

        $stafManset = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $manset->id]);
        $stafPengusahaan = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $pengusahaan->id]);

        // Staf Pengusahaan
        $respPengusahaan = $this->actingAs($stafPengusahaan)->get(route('dashboard'));
        $respPengusahaan->assertOk();
        $respPengusahaan->assertSee('UTAMA');
        $respPengusahaan->assertSee('DATA DIVISI');
        $respPengusahaan->assertDontSee('EKSPLORASI ASET');
        $respPengusahaan->assertSee('id="sidebar-kpi-detail-link"', false);
        $respPengusahaan->assertDontSee('id="sidebar-kpi-analytics-link"', false);
        $respPengusahaan->assertDontSee('Ringkasan NKO & tren');
        $respPengusahaan->assertDontSee('Arsip & riwayat berkas');
        $respPengusahaan->assertDontSee('Impor workbook KPI');

        // Staf Managemen Aset
        $respManset = $this->actingAs($stafManset)->get(route('dashboard'));
        $respManset->assertOk();
        $respManset->assertSee('UTAMA');
        $respManset->assertSee('EKSPLORASI ASET');
        $respManset->assertSee('DATA DIVISI');
        $respManset->assertSee('id="sidebar-asset-detail-link"', false);
        $respManset->assertSee('id="sidebar-asset-mva-link"', false);
        $respManset->assertSee('id="sidebar-asset-transmisi-link"', false);
        $respManset->assertSee('id="sidebar-asset-tower-link"', false);
        $respManset->assertDontSee('Rekapitulasi UIT & UPT');
        $respManset->assertDontSee('MVA & unit per kV');
        $respManset->assertDontSee('Riwayat data aset');
        $respManset->assertDontSee('Unggah & sinkronisasi');
    }
}
