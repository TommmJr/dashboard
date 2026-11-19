<?php

namespace Tests\Feature;

use App\Models\DashboardLayout;
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

    public function test_widget_aset_dipasang_hanya_di_layout_staf_managemen_aset_untuk_demo_prototipe(): void
    {
        $config = config('dashboard_layouts');

        $this->assertArrayHasKey('aset-upt-chart', $config['widgets']);
        $this->assertSame(3, $config['widgets']['aset-upt-chart']['default_cols']);
        $this->assertSame([], DashboardLayout::FORBIDDEN_WIDGETS);

        // Keputusan user untuk prototipe demo: hanya layout default staf
        // Managemen Aset yang memasang widget, tepat di bawah dataset-status.
        // Layout lain tetap tanpa widget Aset.
        $mounted = array_keys(array_filter(
            $config['layouts'],
            fn (array $widgets): bool => in_array('aset-upt-chart', $widgets, true),
        ));

        $this->assertSame(['staff_divisi:managemen-aset'], $mounted);
        $this->assertSame(
            ['aset-upt-chart'],
            $config['layouts']['staff_divisi:managemen-aset'],
        );
    }

    public function test_staf_managemen_aset_melihat_ringkasan_aset_tanpa_badge_prototipe(): void
    {
        $manset = Division::create(['name' => 'Managemen Aset', 'slug' => 'managemen-aset']);
        $pengusahaan = Division::create(['name' => 'Perencanaan Pengusahaan', 'slug' => 'perencanaan-pengusahaan']);

        $stafManset = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $manset->id]);
        $stafPengusahaan = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $pengusahaan->id]);

        $this->actingAs($stafManset)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Ringkasan Aset UIT JBT')
            ->assertSee('Stok Aset per UPT')
            ->assertDontSee('Status Dataset')
            ->assertDontSee('Prototipe Demo')
            ->assertDontSee('Ruang Kerja Divisi');

        $this->actingAs($stafPengusahaan)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Ringkasan Aset UIT JBT')
            ->assertDontSee('Stok Aset per UPT')
            ->assertDontSee('Prototipe Demo');
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
