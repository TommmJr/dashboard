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

/**
 * Test kontrak endpoint GET /api/performance-data (Tahap 7 parsial):
 * series per divisi, KPI card, dan eksklusi dataset ARCHIVED.
 */
class PerformanceChartDataTest extends TestCase
{
    use RefreshDatabase;

    private Division $divA;

    private Division $divB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->divA = Division::factory()->create(['name' => 'Divisi A']);
        $this->divB = Division::factory()->create(['name' => 'Divisi B']);
    }

    private function datasetWithMetric(Division $division, string $status, string $period, float $value): Dataset
    {
        $dataset = Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => $status,
        ]);
        PerformanceMetric::factory()->create([
            'dataset_id' => $dataset->id,
            'division_id' => $division->id,
            'period' => $period,
            'value' => $value,
        ]);

        return $dataset;
    }

    public function test_kontrak_json_berisi_labels_series_kpi(): void
    {
        $this->datasetWithMetric($this->divA, Dataset::STATUS_READY, '2026-01-31', 100);
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $response = $this->actingAs($admin)->getJson(route('performance.data'))->assertOk();

        $response->assertJsonStructure([
            'labels',
            'series' => [['name', 'data']],
            'kpi' => ['total_value', 'ready_datasets', 'metric_count'],
        ]);
        $this->assertSame(['2026-01-31'], $response->json('labels'));
        $this->assertSame('Divisi A', $response->json('series.0.name'));
        $this->assertEquals(100.0, (float) $response->json('series.0.data.0'));
        $this->assertEquals(100.0, (float) $response->json('kpi.total_value'));
        $this->assertSame(1, $response->json('kpi.ready_datasets'));
        $this->assertSame(1, $response->json('kpi.metric_count'));
    }

    public function test_series_per_divisi_dengan_padding_nol(): void
    {
        // Divisi A punya data di Jan & Feb; Divisi B hanya di Feb.
        // Series A harus [100, 200], series B harus [0, 999] (padding nol).
        $this->datasetWithMetric($this->divA, Dataset::STATUS_READY, '2026-01-31', 100);
        $this->datasetWithMetric($this->divA, Dataset::STATUS_READY, '2026-02-28', 200);
        $this->datasetWithMetric($this->divB, Dataset::STATUS_READY, '2026-02-28', 999);

        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $response = $this->actingAs($admin)->getJson(route('performance.data'))->assertOk();

        $this->assertSame(['2026-01-31', '2026-02-28'], $response->json('labels'));

        $series = collect($response->json('series'))->keyBy('name');
        $this->assertEquals([100.0, 200.0], array_map('floatval', $series['Divisi A']['data']));
        $this->assertEquals([0.0, 999.0], array_map('floatval', $series['Divisi B']['data']));
    }

    public function test_dataset_archived_dikeluarkan_dari_series_dan_kpi(): void
    {
        $ready = $this->datasetWithMetric($this->divA, Dataset::STATUS_READY, '2026-01-31', 100);
        $archived = $this->datasetWithMetric($this->divA, Dataset::STATUS_READY, '2026-02-28', 500);
        $archived->update(['status' => Dataset::STATUS_ARCHIVED]);

        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $response = $this->actingAs($admin)->getJson(route('performance.data'))->assertOk();

        $this->assertSame(['2026-01-31'], $response->json('labels'));
        $this->assertEquals(100.0, (float) $response->json('kpi.total_value'));
        $this->assertSame(1, $response->json('kpi.ready_datasets'));
        $this->assertSame(1, $response->json('kpi.metric_count'));
    }

    public function test_staf_hanya_melihat_series_divisinya(): void
    {
        $this->datasetWithMetric($this->divA, Dataset::STATUS_READY, '2026-01-31', 100);
        $this->datasetWithMetric($this->divB, Dataset::STATUS_READY, '2026-01-31', 999);

        $staffA = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $this->divA->id]);
        $response = $this->actingAs($staffA)->getJson(route('performance.data'))->assertOk();

        $seriesNames = collect($response->json('series'))->pluck('name');
        $this->assertContains('Divisi A', $seriesNames);
        $this->assertNotContains('Divisi B', $seriesNames);
        $this->assertEquals(100.0, (float) $response->json('kpi.total_value'));
    }

    public function test_dashboard_view_memuat_elemen_chart(): void
    {
        $user = User::factory()->withRole(Role::MANAGEMENT)->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="performanceChart"', false)
            ->assertSee(route('performance.data'), false);
    }
}
