<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Division;
use App\Models\Indicator;
use App\Models\OrgUnit;
use App\Models\PerformanceMetric;
use App\Models\PerformanceObservation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatasetStatusWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_dataset_status_widget_for_asset_division_counts_active_metrics_and_hides_tjbt_label(): void
    {
        config(['kpi.read_model' => 'structured']);
        config(['kpi.visible_units' => ['TJBT']]);

        $division = Division::factory()->create([
            'name' => 'Managemen Aset',
            'slug' => 'managemen-aset',
        ]);

        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $division->id,
        ]);

        $dataset = Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_READY,
        ]);

        for ($i = 1; $i <= 5; $i++) {
            PerformanceMetric::factory()->create([
                'dataset_id' => $dataset->id,
                'division_id' => $division->id,
                'metric_name' => "Total Aset Trafo {$i}",
                'value' => 100.0 * $i,
                'period' => '2026-08-15',
            ]);
        }

        config(['dashboard_layouts.layouts.staff_divisi:perencanaan-pengusahaan' => ['dataset-status']]);

        $response = $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertOk();

        $response->assertSee('Status Dataset');
        $response->assertSee('Baris Data Aktif');
        $response->assertDontSee('Baris Data Aktif (TJBT)');
        $response->assertSeeInOrder([
            'Baris Data Aktif',
            '5',
        ]);
    }

    public function test_dataset_status_widget_for_kpi_division_counts_observations_and_shows_visible_unit_label(): void
    {
        config(['kpi.read_model' => 'structured']);
        config(['kpi.visible_units' => ['TJBT']]);

        $division = Division::factory()->create([
            'name' => 'Perencanaan Pengusahaan',
            'slug' => 'perencanaan-pengusahaan',
        ]);

        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $division->id,
        ]);

        $dataset = Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_READY,
        ]);

        $unit = OrgUnit::create(['name' => 'TJBT']);

        for ($i = 1; $i <= 4; $i++) {
            $indicator = Indicator::create([
                'org_unit_id' => $unit->id,
                'name' => "Indikator KPI {$i}",
                'satuan' => '%',
                'is_group' => false,
            ]);

            PerformanceObservation::create([
                'dataset_id' => $dataset->id,
                'indicator_id' => $indicator->id,
                'org_unit_id' => $unit->id,
                'division_id' => $division->id,
                'period' => '2026-08-31',
                'target_type' => PerformanceObservation::TYPE_NUMERIC,
                'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
                'target_value' => 100.0,
                'realisasi_value' => 100.0,
                'pencapaian' => 100.0,
            ]);
        }

        config(['dashboard_layouts.layouts.staff_divisi:perencanaan-pengusahaan' => ['dataset-status']]);

        $response = $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertOk();

        $response->assertSee('Status Dataset');
        $response->assertSee('Baris Data Aktif (TJBT)');
        $response->assertSeeInOrder([
            'Baris Data Aktif (TJBT)',
            '4',
        ]);
    }

    public function test_dataset_status_handles_division_with_no_datasets_cleanly_without_null_pointer(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = Division::factory()->create([
            'name' => 'Divisi Tanpa Data',
            'slug' => 'divisi-tanpa-data',
        ]);

        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $division->id,
        ]);

        config(['dashboard_layouts.layouts.staff_divisi:perencanaan-pengusahaan' => ['dataset-status']]);

        $response = $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertOk();

        $response->assertSee('Status Dataset');
        $response->assertSee('Belum ada data KPI aktif.');
    }

    public function test_dataset_status_counts_metrics_across_different_dates_in_same_month(): void
    {
        config(['kpi.read_model' => 'structured']);
        config(['kpi.visible_units' => ['TJBT']]);

        $division = Division::factory()->create([
            'name' => 'Managemen Aset',
            'slug' => 'managemen-aset',
        ]);

        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $division->id,
        ]);

        $dataset = Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_READY,
        ]);

        // Dua tanggal berbeda dalam bulan yang sama (Agustus 2026)
        PerformanceMetric::factory()->create([
            'dataset_id' => $dataset->id,
            'division_id' => $division->id,
            'metric_name' => 'Aset Awal Bulan',
            'value' => 10.0,
            'period' => '2026-08-05',
        ]);

        PerformanceMetric::factory()->create([
            'dataset_id' => $dataset->id,
            'division_id' => $division->id,
            'metric_name' => 'Aset Akhir Bulan',
            'value' => 20.0,
            'period' => '2026-08-25',
        ]);

        config(['dashboard_layouts.layouts.staff_divisi:perencanaan-pengusahaan' => ['dataset-status']]);

        $response = $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertOk();

        $response->assertSee('Status Dataset');
        $response->assertSee('Baris Data Aktif');
        $response->assertDontSee('Baris Data Aktif (TJBT)');

        $data = collect($response->viewData('widgets'))->firstWhere('key', 'dataset-status')['data'];
        $this->assertSame(2, $data['latest_rows']);
    }

    public function test_dataset_status_ignores_archived_and_failed_datasets_when_counting_active_rows(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = Division::factory()->create([
            'name' => 'Managemen Aset',
            'slug' => 'managemen-aset',
        ]);

        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $division->id,
        ]);

        $readyDataset = Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_READY,
        ]);

        $archivedDataset = Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_ARCHIVED,
        ]);

        $failedDataset = Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_FAILED,
        ]);

        PerformanceMetric::factory()->create([
            'dataset_id' => $readyDataset->id,
            'division_id' => $division->id,
            'metric_name' => 'Aset Aktif',
            'value' => 50.0,
            'period' => '2026-08-31',
        ]);

        PerformanceMetric::factory()->create([
            'dataset_id' => $archivedDataset->id,
            'division_id' => $division->id,
            'metric_name' => 'Aset Lama',
            'value' => 50.0,
            'period' => '2026-08-31',
        ]);

        PerformanceMetric::factory()->create([
            'dataset_id' => $failedDataset->id,
            'division_id' => $division->id,
            'metric_name' => 'Aset Rusak',
            'value' => 50.0,
            'period' => '2026-08-31',
        ]);

        config(['dashboard_layouts.layouts.staff_divisi:perencanaan-pengusahaan' => ['dataset-status']]);

        $response = $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertOk();

        $response->assertSee('Status Dataset');
        $response->assertSeeInOrder([
            'Dataset Berhasil (Divisi)',
            '1',
            'Dataset Diarsipkan',
            '1',
            'Dataset Gagal',
            '1',
            'Baris Data Aktif',
            '1',
        ]);
    }

    public function test_dataset_status_blade_component_renders_non_kpi_label_when_widget_flag_set(): void
    {
        $widgetNonKpi = ['is_non_kpi' => true];
        $widgetKpi = ['is_non_kpi' => false];
        $data = [
            'latest_period' => '2026-08-31',
            'ready' => 1,
            'archived' => 0,
            'failed' => 0,
            'latest_rows' => 437,
            'fresh' => true,
        ];

        config(['kpi.visible_units' => ['TJBT']]);

        $htmlNonKpi = $this->blade(
            '<x-widgets.dataset-status :widget="$widget" :data="$data" />',
            ['widget' => $widgetNonKpi, 'data' => $data]
        );

        $htmlNonKpi->assertSee('Baris Data Aktif')
            ->assertDontSee('Baris Data Aktif (TJBT)')
            ->assertSee('437');

        $htmlKpi = $this->blade(
            '<x-widgets.dataset-status :widget="$widget" :data="$data" />',
            ['widget' => $widgetKpi, 'data' => $data]
        );

        $htmlKpi->assertSee('Baris Data Aktif (TJBT)')
            ->assertSee('437');
    }
}
