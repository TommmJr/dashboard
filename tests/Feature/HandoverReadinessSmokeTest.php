<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HandoverReadinessSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_governance_gate_widget_aset_ada_di_katalog_namun_tidak_dipasang_di_layout_produksi(): void
    {
        $config = config('dashboard_layouts');

        $this->assertArrayHasKey('aset-upt-chart', $config['widgets']);

        foreach ($config['layouts'] as $layoutKey => $widgets) {
            $this->assertNotContains(
                'aset-upt-chart',
                $widgets,
                sprintf('Widget aset-upt-chart tidak boleh dipasang pada layout [%s] sebelum sign-off mentor/PIC.', $layoutKey)
            );
        }
    }

    public function test_konfigurasi_produksi_kpi_memakai_structured_dan_scope_tjbt(): void
    {
        $this->assertSame('structured', config('kpi.read_model'));
        $this->assertSame(['TJBT'], config('kpi.visible_units'));
    }

    public function test_keempat_role_resmi_dapat_memuat_dashboard_utama_tanpa_error(): void
    {
        $pengusahaan = Division::create([
            'name' => 'Perencanaan Pengusahaan',
            'slug' => 'perencanaan-pengusahaan',
        ]);

        $roles = [
            Role::ADMIN => null,
            Role::MANAGEMENT => null,
            Role::EDITOR_DASHBOARD => null,
            Role::STAFF_DIVISI => $pengusahaan->id,
        ];

        foreach ($roles as $roleSlug => $divisionId) {
            $role = Role::where('slug', $roleSlug)->firstOrFail();
            $user = User::factory()->create([
                'role_id' => $role->id,
                'division_id' => $divisionId,
            ]);

            $this->actingAs($user)
                ->get(route('dashboard'))
                ->assertOk();
        }
    }
}
