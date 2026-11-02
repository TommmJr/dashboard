<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_sidebar_contract_for_authenticated_roles(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();
        $management = User::factory()->withRole(Role::MANAGEMENT)->create();
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Dashboard Builder')
            ->assertSee(route('dashboard').'#dashboard-layout-builder', false)
            ->assertDontSee('Segera')
            ->assertSee('id="main-sidebar"', false)
            ->assertSee('aria-controls="main-sidebar"', false)
            ->assertSee('Buka menu', false);

        $this->actingAs($editor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Dashboard Builder')
            ->assertSee(route('dashboard').'#dashboard-layout-builder', false)
            ->assertDontSee('Segera');

        foreach ([$management, $staff] as $user) {
            $this->actingAs($user)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertDontSee('Dashboard Builder')
                ->assertDontSee(route('dashboard').'#dashboard-layout-builder', false)
                ->assertDontSee('Segera');
        }
    }
}
