<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Division;
use App\Models\Indicator;
use App\Models\OrgUnit;
use App\Models\PerformanceObservation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitDivisionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomChartDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(UnitDivisionSeeder::class);
    }

    public function test_custom_chart_data_endpoint_requires_auth_and_permission(): void
    {
        $response = $this->getJson(route('api.dashboard.custom-chart-data', [
            'metric' => 'nko_score',
            'chart_type' => 'bar',
        ]));

        $response->assertUnauthorized();
    }

    public function test_custom_chart_returns_scoped_metric_data(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $response = $this->actingAs($admin)->getJson(route('api.dashboard.custom-chart-data', [
            'metric' => 'nko_score',
            'chart_type' => 'bar',
        ]));

        $response->assertOk()
            ->assertJsonStructure([
                'metric',
                'chart_type',
                'series',
                'categories',
            ]);
    }

    public function test_custom_chart_rejects_unwhitelisted_metric_query(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $response = $this->actingAs($admin)->getJson(route('api.dashboard.custom-chart-data', [
            'metric' => 'unauthorized_column',
            'chart_type' => 'bar',
        ]));

        $response->assertStatus(422);
    }

    public function test_staff_without_division_is_fail_closed_and_returns_empty_data(): void
    {
        $division = Division::factory()->create();
        $dataset = Dataset::factory()->create(['status' => Dataset::STATUS_READY, 'division_id' => $division->id]);
        $unit = OrgUnit::factory()->create(['name' => 'TJBT']);
        $indicator = Indicator::factory()->create([
            'category' => 'nko',
            'is_group' => false,
            'org_unit_id' => $unit->id,
        ]);
        PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indicator->id,
            'org_unit_id' => $unit->id,
            'division_id' => $division->id,
            'period' => '2026-08-01',
            'nilai' => 105.0,
            'pencapaian' => 1.05,
        ]);

        $staffWithoutDivision = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => null,
        ]);

        $response = $this->actingAs($staffWithoutDivision)->getJson(route('api.dashboard.custom-chart-data', [
            'metric' => 'nko_score',
            'chart_type' => 'bar',
        ]));

        $response->assertOk()
            ->assertJson([
                'categories' => [],
                'series' => [],
            ]);

        $pencapaianResponse = $this->actingAs($staffWithoutDivision)->getJson(route('api.dashboard.custom-chart-data', [
            'metric' => 'pencapaian_ratio',
            'chart_type' => 'bar',
        ]));

        $pencapaianResponse->assertOk()
            ->assertJson([
                'categories' => [],
                'series' => [],
            ]);
    }

    public function test_scopes_kpi_units_filters_out_units_outside_visible_units_for_nko_and_pencapaian(): void
    {
        config(['kpi.visible_units' => ['TJBT']]);

        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $division = Division::factory()->create();
        $dataset = Dataset::factory()->create(['status' => Dataset::STATUS_READY, 'division_id' => $division->id]);

        $tjbt = OrgUnit::factory()->create(['name' => 'TJBT']);
        $tjbb = OrgUnit::factory()->create(['name' => 'TJBB']);

        $indNkoTjbt = Indicator::factory()->create(['category' => 'nko', 'is_group' => false, 'org_unit_id' => $tjbt->id]);
        $indNkoTjbb = Indicator::factory()->create(['category' => 'nko', 'is_group' => false, 'org_unit_id' => $tjbb->id]);

        PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indNkoTjbt->id,
            'org_unit_id' => $tjbt->id,
            'division_id' => $division->id,
            'period' => '2026-08-01',
            'nilai' => 102.5,
        ]);
        PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indNkoTjbb->id,
            'org_unit_id' => $tjbb->id,
            'division_id' => $division->id,
            'period' => '2026-08-01',
            'nilai' => 99.0,
        ]);

        $indLeafTjbt = Indicator::factory()->create(['category' => 'kinerja', 'is_group' => false, 'org_unit_id' => $tjbt->id]);
        $indLeafTjbb = Indicator::factory()->create(['category' => 'kinerja', 'is_group' => false, 'org_unit_id' => $tjbb->id]);

        PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indLeafTjbt->id,
            'org_unit_id' => $tjbt->id,
            'division_id' => $division->id,
            'period' => '2026-08-01',
            'pencapaian' => 1.05,
        ]);
        PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indLeafTjbb->id,
            'org_unit_id' => $tjbb->id,
            'division_id' => $division->id,
            'period' => '2026-08-01',
            'pencapaian' => 0.95,
        ]);

        $nkoResponse = $this->actingAs($admin)->getJson(route('api.dashboard.custom-chart-data', [
            'metric' => 'nko_score',
            'chart_type' => 'bar',
        ]));
        $nkoResponse->assertOk();
        $this->assertEquals(['TJBT'], $nkoResponse->json('categories'));
        $this->assertEquals([102.5], $nkoResponse->json('series.0.data'));

        $pencapaianResponse = $this->actingAs($admin)->getJson(route('api.dashboard.custom-chart-data', [
            'metric' => 'pencapaian_ratio',
            'chart_type' => 'bar',
        ]));
        $pencapaianResponse->assertOk();
        $this->assertEquals(['TJBT'], $pencapaianResponse->json('categories'));
        $this->assertEquals([105.0], $pencapaianResponse->json('series.0.data'));
    }

    public function test_pencapaian_ratio_ignores_group_indicators_normalizes_decimal_ratio_and_applies_cap(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $division = Division::factory()->create();
        $dataset = Dataset::factory()->create(['status' => Dataset::STATUS_READY, 'division_id' => $division->id]);

        $unit = OrgUnit::factory()->create(['name' => 'TJBT']);

        $indGroup = Indicator::factory()->create(['is_group' => true, 'org_unit_id' => $unit->id]);
        PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indGroup->id,
            'org_unit_id' => $unit->id,
            'division_id' => $division->id,
            'period' => '2026-08-01',
            'pencapaian' => 50.0,
        ]);

        $indLeaf1 = Indicator::factory()->create(['is_group' => false, 'org_unit_id' => $unit->id]);
        PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indLeaf1->id,
            'org_unit_id' => $unit->id,
            'division_id' => $division->id,
            'period' => '2026-08-01',
            'pencapaian' => 1.05,
        ]);

        $indLeaf2 = Indicator::factory()->create(['is_group' => false, 'org_unit_id' => $unit->id]);
        PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indLeaf2->id,
            'org_unit_id' => $unit->id,
            'division_id' => $division->id,
            'period' => '2026-08-01',
            'pencapaian' => 120.0,
        ]);

        $response = $this->actingAs($admin)->getJson(route('api.dashboard.custom-chart-data', [
            'metric' => 'pencapaian_ratio',
            'chart_type' => 'bar',
        ]));

        $response->assertOk();
        $this->assertEquals(['TJBT'], $response->json('categories'));
        $this->assertEquals([107.5], $response->json('series.0.data'));
    }
}
