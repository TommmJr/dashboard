<?php

namespace Tests\Feature;

use App\Http\Controllers\KpiAnalyticsController;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\Indicator;
use App\Models\OrgUnit;
use App\Models\PerformanceObservation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KpiAnalyticsPageTest extends TestCase
{
    use RefreshDatabase;

    private Division $pengusahaan;

    private Division $aset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->pengusahaan = Division::create([
            'name' => 'Perencanaan Pengusahaan',
            'slug' => 'perencanaan-pengusahaan',
        ]);

        $this->aset = Division::create([
            'name' => 'Managemen Aset',
            'slug' => 'managemen-aset',
        ]);
    }

    public function test_guest_diarahkan_ke_login_saat_mengakses_kpi_analytics(): void
    {
        $this->get('/dashboard/kpi-analytics')
            ->assertRedirect(route('login'));

        $this->get('/dashboard/kpi-matrix')
            ->assertRedirect(route('login'));
    }

    public function test_staf_perencanaan_pengusahaan_dapat_mengakses_kpi_analytics_dan_melihat_komponen_lengkap(): void
    {
        $role = Role::where('slug', Role::STAFF_DIVISI)->firstOrFail();
        $user = User::factory()->create([
            'role_id' => $role->id,
            'division_id' => $this->pengusahaan->id,
        ]);

        $dataset = Dataset::factory()->create([
            'division_id' => $this->pengusahaan->id,
            'status' => Dataset::STATUS_READY,
        ]);

        $unit = OrgUnit::create(['name' => 'TJBT']);

        // Indikator 1: Kritis (< 95%)
        $ind1 = Indicator::create([
            'org_unit_id' => $unit->id,
            'name' => 'Kesiapan Pembangkit Trafo',
            'satuan' => '%',
            'is_group' => false,
            'pencapaian_formula_kind' => 'polarity_ratio',
        ]);
        PerformanceObservation::create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $ind1->id,
            'org_unit_id' => $unit->id,
            'division_id' => $this->pengusahaan->id,
            'period' => '2026-08-31',
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'target_value' => 100.0,
            'realisasi_value' => 88.0,
            'pencapaian' => 0.88,
        ]);

        // Indikator 2: Tercapai (>= 100%)
        $ind2 = Indicator::create([
            'org_unit_id' => $unit->id,
            'name' => 'Keandalan Jaringan Transmisi',
            'satuan' => '%',
            'is_group' => false,
            'pencapaian_formula_kind' => 'polarity_ratio',
        ]);
        PerformanceObservation::create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $ind2->id,
            'org_unit_id' => $unit->id,
            'division_id' => $this->pengusahaan->id,
            'period' => '2026-08-31',
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'target_value' => 100.0,
            'realisasi_value' => 104.5,
            'pencapaian' => 1.045,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard.kpi-analytics'));

        $response->assertOk()
            ->assertSee('Analitik & Bottleneck KPI', false)
            ->assertSee('id="kpi-analytics-header"', false)
            ->assertSee('Skor NKO Aktif')
            ->assertSee('Indikator Tercapai')
            ->assertSee('Rata-rata Pencapaian')
            ->assertSee('Indikator Bottleneck')
            ->assertSee('Bottleneck Finder &mdash; Indikator Prioritas Perbaikan', false)
            ->assertSee('Kesiapan Pembangkit Trafo')
            ->assertSee('Keandalan Jaringan Transmisi')
            ->assertSee('id="kpi-bullet-chart"', false)
            ->assertSee('id="kpi-distribution-chart"', false)
            ->assertSee('id="kpiAnalyticsChartData"', false)
            ->assertSee('style="width: 100%; height: 360px;"', false);
    }

    public function test_alias_route_kpi_matrix_berfungsi_dan_mengarah_ke_halaman_analitik(): void
    {
        $role = Role::where('slug', Role::STAFF_DIVISI)->firstOrFail();
        $user = User::factory()->create([
            'role_id' => $role->id,
            'division_id' => $this->pengusahaan->id,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.kpi-matrix'))
            ->assertOk()
            ->assertSee('Analitik & Bottleneck KPI', false);
    }

    public function test_admin_dan_management_dapat_mengakses_kpi_analytics(): void
    {
        $adminRole = Role::where('slug', Role::ADMIN)->firstOrFail();
        $admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'division_id' => null,
        ]);

        $managementRole = Role::where('slug', Role::MANAGEMENT)->firstOrFail();
        $management = User::factory()->create([
            'role_id' => $managementRole->id,
            'division_id' => null,
        ]);

        $this->assertTrue(KpiAnalyticsController::canAccessKpiAnalytics($admin));
        $this->assertTrue(KpiAnalyticsController::canAccessKpiAnalytics($management));

        $this->actingAs($admin)
            ->get(route('dashboard.kpi-analytics'))
            ->assertOk();

        $this->actingAs($management)
            ->get(route('dashboard.kpi-analytics'))
            ->assertOk();
    }

    public function test_staf_divisi_aset_dan_editor_dashboard_ditolak_403_fail_closed(): void
    {
        $staffRole = Role::where('slug', Role::STAFF_DIVISI)->firstOrFail();
        $stafAset = User::factory()->create([
            'role_id' => $staffRole->id,
            'division_id' => $this->aset->id,
        ]);

        $editorRole = Role::where('slug', Role::EDITOR_DASHBOARD)->firstOrFail();
        $editor = User::factory()->create([
            'role_id' => $editorRole->id,
            'division_id' => null,
        ]);

        $userTanpaRole = User::factory()->create([
            'role_id' => null,
            'division_id' => null,
        ]);

        $this->assertFalse(KpiAnalyticsController::canAccessKpiAnalytics(null));
        $this->assertFalse(KpiAnalyticsController::canAccessKpiAnalytics($stafAset));
        $this->assertFalse(KpiAnalyticsController::canAccessKpiAnalytics($editor));
        $this->assertFalse(KpiAnalyticsController::canAccessKpiAnalytics($userTanpaRole));

        $this->actingAs($stafAset)
            ->get(route('dashboard.kpi-analytics'))
            ->assertForbidden();

        $this->actingAs($editor)
            ->get(route('dashboard.kpi-analytics'))
            ->assertForbidden();

        $this->actingAs($userTanpaRole)
            ->get(route('dashboard.kpi-analytics'))
            ->assertForbidden();
    }

    public function test_sidebar_menampilkan_link_analitik_kpi_untuk_staf_pengusahaan_admin_management_dan_menyembunyikannya_untuk_staf_aset(): void
    {
        $staffRole = Role::where('slug', Role::STAFF_DIVISI)->firstOrFail();
        $adminRole = Role::where('slug', Role::ADMIN)->firstOrFail();
        $managementRole = Role::where('slug', Role::MANAGEMENT)->firstOrFail();

        $stafPengusahaan = User::factory()->create([
            'role_id' => $staffRole->id,
            'division_id' => $this->pengusahaan->id,
        ]);

        $admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'division_id' => null,
        ]);

        $management = User::factory()->create([
            'role_id' => $managementRole->id,
            'division_id' => null,
        ]);

        $stafAset = User::factory()->create([
            'role_id' => $staffRole->id,
            'division_id' => $this->aset->id,
        ]);

        // Staf Pengusahaan melihat link di dashboard
        $this->actingAs($stafPengusahaan)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="sidebar-kpi-analytics-link"', false)
            ->assertSee('Analitik & Bottleneck KPI', false);

        // Admin melihat link di dashboard
        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="sidebar-kpi-analytics-link"', false);

        // Management melihat link di dashboard
        $this->actingAs($management)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="sidebar-kpi-analytics-link"', false);

        // Staf Aset TIDAK melihat link di dashboard
        $this->actingAs($stafAset)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('id="sidebar-kpi-analytics-link"', false);
    }

    public function test_filter_tanggal_dapat_diterapkan_dengan_aman(): void
    {
        $role = Role::where('slug', Role::STAFF_DIVISI)->firstOrFail();
        $user = User::factory()->create([
            'role_id' => $role->id,
            'division_id' => $this->pengusahaan->id,
        ]);

        // Rentang terbalik
        $this->actingAs($user)
            ->get(route('dashboard.kpi-analytics', [
                'start_date' => '2026-12-31',
                'end_date' => '2026-01-01',
            ]))
            ->assertOk();

        // Nilai tidak valid
        $this->actingAs($user)
            ->get(route('dashboard.kpi-analytics', [
                'start_date' => 'format-salah',
                'end_date' => 'bukan-tanggal',
            ]))
            ->assertOk();
    }
}
