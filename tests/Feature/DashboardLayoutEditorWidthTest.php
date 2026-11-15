<?php

namespace Tests\Feature;

use App\Models\DashboardLayout;
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
            ->assertSee('id="builder-top-bar"', false)
            ->assertSee('lg:grid-cols-12', false)
            ->assertSee('id="builder-config-pane"', false)
            ->assertSee('lg:col-span-4 xl:col-span-3', false)
            ->assertSee('id="builder-preview-pane"', false)
            ->assertSee('lg:col-span-8 xl:col-span-9', false)
            ->assertSee('w-full')
            ->assertSee('Pratinjau Tata Letak');

        $html = $response->getContent();
        $topBarPos = strpos($html, 'id="builder-top-bar"');
        $gridPos = strpos($html, 'lg:grid-cols-12');
        $configPos = strpos($html, 'id="builder-config-pane"');
        $previewPos = strpos($html, 'id="builder-preview-pane"');

        $this->assertNotFalse($topBarPos);
        $this->assertNotFalse($gridPos);
        $this->assertNotFalse($configPos);
        $this->assertNotFalse($previewPos);
        $this->assertLessThan($gridPos, $topBarPos);
        $this->assertLessThan($configPos, $gridPos);
        $this->assertLessThan($previewPos, $configPos);
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
            ->assertSee('lg:grid-cols-3', false);
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

        $response = $this->actingAs($admin)->get(route('dashboard.builder'));

        $response->assertOk()
            ->assertSee('Lebar Kolom')
            ->assertSee('1 Kol')
            ->assertSee('2 Kol')
            ->assertSee('3 Kol')
            ->assertSee('setWidgetCols(index, 1)', false)
            ->assertSee('setWidgetCols(index, 2)', false)
            ->assertSee('setWidgetCols(index, 3)', false);

        $html = $response->getContent();

        $this->assertMatchesRegularExpression('/preview-card-item.*canvas-card-toolbar.*setWidgetCols\(index, 1\).*1 Kol.*2 Kol.*3 Kol.*removeWidget\(index\)/s', $html);
        $this->assertStringContainsString('isLockedWidget(w.key)', $html);
        $this->assertStringNotContainsString("widgetKey === 'layout-editor-placeholder'", $html);
        $this->assertSame(1, substr_count($html, '@click="removeWidget(index)"'));
        $this->assertStringNotContainsString('x-model.number="w.cols"', $html);
    }

    public function test_dashboard_builder_preview_shows_sketch_for_every_widget(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $this->actingAs($admin)->get(route('dashboard.builder'))
            ->assertOk()
            ->assertSee('preview-sketch', false)
            ->assertSee('data-mini-mockup="true"', false)
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

    public function test_dashboard_builder_supports_pointer_events_drag_and_drop_reordering(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $this->actingAs($admin)->get(route('dashboard.builder'))
            ->assertOk()
            ->assertSee('@pointerdown="onCardPointerDown($event, index)"', false)
            ->assertSee('select-none touch-none', false)
            ->assertSee('pointerDrag.active', false)
            ->assertSee('onCardPointerDown(event, index)', false)
            ->assertSee('updateDropTargetFromPoint', false)
            ->assertSee('executeReorder', false);
    }

    public function test_dashboard_builder_auto_scroll_uses_raf_and_gradual_speed_curve(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $this->actingAs($admin)->get(route('dashboard.builder'))
            ->assertOk()
            ->assertSee('runAutoScrollLoop()', false)
            ->assertSee('requestAnimationFrame(step)', false)
            ->assertSee('cancelAnimationFrame(this.autoScrollState.frame)', false)
            ->assertSee('stopAutoScroll()', false)
            ->assertSee('(ratio * ratio)', false)
            ->assertSee('viewport.scrollHeight > viewport.clientHeight + 1', false);
    }

    public function test_dashboard_builder_provides_escape_key_and_pointer_cancellation(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $this->actingAs($admin)->get(route('dashboard.builder'))
            ->assertOk()
            ->assertSee('@keydown.escape.window="cancelDrag()"', false)
            ->assertSee('cancelDrag()', false)
            ->assertSee('onPointerCancel', false);
    }

    public function test_dashboard_builder_persists_reordered_widget_layout(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        // 1. Initial order: admin-summary first, then layout-editor-placeholder
        $initialWidgets = [
            ['key' => 'admin-summary', 'cols' => 3],
            ['key' => 'layout-editor-placeholder', 'cols' => 3],
        ];

        $response = $this->actingAs($admin)
            ->putJson(route('dashboard.layout.update'), [
                'role_slug' => Role::ADMIN,
                'layout_key' => Role::ADMIN,
                'widgets' => $initialWidgets,
            ]);

        $response->assertOk();
        $this->assertSame(['admin-summary', 'layout-editor-placeholder'], DashboardLayout::where('role_slug', Role::ADMIN)->first()?->widgets);

        // 2. Reordered layout: swapped positions
        $reorderedWidgets = [
            ['key' => 'layout-editor-placeholder', 'cols' => 3],
            ['key' => 'admin-summary', 'cols' => 3],
        ];

        $reorderResponse = $this->actingAs($admin)
            ->putJson(route('dashboard.layout.update'), [
                'role_slug' => Role::ADMIN,
                'layout_key' => Role::ADMIN,
                'widgets' => $reorderedWidgets,
            ]);

        $reorderResponse->assertOk();
        $layout = DashboardLayout::where('role_slug', Role::ADMIN)->first();
        $this->assertNotNull($layout);
        $this->assertSame(['layout-editor-placeholder', 'admin-summary'], $layout->widgets);
    }

    public function test_dashboard_builder_allows_layout_without_layout_editor_placeholder(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $validWidgets = [
            ['key' => 'admin-summary', 'cols' => 3],
        ];

        $response = $this->actingAs($admin)
            ->putJson(route('dashboard.layout.update'), [
                'role_slug' => Role::ADMIN,
                'layout_key' => Role::ADMIN,
                'widgets' => $validWidgets,
            ]);

        $response->assertOk();
        $this->assertSame(['admin-summary'], DashboardLayout::where('role_slug', Role::ADMIN)->first()?->widgets);
    }

    public function test_dashboard_builder_resets_customized_layout_to_defaults(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        // Save a customized layout first
        DashboardLayout::create([
            'role_slug' => Role::ADMIN,
            'widgets' => ['layout-editor-placeholder', 'admin-summary'],
            'updated_by' => $admin->id,
        ]);

        $this->assertDatabaseHas('dashboard_layouts', [
            'role_slug' => Role::ADMIN,
        ]);

        // Reset layout
        $response = $this->actingAs($admin)
            ->deleteJson(route('dashboard.layout.reset'), [
                'role_slug' => Role::ADMIN,
                'layout_key' => Role::ADMIN,
            ]);

        $response->assertOk();
        $this->assertDatabaseMissing('dashboard_layouts', [
            'role_slug' => Role::ADMIN,
        ]);
    }

    public function test_dashboard_builder_studio_dock_provides_search_category_filters_and_omits_legacy_drawers(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $response = $this->actingAs($admin)->get(route('dashboard.builder'));

        $response->assertOk()
            ->assertDontSee('id="builder-quick-add-drawer"', false)
            ->assertDontSee('id="builder-quick-add-trigger"', false)
            ->assertDontSee('card-action-menu-btn', false)
            ->assertDontSee('+ Tambah Cepat')
            ->assertDontSee('Katalog Penuh')
            ->assertSee('catalogSearch', false)
            ->assertSee('catalogFilterCategory', false)
            ->assertSee('Semua')
            ->assertSee('KPI')
            ->assertSee('Aset')
            ->assertSee('Admin')
            ->assertSee('addWidget(item.key)', false)
            ->assertSee('+ Tambah')
            ->assertSee('Pratinjau Tata Letak')
            ->assertSee('sticky top-20', false)
            ->assertSee('lg:sticky lg:top-40', false);

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, '@click="removeWidget(index)"'));
    }

    public function test_dashboard_builder_renders_obsidian_studio_command_bar_and_high_contrast_canvas(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $response = $this->actingAs($admin)->get(route('dashboard.builder'));

        $response->assertOk()
            ->assertSee('rounded-2xl border border-slate-800 bg-slate-900 px-4 py-3 text-white shadow-lg ring-1 ring-white/10', false)
            ->assertSee('bg-indigo-600 text-white font-semibold shadow-sm ring-1 ring-indigo-400/30', false)
            ->assertSee('paletteThumbClass(item.key)', false)
            ->assertSee('bg-indigo-50 text-indigo-600 ring-1 ring-indigo-500/15', false)
            ->assertSee('bg-teal-50 text-teal-700 ring-1 ring-teal-500/15', false)
            ->assertSee('bg-amber-50 text-amber-700 ring-1 ring-amber-500/15', false)
            ->assertSee('bg-slate-200/75', false)
            ->assertSee('radial-gradient(#94a3b8 1.25px, transparent 1.25px)', false)
            ->assertSee('bg-slate-50/90 px-3.5 py-2.5', false)
            ->assertSee('bg-slate-900 text-white text-[11px] font-bold', false)
            ->assertSee('bg-indigo-600 text-white font-semibold shadow-xs', false)
            ->assertDontSee('font-mono', false);
    }
}
