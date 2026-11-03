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
            ->assertSee('id="preview-canvas-viewport"', false);

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
            ->assertSee('Komponen')
            ->assertSee('id="builder-preview-pane"', false)
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

    public function test_dashboard_builder_renders_split_view_editor_and_preview_panes(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $response = $this->actingAs($admin)->get(route('dashboard.builder'));

        $response->assertOk()
            ->assertSee('id="builder-split-view"', false)
            ->assertSee('id="builder-config-pane"', false)
            ->assertSee('id="builder-preview-pane"', false)
            ->assertSee('lg:grid-cols-12', false)
            ->assertSee('lg:col-span-5', false)
            ->assertSee('lg:col-span-7', false)
            ->assertSee('Pratinjau');
    }

    public function test_dashboard_builder_preview_pane_mirrors_active_widgets_in_real_time(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $response = $this->actingAs($admin)->get(route('dashboard.builder'));

        $response->assertOk()
            ->assertDontSee('id="active-widget-list"', false)
            ->assertSee('id="preview-canvas-viewport"', false)
            ->assertSee('preview-card-item', false)
            ->assertSee('previewSpanClass', false)
            ->assertSee('md:grid-cols-3', false);
    }

    public function test_dashboard_builder_preview_pane_initializes_and_resizes_live_charts(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $response = $this->actingAs($admin)->get(route('dashboard.builder'));

        $response->assertOk()
            ->assertSee('preview-chart-container', false)
            ->assertSee('x-effect="initPreviewChart($el, w)"', false)
            ->assertSee('initPreviewChart', false)
            ->assertSee('resizePreviewCharts', false)
            ->assertSee('disposePreviewChart', false)
            ->assertSee('disposeAllPreviewCharts', false)
            ->assertSee('previewChartInstances', false);
    }

    public function test_dashboard_builder_palette_renders_visual_chart_thumbnails(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $this->actingAs($admin)->get(route('dashboard.builder'))
            ->assertOk()
            ->assertSee('palette-thumb', false)
            ->assertSee('palette-type-label', false)
            ->assertSee('paletteVisual(item.key)', false)
            ->assertSee('Grafik Batang')
            ->assertSee('Grafik Garis')
            ->assertSee('Grafik Donat')
            ->assertSee('Kartu Skor')
            ->assertDontSee('item.key.substring(0, 2)', false);
    }

    public function test_dashboard_builder_has_no_redundant_helper_copy(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $this->actingAs($admin)->get(route('dashboard.builder'))
            ->assertOk()
            ->assertSee('Komponen')
            ->assertDontSee('Widget Aktif')
            ->assertSee('Pratinjau')
            ->assertDontSee('Sinkronisasi Real-Time')
            ->assertDontSee('(Split-View)')
            ->assertDontSee('x-text="item.key"', false);
    }

    public function test_dashboard_builder_preview_cards_carry_width_and_delete_controls(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $html = $this->actingAs($admin)->get(route('dashboard.builder'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/preview-card-item.*canvas-card-toolbar.*setWidgetCols\(w, n\).*removeWidget\(index\)/s', $html);
        $this->assertStringContainsString('isLockedWidget(w.key)', $html);
        $this->assertSame(1, substr_count($html, '@click="removeWidget(index)"'));
        $this->assertStringNotContainsString('x-model.number="w.cols"', $html);
    }

    public function test_dashboard_builder_preview_shows_sketch_for_every_widget(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $this->actingAs($admin)->get(route('dashboard.builder'))
            ->assertOk()
            ->assertSee('preview-sketch', false)
            ->assertSee('paletteVisual(w.key)', false)
            ->assertSee('paletteVisual(item.key)', false)
            ->assertDontSee('Komponen akan dirender penuh')
            ->assertDontSee('Pratinjau Visual:');
    }

    public function test_dashboard_builder_preview_auto_scrolls_during_drag(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $this->actingAs($admin)->get(route('dashboard.builder'))
            ->assertOk()
            ->assertSee('@dragover="autoScrollPreview($event)"', false)
            ->assertSee('autoScrollPreview(event)', false);
    }

    public function test_dashboard_builder_auto_scroll_uses_visible_edges(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $this->actingAs($admin)->get(route('dashboard.builder'))
            ->assertOk()
            ->assertSee('Math.min(rect.bottom, window.innerHeight)', false)
            ->assertSee("closest('.app-content')", false);
    }

    public function test_dashboard_builder_supports_cursor_direction_placement_and_indicators(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $this->actingAs($admin)->get(route('dashboard.builder'))
            ->assertOk()
            ->assertSee('getDropPlacement', false)
            ->assertSee('dropTargetIndex', false)
            ->assertSee('dropPlacement', false)
            ->assertSee('onCardDragLeave', false)
            ->assertSee('onViewportDragLeave', false);
    }
}
