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
            ->assertSee('id="kpi-detail-fullscreen-btn"', false)
            ->assertSee('id="kpi-detail-download-btn"', false)
            ->assertSee('id="kpi-detail-print-btn"', false)
            ->assertSee('Semua Berdampingan Compact')
            ->assertSee('Fokus Kartu Visual (Grid 4 Kolom)')
            ->assertSee('Fokus Tabel Matriks Padat')
            ->assertSee('ibs-card')
            ->assertSee('PLN_CHART_PALETTE')
            ->assertDontSee('font-mono')
            ->assertDontSee('—')
            ->assertDontSee('&mdash;', false)
            ->assertDontSee('&ndash;', false)
            ->assertDontSee('max-h-[410px]')
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

    public function test_kinerja_detail_does_not_double_normalize_percentage_scores_below_one_point_one_percent(): void
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

        $indicator = Indicator::create([
            'org_unit_id' => $unit->id,
            'name' => 'Indikator Kritis',
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
            'realisasi_value' => 0.8,
            'pencapaian' => 0.008,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard.kpi-detail'));

        $response->assertOk()
            ->assertSee('0,8%')
            ->assertSee('bg-rose-500')
            ->assertSee('style="width: 0.8%"', false)
            ->assertDontSee('style="width: 80%"', false);

        $rows = collect([
            [
                'unit' => 'TJBT',
                'indikator' => 'Indikator Uji Satu Persen',
                'target' => 100.0,
                'realisasi' => 1.0,
                'pencapaian' => 1.0,
                'satuan' => '%',
            ],
        ]);
        $widget = ['limit' => 10];
        $data = ['rows' => $rows, 'total_rows' => 1];

        $html = $this->actingAs($user)->blade(
            '<x-widgets.kinerja-detail :widget="$widget" :data="$data" />',
            ['widget' => $widget, 'data' => $data]
        );

        $html->assertSee('1%')
            ->assertSee('class="h-full rounded-full bg-rose-500" style="width: 1%"', false)
            ->assertDontSee('style="width: 100%"', false);
    }

    public function test_halaman_kpi_detail_menyediakan_workspace_grafik_layar_penuh_interaktif_dan_unduhan_png(): void
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

        $indTercapai = Indicator::create([
            'org_unit_id' => $unit->id,
            'name' => 'Keandalan Jaringan Utama',
            'satuan' => '%',
            'polarity' => 'higher_is_better',
            'bobot' => '15',
            'is_group' => false,
            'pencapaian_formula_kind' => 'polarity_ratio',
        ]);
        $indHampir = Indicator::create([
            'org_unit_id' => $unit->id,
            'name' => 'Susut Transmisi Regional',
            'satuan' => '%',
            'polarity' => 'lower_is_better',
            'bobot' => '10',
            'is_group' => false,
            'pencapaian_formula_kind' => 'polarity_ratio',
        ]);
        $indPerlu = Indicator::create([
            'org_unit_id' => $unit->id,
            'name' => 'Penyelesaian Proyek Strategis',
            'satuan' => 'Proyek',
            'polarity' => 'higher_is_better',
            'bobot' => '12',
            'is_group' => false,
            'pencapaian_formula_kind' => 'polarity_ratio',
        ]);

        PerformanceObservation::create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indTercapai->id,
            'org_unit_id' => $unit->id,
            'division_id' => $this->pengusahaan->id,
            'period' => '2026-08-31',
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'target_value' => 100.0,
            'realisasi_value' => 106.0,
            'pencapaian' => 1.06,
        ]);
        PerformanceObservation::create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indHampir->id,
            'org_unit_id' => $unit->id,
            'division_id' => $this->pengusahaan->id,
            'period' => '2026-08-31',
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'target_value' => 2.0,
            'realisasi_value' => 2.04,
            'pencapaian' => 0.98,
        ]);
        PerformanceObservation::create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indPerlu->id,
            'org_unit_id' => $unit->id,
            'division_id' => $this->pengusahaan->id,
            'period' => '2026-08-31',
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'target_value' => 10.0,
            'realisasi_value' => 8.0,
            'pencapaian' => 0.80,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard.kpi-detail'));

        $response->assertOk()
            ->assertSee('id="kpi-fullscreen-workspace"', false)
            ->assertSee('Visualisasi Grafik Capaian Indikator Kinerja')
            ->assertSee('id="kpi-fullscreen-echart-canvas"', false)
            ->assertSee('style="width: 100%; height: 500px; min-height: 440px;"', false)
            ->assertSee('id="kpi-fullscreen-download-chart-btn"', false)
            ->assertSee('id="kpi-fullscreen-close-btn"', false)
            ->assertSee('id="kpi-fullscreen-search"', false)
            ->assertSee('id="kpi-fullscreen-mode-achievement"', false)
            ->assertSee('id="kpi-fullscreen-mode-comparison"', false)
            ->assertSee('Perbandingan Target vs Realisasi')
            ->assertSee('data-fullscreen-rag="all"', false)
            ->assertSee('data-fullscreen-rag="tercapai"', false)
            ->assertSee('data-fullscreen-rag="hampir"', false)
            ->assertSee('data-fullscreen-rag="perlu"', false)
            ->assertSee('id="kpiFullscreenChartData"', false);

        $html = $response->getContent();
        $this->assertSame(
            1,
            preg_match('/<script type="application\/json" id="kpiFullscreenChartData">\s*(.*?)\s*<\/script>/s', $html, $matches),
        );

        $payload = json_decode($matches[1], true);
        $this->assertIsArray($payload);
        $this->assertSame('2026-08-31', $payload['period']);
        $this->assertCount(3, $payload['indicators']);

        $byName = collect($payload['indicators'])->keyBy('indikator');
        $this->assertSame('tercapai', $byName['Keandalan Jaringan Utama']['tier']);
        $this->assertSame('Positif', $byName['Keandalan Jaringan Utama']['polaritas']);
        $this->assertEqualsWithDelta(106.0, $byName['Keandalan Jaringan Utama']['pencapaian'], 0.1);

        $this->assertSame('hampir', $byName['Susut Transmisi Regional']['tier']);
        $this->assertSame('Negatif', $byName['Susut Transmisi Regional']['polaritas']);
        $this->assertEqualsWithDelta(98.0, $byName['Susut Transmisi Regional']['pencapaian'], 0.1);

        $this->assertSame('perlu', $byName['Penyelesaian Proyek Strategis']['tier']);
        $this->assertEqualsWithDelta(80.0, $byName['Penyelesaian Proyek Strategis']['pencapaian'], 0.1);
    }

    public function test_payload_grafik_layar_penuh_menjaga_indikator_tanggal_bebas_serial_excel_dan_mendukung_filter_unit(): void
    {
        config(['kpi.visible_units' => ['TJBT', 'UPT BANDUNG']]);

        $role = Role::where('slug', Role::MANAGEMENT)->firstOrFail();
        $user = User::factory()->create([
            'role_id' => $role->id,
            'division_id' => null,
        ]);

        $dataset = Dataset::factory()->create([
            'division_id' => $this->pengusahaan->id,
            'status' => Dataset::STATUS_READY,
        ]);

        $tjbt = OrgUnit::create(['name' => 'TJBT']);
        $bandung = OrgUnit::create(['name' => 'UPT BANDUNG']);

        $dateInd = Indicator::create([
            'org_unit_id' => $tjbt->id,
            'name' => 'Kualitas Data Material',
            'is_group' => false,
            'pencapaian_formula_kind' => 'date_delta',
        ]);
        $bandungInd = Indicator::create([
            'org_unit_id' => $bandung->id,
            'name' => 'Kinerja Pemeliharaan Bandung',
            'satuan' => '%',
            'is_group' => false,
            'pencapaian_formula_kind' => 'polarity_ratio',
        ]);

        PerformanceObservation::create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $dateInd->id,
            'org_unit_id' => $tjbt->id,
            'division_id' => $this->pengusahaan->id,
            'period' => '2026-08-31',
            'target_type' => PerformanceObservation::TYPE_DATE,
            'realisasi_type' => PerformanceObservation::TYPE_DATE,
            'target_value' => 46173,
            'realisasi_value' => 46163,
            'target_date' => '2026-05-31',
            'realisasi_date' => '2026-05-21',
            'pencapaian' => 1.0143,
        ]);
        PerformanceObservation::create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $bandungInd->id,
            'org_unit_id' => $bandung->id,
            'division_id' => $this->pengusahaan->id,
            'period' => '2026-08-31',
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'target_value' => 100.0,
            'realisasi_value' => 102.0,
            'pencapaian' => 1.02,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard.kpi-detail', ['unit' => 'TJBT']));
        $response->assertOk();

        preg_match('/<script type="application\/json" id="kpiFullscreenChartData">\s*(.*?)\s*<\/script>/s', $response->getContent(), $matches);
        $payload = json_decode($matches[1], true);

        $this->assertSame('TJBT', $payload['unitLabel']);
        $this->assertCount(1, $payload['indicators']);
        $this->assertSame('Kualitas Data Material', $payload['indicators'][0]['indikator']);
        $this->assertSame('date', $payload['indicators'][0]['mode']);
        $this->assertNull($payload['indicators'][0]['target_num']);
        $this->assertNull($payload['indicators'][0]['realisasi_num']);
        $this->assertSame('2026-05-31', $payload['indicators'][0]['target_display']);
        $this->assertSame('2026-05-21', $payload['indicators'][0]['realisasi_display']);
    }

    public function test_halaman_kpi_detail_memisahkan_dan_menyelaraskan_skor_kpi_dan_rasio_realisasi_murni(): void
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

        $attb = Indicator::create([
            'org_unit_id' => $unit->id,
            'name' => 'c. Usulan Penghapusan ATTB',
            'satuan' => 'Rp. Juta',
            'polarity' => 'higher_is_better',
            'is_group' => false,
            'pencapaian_formula_kind' => 'polarity_ratio',
        ]);

        $responseTime = Indicator::create([
            'org_unit_id' => $unit->id,
            'name' => 'Emergency Response time - Gangguan Meluas',
            'satuan' => 'Jam',
            'polarity' => 'lower_is_better',
            'is_group' => false,
            'pencapaian_formula_kind' => 'polarity_ratio',
        ]);

        PerformanceObservation::create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $attb->id,
            'org_unit_id' => $unit->id,
            'division_id' => $this->pengusahaan->id,
            'period' => '2026-08-31',
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'target_value' => 28795.3,
            'realisasi_value' => 181979.53,
            'pencapaian' => 1.1000,
        ]);

        PerformanceObservation::create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $responseTime->id,
            'org_unit_id' => $unit->id,
            'division_id' => $this->pengusahaan->id,
            'period' => '2026-08-31',
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'target_value' => 3.0,
            'realisasi_value' => 0.41,
            'pencapaian' => 1.1000,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard.kpi-detail'));

        $response->assertOk()
            ->assertSee('Skor Pencapaian KPI (Maks 110%)')
            ->assertSee('Rasio Realisasi Murni')
            ->assertSee('110%')
            ->assertSee('632%')
            ->assertSee('13,7%')
            ->assertSee("matches('Emergency Response time - Gangguan Meluas', 'TJBT', 'tercapai')", false)
            // Polaritas terbalik yang tercapai (110%) tidak boleh memakai bar merah palsu dari rasio murni 13,7%.
            ->assertDontSee('h-full rounded-full bg-rose-500');
    }

    public function test_halaman_kpi_detail_menyediakan_header_eksekutif_dua_tingkat_dan_filter_terpadu(): void
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
        $indicator = Indicator::create([
            'org_unit_id' => $unit->id,
            'name' => 'Keandalan Transmisi Utama',
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
            'realisasi_value' => 102.0,
            'pencapaian' => 1.02,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard.kpi-detail'));

        $response->assertOk()
            ->assertSee('id="kpi-detail-header"', false)
            ->assertSee('id="kpi-command-bar"', false)
            ->assertSee('id="kpi-unified-search"', false)
            ->assertSee('kpi-page-filter-changed', false)
            ->assertSee('Ringkasan')
            ->assertSee('Analitik KPI')
            ->assertSee('Unduh CSV')
            ->assertSee('Cetak Laporan')
            ->assertSee('Berdampingan')
            ->assertSee('Kartu Visual')
            ->assertSee('Tabel Matriks')
            ->assertSee('lg:col-span-5', false)
            ->assertSee('lg:col-span-7', false)
            ->assertDontSee('Mode: <span', false);
    }

    public function test_studio_presentasi_layar_penuh_menyajikan_layout_boardroom_dan_mode_perbandingan_ternormalisasi(): void
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
        $indicator = Indicator::create([
            'org_unit_id' => $unit->id,
            'name' => 'a. Faktor Ketersediaan Trafo (Trafo Availability)',
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
            'target_value' => 99.92,
            'realisasi_value' => 99.99,
            'pencapaian' => 1.0007,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard.kpi-detail'));

        $response->assertOk()
            ->assertSee('Executive Presentation Studio')
            ->assertSee('Ringkasan Kinerja per Indikator')
            ->assertSee('xl:col-span-8', false)
            ->assertSee('xl:col-span-4', false)
            ->assertSee('Perbandingan Target vs Realisasi (Skala Ternormalisasi %)')
            ->assertSee('Target (Acuan 100%)')
            ->assertSee('formatChartIndicatorName', false)
            ->assertSee('rows.length > 20', false)
            ->assertDontSee('rows.length > 12', false)
            ->assertDontSee('font-mono')
            ->assertDontSee('monospace')
            ->assertDontSee('&mdash;', false)
            ->assertDontSee('&ndash;', false);
    }
}
