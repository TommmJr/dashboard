<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitDivisionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardWidgetValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(UnitDivisionSeeder::class);
    }

    public function test_rejects_invalid_widget_column_widths(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $response = $this->actingAs($admin)->putJson(route('dashboard.layout.update'), [
            'target' => Role::ADMIN,
            'widgets' => [
                ['key' => 'kpi-summary', 'cols' => 4],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['widgets.0.cols']);
    }

    public function test_rejects_unwhitelisted_metric_for_custom_chart(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $response = $this->actingAs($admin)->putJson(route('dashboard.layout.update'), [
            'target' => Role::ADMIN,
            'widgets' => [
                [
                    'key' => 'custom-chart',
                    'cols' => 2,
                    'config' => [
                        'title' => 'Grafik Uji',
                        'chart_type' => 'bar',
                        'metric' => 'invalid_metric_column',
                    ],
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['widgets.0.config.metric']);
    }

    public function test_accepts_valid_custom_chart_configuration(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $response = $this->actingAs($admin)->putJson(route('dashboard.layout.update'), [
            'target' => Role::ADMIN,
            'widgets' => [
                'layout-editor-placeholder',
                [
                    'key' => 'custom-chart',
                    'cols' => 2,
                    'config' => [
                        'title' => 'Distribusi Skor NKO',
                        'chart_type' => 'bar',
                        'metric' => 'nko_score',
                    ],
                ],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('dashboard_layouts', [
            'layout_key' => Role::ADMIN,
        ]);
    }
}
