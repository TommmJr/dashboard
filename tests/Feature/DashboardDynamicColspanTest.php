<?php

namespace Tests\Feature;

use App\Models\DashboardLayout;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitDivisionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardDynamicColspanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(UnitDivisionSeeder::class);
    }

    public function test_renders_dynamic_colspans_from_database_layout(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        DashboardLayout::create([
            'layout_key' => Role::ADMIN,
            'role_slug' => Role::ADMIN,
            'widgets' => [
                ['key' => 'nko-donut', 'cols' => 2, 'config' => []],
                ['key' => 'kpi-summary', 'cols' => 1, 'config' => []],
            ],
            'updated_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('lg:col-span-2');
        $response->assertSee('lg:col-span-1');
    }
}
