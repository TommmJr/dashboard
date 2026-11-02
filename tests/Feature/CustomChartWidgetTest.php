<?php

namespace Tests\Feature;

use App\Models\DashboardLayout;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitDivisionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomChartWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(UnitDivisionSeeder::class);
    }

    public function test_renders_custom_chart_widget_with_custom_title(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        DashboardLayout::create([
            'layout_key' => Role::ADMIN,
            'role_slug' => Role::ADMIN,
            'widgets' => [
                [
                    'key' => 'custom-chart',
                    'cols' => 2,
                    'config' => [
                        'title' => 'Grafik Efisiensi Trafo',
                        'chart_type' => 'bar',
                        'metric' => 'nko_score',
                    ],
                ],
            ],
            'updated_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('Grafik Efisiensi Trafo')
            ->assertSee('custom-chart-container');
    }
}
