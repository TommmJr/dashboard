<?php

namespace Tests\Feature;

use App\Http\Controllers\DashboardController;
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

class KpiDetailPageTest extends TestCase
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

    public function test_guest_diarahkan_ke_login_saat_mengakses_kpi_detail(): void
    {
        $this->get('/dashboard/kpi-detail')
            ->assertRedirect(route('login'));
    }

    public function test_staf_perencanaan_pengusahaan_dapat_mengakses_halaman_kpi_detail_dan_melihat_semua_baris_tanpa_potongan_10(): void
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

        for ($i = 1; $i <= 12; $i++) {
            $indicator = Indicator::create([
                'org_unit_id' => $unit->id,
                'name' => sprintf('Indikator Uji %02d', $i),
                'satuan' => '%',
                'is_group' => false,
                'pencapaian_formula_kind' => 'polarity_ratio',
            ]);

            PerformanceObservation::create([
                'dataset_id' => $dataset->id,
                'indicator_id' => $indicator->id,
                'org_unit_id' => $unit->id,
                'division_id' => $this->pengusahaan->id,
                'period' => '2026-08-31',
                'target_type' => PerformanceObservation::TYPE_NUMERIC,
                'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
                'target_value' => 100.0,
                'realisasi_value' => 105.0,
                'pencapaian' => 1.05,
            ]);
        }

        $response = $this->actingAs($user)->get(route('dashboard.kpi-detail'));

        $response->assertOk()
            ->assertSee('Rincian Kinerja KPI')
            ->assertSee('Target dan Realisasi per Indikator')
            ->assertSee('Detail Metrik KPI')
            ->assertSee('Indikator Uji 01')
            ->assertSee('Indikator Uji 12')
            ->assertSee('Menampilkan 12 dari 12 baris.')
            ->assertSee('id="kinerja-detail-unit"', false)
            ->assertDontSee('Lihat semua (12 baris)');
    }

    public function test_staf_divisi_non_pengusahaan_ditolak_403_saat_mengakses_kpi_detail(): void
    {
        $role = Role::where('slug', Role::STAFF_DIVISI)->firstOrFail();
        $user = User::factory()->create([
            'role_id' => $role->id,
            'division_id' => $this->aset->id,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.kpi-detail'))
            ->assertForbidden();
    }

    public function test_filter_tanggal_rusak_atau_terbalik_diabaikan_dengan_aman(): void
    {
        $role = Role::where('slug', Role::MANAGEMENT)->firstOrFail();
        $user = User::factory()->create([
            'role_id' => $role->id,
            'division_id' => null,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.kpi-detail', [
                'start_date' => '2026-12-31',
                'end_date' => '2026-01-01',
            ]))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('dashboard.kpi-detail', [
                'start_date' => 'bukan-tanggal',
                'end_date' => '2026-99-99',
            ]))
            ->assertOk();
    }

    public function test_sidebar_menampilkan_link_rincian_kpi_untuk_staf_pengusahaan_dan_menyembunyikannya_untuk_staf_aset(): void
    {
        $role = Role::where('slug', Role::STAFF_DIVISI)->firstOrFail();

        $stafPengusahaan = User::factory()->create([
            'role_id' => $role->id,
            'division_id' => $this->pengusahaan->id,
        ]);

        $stafAset = User::factory()->create([
            'role_id' => $role->id,
            'division_id' => $this->aset->id,
        ]);

        $this->actingAs($stafPengusahaan)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="sidebar-kpi-detail-link"', false)
            ->assertSee('Rincian KPI');

        $this->actingAs($stafAset)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('id="sidebar-kpi-detail-link"', false);
    }

    public function test_widget_kinerja_detail_di_dashboard_utama_memiliki_tautan_ke_halaman_rincian_kpi(): void
    {
        $role = Role::where('slug', Role::STAFF_DIVISI)->firstOrFail();
        $stafPengusahaan = User::factory()->create([
            'role_id' => $role->id,
            'division_id' => $this->pengusahaan->id,
        ]);

        $dataset = Dataset::factory()->create([
            'division_id' => $this->pengusahaan->id,
            'status' => Dataset::STATUS_READY,
        ]);

        $unit = OrgUnit::create(['name' => 'TJBT']);
        $indicator = Indicator::create([
            'org_unit_id' => $unit->id,
            'name' => 'Indikator Pintas',
            'satuan' => '%',
            'is_group' => false,
            'pencapaian_formula_kind' => 'polarity_ratio',
        ]);

        PerformanceObservation::create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indicator->id,
            'org_unit_id' => $unit->id,
            'division_id' => $this->pengusahaan->id,
            'period' => '2026-08-31',
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'target_value' => 100.0,
            'realisasi_value' => 100.0,
            'pencapaian' => 1.0,
        ]);

        $this->actingAs($stafPengusahaan)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('dashboard.kpi-detail'), false);
    }

    public function test_admin_ditolak_403_saat_mengakses_kpi_detail_dan_sidebar_link_tidak_tampil(): void
    {
        $role = Role::where('slug', Role::ADMIN)->firstOrFail();
        $admin = User::factory()->create([
            'role_id' => $role->id,
            'division_id' => null,
        ]);

        $this->assertFalse(DashboardController::canAccessKpiDetail(null));
        $this->assertFalse(DashboardController::canAccessKpiDetail($admin));

        $adminWithDivision = User::factory()->create([
            'role_id' => $role->id,
            'division_id' => $this->pengusahaan->id,
        ]);
        $this->assertFalse(DashboardController::canAccessKpiDetail($adminWithDivision));

        $userWithoutRole = User::factory()->create([
            'role_id' => null,
            'division_id' => null,
        ]);
        $this->assertFalse(DashboardController::canAccessKpiDetail($userWithoutRole));

        $this->actingAs($admin)
            ->get(route('dashboard.kpi-detail'))
            ->assertForbidden();

        $this->actingAs($adminWithDivision)
            ->get(route('dashboard.kpi-detail'))
            ->assertForbidden();

        $this->actingAs($userWithoutRole)
            ->get(route('dashboard.kpi-detail'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('id="sidebar-kpi-detail-link"', false)
            ->assertDontSee(route('dashboard.kpi-detail'), false)
            ->assertDontSee('Rincian KPI');
    }

    public function test_management_dan_editor_dashboard_dapat_mengakses_kpi_detail_dan_melihat_sidebar_link(): void
    {
        $managementRole = Role::where('slug', Role::MANAGEMENT)->firstOrFail();
        $managementUser = User::factory()->create([
            'role_id' => $managementRole->id,
            'division_id' => null,
        ]);

        $editorRole = Role::where('slug', Role::EDITOR_DASHBOARD)->firstOrFail();
        $editorUser = User::factory()->create([
            'role_id' => $editorRole->id,
            'division_id' => null,
        ]);

        $this->assertTrue(DashboardController::canAccessKpiDetail($managementUser));
        $this->assertTrue(DashboardController::canAccessKpiDetail($editorUser));

        $this->actingAs($managementUser)
            ->get(route('dashboard.kpi-detail'))
            ->assertOk();

        $this->actingAs($managementUser)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="sidebar-kpi-detail-link"', false)
            ->assertSee('Rincian KPI');

        $this->actingAs($editorUser)
            ->get(route('dashboard.kpi-detail'))
            ->assertOk();

        $this->actingAs($editorUser)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="sidebar-kpi-detail-link"', false)
            ->assertSee('Rincian KPI');
    }

    public function test_widget_kinerja_detail_hanya_menampilkan_tautan_rincian_kpi_untuk_pengguna_yang_berhak(): void
    {
        $adminRole = Role::where('slug', Role::ADMIN)->firstOrFail();
        $admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'division_id' => null,
        ]);

        $managementRole = Role::where('slug', Role::MANAGEMENT)->firstOrFail();
        $managementUser = User::factory()->create([
            'role_id' => $managementRole->id,
            'division_id' => null,
        ]);

        $editorRole = Role::where('slug', Role::EDITOR_DASHBOARD)->firstOrFail();
        $editorUser = User::factory()->create([
            'role_id' => $editorRole->id,
            'division_id' => null,
        ]);

        $stafRole = Role::where('slug', Role::STAFF_DIVISI)->firstOrFail();
        $stafPengusahaan = User::factory()->create([
            'role_id' => $stafRole->id,
            'division_id' => $this->pengusahaan->id,
        ]);

        $stafAset = User::factory()->create([
            'role_id' => $stafRole->id,
            'division_id' => $this->aset->id,
        ]);

        $rows = collect(range(1, 15))->map(fn ($i) => [
            'unit' => 'TJBT',
            'indikator' => "Indikator $i",
            'target' => 100.0,
            'realisasi' => 100.0,
            'pencapaian' => 1.0,
            'satuan' => '%',
            'target_raw' => 100.0,
            'realisasi_raw' => 100.0,
            'target_type' => 'numeric',
            'realisasi_type' => 'numeric',
        ]);

        $widget = ['limit' => 10];
        $data = ['rows' => $rows, 'total_rows' => 15];

        $htmlPengusahaan = $this->actingAs($stafPengusahaan)->blade(
            '<x-widgets.kinerja-detail :widget="$widget" :data="$data" />',
            ['widget' => $widget, 'data' => $data]
        );
        $htmlPengusahaan->assertSee('Menampilkan 10 baris teratas.')
            ->assertSee('Halaman Rincian KPI')
            ->assertSee(route('dashboard.kpi-detail'), false);

        $htmlManagement = $this->actingAs($managementUser)->blade(
            '<x-widgets.kinerja-detail :widget="$widget" :data="$data" />',
            ['widget' => $widget, 'data' => $data]
        );
        $htmlManagement->assertSee('Menampilkan 10 baris teratas.')
            ->assertSee('Halaman Rincian KPI')
            ->assertSee(route('dashboard.kpi-detail'), false);

        $htmlEditor = $this->actingAs($editorUser)->blade(
            '<x-widgets.kinerja-detail :widget="$widget" :data="$data" />',
            ['widget' => $widget, 'data' => $data]
        );
        $htmlEditor->assertSee('Menampilkan 10 baris teratas.')
            ->assertSee('Halaman Rincian KPI')
            ->assertSee(route('dashboard.kpi-detail'), false);

        $htmlAdmin = $this->actingAs($admin)->blade(
            '<x-widgets.kinerja-detail :widget="$widget" :data="$data" />',
            ['widget' => $widget, 'data' => $data]
        );
        $htmlAdmin->assertSee('Menampilkan 10 baris teratas.')
            ->assertDontSee('Halaman Rincian KPI')
            ->assertDontSee(route('dashboard.kpi-detail'), false);

        $htmlAset = $this->actingAs($stafAset)->blade(
            '<x-widgets.kinerja-detail :widget="$widget" :data="$data" />',
            ['widget' => $widget, 'data' => $data]
        );
        $htmlAset->assertSee('Menampilkan 10 baris teratas.')
            ->assertDontSee('Halaman Rincian KPI')
            ->assertDontSee(route('dashboard.kpi-detail'), false);

        // Uji kondisi data tidak terpotong (rows <= 10): tautan bawah tetap mengikuti otorisasi
        $shortRows = $rows->take(5);
        $shortData = ['rows' => $shortRows, 'total_rows' => 5];

        $shortHtmlPengusahaan = $this->actingAs($stafPengusahaan)->blade(
            '<x-widgets.kinerja-detail :widget="$widget" :data="$data" />',
            ['widget' => $widget, 'data' => $shortData]
        );
        $shortHtmlPengusahaan->assertDontSee('Menampilkan 10 baris teratas.')
            ->assertSee('Halaman Rincian KPI &rarr;', false)
            ->assertSee(route('dashboard.kpi-detail'), false);

        $shortHtmlAdmin = $this->actingAs($admin)->blade(
            '<x-widgets.kinerja-detail :widget="$widget" :data="$data" />',
            ['widget' => $widget, 'data' => $shortData]
        );
        $shortHtmlAdmin->assertDontSee('Menampilkan 10 baris teratas.')
            ->assertDontSee('Halaman Rincian KPI')
            ->assertDontSee(route('dashboard.kpi-detail'), false);
    }
}
