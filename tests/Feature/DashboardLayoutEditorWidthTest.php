<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitDivisionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardLayoutEditorWidthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(UnitDivisionSeeder::class);
    }

    public function test_layout_editor_provides_width_options_and_live_preview(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        // Halaman terpisah Dashboard Builder memuat opsi lebar dan live preview
        $response = $this->actingAs($admin)->get(route('dashboard.builder'));

        $response->assertOk()
            ->assertSee('Lebar Kolom')
            ->assertSee('Pratinjau Tata Letak');

        // Halaman dashboard utama tidak menggabungkan editor tata letak
        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('id="dashboard-layout-builder"', false);
    }

    public function test_dashboard_builder_page_requires_auth_and_permission(): void
    {
        $this->get(route('dashboard.builder'))
            ->assertRedirect(route('login'));

        $management = User::factory()->withRole(Role::MANAGEMENT)->create();
        $this->actingAs($management)
            ->get(route('dashboard.builder'))
            ->assertForbidden();

        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();
        $this->actingAs($editor)
            ->get(route('dashboard.builder'))
            ->assertOk()
            ->assertSee('Dashboard Builder')
            ->assertSee('id="dashboard-layout-builder"', false);
    }

    public function test_dashboard_builder_contains_toast_notification_container(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $this->actingAs($admin)
            ->get(route('dashboard.builder'))
            ->assertOk()
            ->assertSee('x-show="toast.show"', false)
            ->assertSee('x-text="toast.message"', false);
    }

    public function test_dashboard_builder_renders_visual_palette_and_interactive_canvas(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $response = $this->actingAs($admin)->get(route('dashboard.builder'));

        $response->assertOk()
            ->assertSee('Palet Komponen')
            ->assertSee('Kanvas Presentasi Dashboard')
            ->assertSee('draggable="true"', false)
            ->assertSee('onCanvasDrop', false);
    }

    public function test_dashboard_builder_canvas_includes_live_preview_containers(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $response = $this->actingAs($admin)->get(route('dashboard.builder'));

        $response->assertOk()
            ->assertSee('canvas-chart-container', false)
            ->assertSee('initCanvasChart', false);
    }

    public function test_dashboard_builder_canvas_provides_in_card_controls_and_width_toggles(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $response = $this->actingAs($admin)->get(route('dashboard.builder'));

        $response->assertOk()
            ->assertSee('Atur Lebar')
            ->assertSee('canvas-drag-handle', false)
            ->assertSee('canvas-card-toolbar', false);
    }

    public function test_dashboard_builder_provides_direct_link_to_main_dashboard_presentation(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $response = $this->actingAs($admin)->get(route('dashboard.builder'));

        $response->assertOk()
            ->assertSee(route('dashboard'))
            ->assertSee('Buka Dashboard');
    }
}
