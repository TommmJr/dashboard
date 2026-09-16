<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Layout widget dashboard per role (Dashboard Builder Fase 1).
 *
 * Label widget di-render server-side (bukan Alpine/JS) sehingga
 * assertSee/assertDontSee valid untuk memverifikasi komposisi layout
 * per role dari config/dashboard_layouts.php.
 */
class DashboardWidgetLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_melihat_widget_audit_dan_dataset_gagal(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Audit Terbaru')
            ->assertSee('Dataset Gagal')
            ->assertSee('Grafik Tren Kinerja');
    }

    public function test_dashboard_merender_kontrol_filter_performance(): void
    {
        $management = User::factory()->withRole(Role::MANAGEMENT)->create();

        $this->actingAs($management)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="start_date"', false)
            ->assertSee('id="end_date"', false)
            ->assertSee('id="unit"', false)
            ->assertSee('x-model="filters.units"', false)
            ->assertSee('Semua unit')
            ->assertSee('Terapkan')
            ->assertSee('Reset');
    }

    public function test_management_tidak_melihat_widget_admin(): void
    {
        $management = User::factory()->withRole(Role::MANAGEMENT)->create();

        $this->actingAs($management)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Grafik Tren Kinerja')
            ->assertDontSee('Audit Terbaru')
            ->assertDontSee('Dataset Gagal')
            ->assertDontSee('Editor Layout');
    }

    public function test_editor_dashboard_melihat_placeholder_layout_editor(): void
    {
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();

        $this->actingAs($editor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Editor Layout')
            ->assertSee('Mode edit layout menyusul (Fase 2)')
            ->assertDontSee('Audit Terbaru');
    }

    public function test_staff_divisi_melihat_layout_default(): void
    {
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create();

        $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Rata-rata NKO')
            ->assertSee('Grafik Tren Kinerja')
            ->assertDontSee('Audit Terbaru')
            ->assertDontSee('Dataset Gagal')
            ->assertDontSee('Editor Layout');
    }

    public function test_user_tanpa_role_mendapat_layout_default(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Grafik Tren Kinerja')
            ->assertDontSee('Audit Terbaru');
    }
}
