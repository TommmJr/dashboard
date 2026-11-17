<?php

namespace Tests\Feature;

use App\Models\DashboardLayout;
use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use App\Services\Dashboard\DashboardLayoutService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DashboardLayoutServiceTest extends TestCase
{
    use RefreshDatabase;

    private DashboardLayoutService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->service = app(DashboardLayoutService::class);
    }

    public function test_canonical_staff_division_slugs_constant_matches_contract(): void
    {
        $this->assertSame(
            ['perencanaan-pengusahaan', 'managemen-aset'],
            DashboardLayoutService::CANONICAL_STAFF_DIVISION_SLUGS,
        );
    }

    public function test_is_privileged_returns_true_only_for_admin_management_and_editor_dashboard(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $management = User::factory()->withRole(Role::MANAGEMENT)->create();
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();
        $division = Division::factory()->create(['slug' => 'perencanaan-pengusahaan']);
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $division->id,
        ]);

        $this->assertTrue($this->service->isPrivileged($admin));
        $this->assertTrue($this->service->isPrivileged($management));
        $this->assertTrue($this->service->isPrivileged($editor));
        $this->assertFalse($this->service->isPrivileged($staff));
        $this->assertFalse($this->service->isPrivileged(null));
    }

    public function test_resolve_layout_follows_db_then_config_priority_for_staff_divisi_with_canonical_division(): void
    {
        $division = Division::factory()->create([
            'name' => 'Perencanaan Pengusahaan',
            'slug' => 'perencanaan-pengusahaan',
        ]);
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $division->id,
        ]);
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $config = [
            'widgets' => [
                'kpi-summary' => ['default_cols' => 3],
                'trend-chart' => ['default_cols' => 2],
                'kpi-rag-status' => ['default_cols' => 1],
                'dataset-status' => ['default_cols' => 3],
                'nko-ranking' => ['default_cols' => 1],
                'nko-donut' => ['default_cols' => 1],
            ],
            'layouts' => [
                'staff_divisi:perencanaan-pengusahaan' => ['kpi-summary', 'trend-chart'],
                'staff_divisi' => ['dataset-status'],
            ],
            'default' => ['nko-donut'],
        ];

        // 5. Fallback to config default when no DB and no matching config layouts
        $minimalConfig = [
            'widgets' => $config['widgets'],
            'layouts' => [],
            'default' => ['nko-donut'],
        ];
        $this->assertSame(['nko-donut'], $this->service->resolveLayout($staff, $minimalConfig));

        // 4. Config staff_divisi
        $staffOnlyConfig = [
            'widgets' => $config['widgets'],
            'layouts' => ['staff_divisi' => ['dataset-status']],
            'default' => ['nko-donut'],
        ];
        $this->assertSame(['dataset-status'], $this->service->resolveLayout($staff, $staffOnlyConfig));

        // 3. DB staff_divisi overrides config staff_divisi
        DashboardLayout::create([
            'layout_key' => 'staff_divisi',
            'role_slug' => Role::STAFF_DIVISI,
            'division_id' => null,
            'widgets' => ['nko-ranking'],
            'updated_by' => $admin->id,
        ]);
        $this->assertSame(['nko-ranking'], $this->service->resolveLayout($staff, $staffOnlyConfig));

        // 2. Config staff_divisi:<slug> overrides DB staff_divisi
        $this->assertSame(['kpi-summary', 'trend-chart'], $this->service->resolveLayout($staff, $config));

        // 1. DB staff_divisi:<slug> overrides everything
        DashboardLayout::create([
            'layout_key' => 'staff_divisi:perencanaan-pengusahaan',
            'role_slug' => Role::STAFF_DIVISI,
            'division_id' => $division->id,
            'widgets' => ['kpi-rag-status', 'kpi-summary'],
            'updated_by' => $admin->id,
        ]);
        $this->assertSame(['kpi-rag-status', 'kpi-summary'], $this->service->resolveLayout($staff, $config));
    }

    public function test_resolve_layout_handles_non_division_roles_and_defaults(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $management = User::factory()->withRole(Role::MANAGEMENT)->create();
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();
        $staffNoDivision = User::factory()->withRole(Role::STAFF_DIVISI)->create();

        $config = [
            'widgets' => [
                'admin-summary' => ['default_cols' => 3],
                'recent-audit' => ['default_cols' => 2],
                'kpi-summary' => ['default_cols' => 3],
                'trend-chart' => ['default_cols' => 2],
                'nko-ranking' => ['default_cols' => 1],
                'dataset-status' => ['default_cols' => 3],
            ],
            'layouts' => [
                'admin' => ['admin-summary'],
                'management' => ['kpi-summary', 'nko-ranking'],
                'editor_dashboard' => ['trend-chart'],
                'staff_divisi' => ['dataset-status'],
            ],
            'default' => ['dataset-status'],
        ];

        $this->assertSame(['admin-summary'], $this->service->resolveLayout($admin, $config));
        $this->assertSame(['kpi-summary', 'nko-ranking'], $this->service->resolveLayout($management, $config));
        $this->assertSame(['trend-chart'], $this->service->resolveLayout($editor, $config));
        $this->assertSame(['dataset-status'], $this->service->resolveLayout($staffNoDivision, $config));
        $this->assertSame(['dataset-status'], $this->service->resolveLayout(null, $config));

        DashboardLayout::create([
            'layout_key' => Role::ADMIN,
            'role_slug' => Role::ADMIN,
            'division_id' => null,
            'widgets' => ['recent-audit', 'admin-summary'],
            'updated_by' => $admin->id,
        ]);

        $this->assertSame(['recent-audit', 'admin-summary'], $this->service->resolveLayout($admin, $config));
    }

    public function test_resolve_db_layout_and_resolve_config_layout_enforce_structure_and_sanitization(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $division = Division::factory()->create(['slug' => 'managemen-aset']);

        DashboardLayout::create([
            'layout_key' => Role::MANAGEMENT,
            'role_slug' => Role::MANAGEMENT,
            'division_id' => null,
            'widgets' => ['kpi-summary', 'nko-ranking'],
            'updated_by' => $admin->id,
        ]);

        DashboardLayout::create([
            'layout_key' => 'staff_divisi:managemen-aset',
            'role_slug' => Role::STAFF_DIVISI,
            'division_id' => $division->id,
            'widgets' => ['dataset-status', 'aset-upt-chart'],
            'updated_by' => $admin->id,
        ]);

        $config = config('dashboard_layouts');

        $this->assertSame(['kpi-summary', 'nko-ranking'], $this->service->resolveDbLayout(Role::MANAGEMENT, $config));
        $this->assertSame(['dataset-status', 'aset-upt-chart'], $this->service->resolveDbLayout('staff_divisi:managemen-aset', $config));
        $this->assertSame([], $this->service->resolveDbLayout('nonexistent-key', $config));

        $sampleConfig = [
            'layouts' => [
                'custom' => ['kpi-summary', '', null, 123, 'trend-chart'],
            ],
        ];
        $this->assertSame(['kpi-summary', 'trend-chart'], $this->service->resolveConfigLayout('custom', $sampleConfig));
        $this->assertSame([], $this->service->resolveConfigLayout('missing', $sampleConfig));
    }

    public function test_resolve_fallback_widgets_for_target_ignores_direct_db_override_and_uses_fallback_chain(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $division = Division::factory()->create(['slug' => 'perencanaan-pengusahaan']);

        // Direct DB override for target must be ignored by resolveFallbackWidgetsForTarget
        DashboardLayout::create([
            'layout_key' => 'staff_divisi:perencanaan-pengusahaan',
            'role_slug' => Role::STAFF_DIVISI,
            'division_id' => $division->id,
            'widgets' => ['nko-donut'],
            'updated_by' => $admin->id,
        ]);

        DashboardLayout::create([
            'layout_key' => Role::ADMIN,
            'role_slug' => Role::ADMIN,
            'division_id' => null,
            'widgets' => ['failed-datasets'],
            'updated_by' => $admin->id,
        ]);

        $config = config('dashboard_layouts');

        $this->assertSame(
            ['kpi-summary', 'trend-chart', 'kpi-rag-status'],
            $this->service->resolveFallbackWidgetsForTarget(Role::STAFF_DIVISI, 'perencanaan-pengusahaan', $config),
        );

        $this->assertSame(
            ['admin-summary', 'recent-audit', 'failed-datasets', 'admin-quick-actions'],
            $this->service->resolveFallbackWidgetsForTarget(Role::ADMIN, null, $config),
        );

        // When division has no config entry, falls back to DB staff_divisi if present
        DashboardLayout::create([
            'layout_key' => 'staff_divisi',
            'role_slug' => Role::STAFF_DIVISI,
            'division_id' => null,
            'widgets' => ['nko-ranking'],
            'updated_by' => $admin->id,
        ]);

        $customConfig = [
            'widgets' => $config['widgets'],
            'layouts' => [
                'staff_divisi' => ['dataset-status'],
            ],
            'default' => ['kpi-summary'],
        ];

        $this->assertSame(
            ['nko-ranking'],
            $this->service->resolveFallbackWidgetsForTarget(Role::STAFF_DIVISI, 'perencanaan-pengusahaan', $customConfig),
        );
    }

    public function test_layout_editor_data_returns_empty_payload_for_unauthorized_and_null_users(): void
    {
        $division = Division::factory()->create(['slug' => 'perencanaan-pengusahaan']);
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $division->id,
        ]);
        $management = User::factory()->withRole(Role::MANAGEMENT)->create();

        $expectedEmpty = [
            'catalog' => [],
            'targets' => [],
            'initial_target_key' => '',
            'current_user_layout_key' => '',
        ];

        $this->assertSame($expectedEmpty, $this->service->layoutEditorData(null));
        $this->assertSame($expectedEmpty, $this->service->layoutEditorData($staff));
        $this->assertSame($expectedEmpty, $this->service->layoutEditorData($management));
    }

    public function test_layout_editor_data_returns_catalog_and_canonical_targets_for_authorized_users(): void
    {
        $perencanaan = Division::factory()->create([
            'name' => 'Perencanaan Pengusahaan',
            'slug' => 'perencanaan-pengusahaan',
        ]);
        $aset = Division::factory()->create([
            'name' => 'Managemen Aset',
            'slug' => 'managemen-aset',
        ]);
        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();

        DashboardLayout::create([
            'layout_key' => 'staff_divisi:managemen-aset',
            'role_slug' => Role::STAFF_DIVISI,
            'division_id' => $aset->id,
            'widgets' => ['aset-upt-chart'],
            'updated_by' => $admin->id,
        ]);

        $adminData = $this->service->layoutEditorData($admin);

        $this->assertNotEmpty($adminData['catalog']);
        $this->assertArrayHasKey('key', $adminData['catalog'][0]);
        $this->assertArrayHasKey('label', $adminData['catalog'][0]);
        $this->assertArrayHasKey('permission', $adminData['catalog'][0]);
        $this->assertArrayHasKey('default_cols', $adminData['catalog'][0]);
        $this->assertArrayHasKey('category', $adminData['catalog'][0]);
        $this->assertArrayHasKey('description', $adminData['catalog'][0]);

        $targetKeys = array_column($adminData['targets'], 'layout_key');
        $this->assertSame(
            ['admin', 'staff_divisi:perencanaan-pengusahaan', 'staff_divisi:managemen-aset'],
            $targetKeys,
        );
        $this->assertSame('admin', $adminData['initial_target_key']);
        $this->assertSame('admin', $adminData['current_user_layout_key']);

        $asetTarget = collect($adminData['targets'])->firstWhere('layout_key', 'staff_divisi:managemen-aset');
        $this->assertTrue($asetTarget['is_customized']);
        $this->assertSame(['aset-upt-chart'], $asetTarget['active_widgets']);
        $this->assertSame($aset->id, $asetTarget['division_id']);

        $perencanaanTarget = collect($adminData['targets'])->firstWhere('layout_key', 'staff_divisi:perencanaan-pengusahaan');
        $this->assertFalse($perencanaanTarget['is_customized']);
        $this->assertSame($perencanaan->id, $perencanaanTarget['division_id']);

        $editorData = $this->service->layoutEditorData($editor);
        $this->assertSame('editor_dashboard', $editorData['current_user_layout_key']);
        $this->assertSame('admin', $editorData['initial_target_key']);
    }

    public function test_validate_layout_target_accepts_canonical_targets_and_normalizes_widgets(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $division = Division::factory()->create(['slug' => 'perencanaan-pengusahaan']);

        $request = Request::create('/dashboard/layout', 'PUT', [
            'target' => 'staff_divisi:perencanaan-pengusahaan',
            'widgets' => [
                ['key' => 'kpi-summary', 'cols' => 3, 'config' => []],
                ['key' => 'trend-chart', 'cols' => 1, 'config' => []],
                [
                    'key' => 'custom-chart',
                    'cols' => 2,
                    'config' => [
                        'title' => 'Tren NKO Unit',
                        'chart_type' => 'line',
                        'metric' => 'nko_score',
                    ],
                ],
            ],
        ]);
        $request->setUserResolver(fn () => $admin);

        [$roleSlug, $divisionId, $layoutKey, $widgets] = $this->service->validateLayoutTarget($request, requireWidgets: true);

        $this->assertSame(Role::STAFF_DIVISI, $roleSlug);
        $this->assertSame($division->id, $divisionId);
        $this->assertSame('staff_divisi:perencanaan-pengusahaan', $layoutKey);
        // kpi-summary has default_cols=3 and empty config so it is compacted to string
        $this->assertSame('kpi-summary', $widgets[0]);
        // trend-chart has default_cols=2, overridden to 1 so stays array
        $this->assertSame(['key' => 'trend-chart', 'cols' => 1, 'config' => []], $widgets[1]);
        $this->assertSame([
            'key' => 'custom-chart',
            'cols' => 2,
            'config' => [
                'title' => 'Tren NKO Unit',
                'chart_type' => 'line',
                'metric' => 'nko_score',
            ],
        ], $widgets[2]);
    }

    public function test_validate_layout_target_rejects_non_canonical_staff_division(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $nonCanonical = Division::factory()->create(['slug' => 'keuangan-sdm']);

        $request = Request::create('/dashboard/layout', 'PUT', [
            'role_slug' => Role::STAFF_DIVISI,
            'division_id' => $nonCanonical->id,
            'widgets' => ['dataset-status'],
        ]);
        $request->setUserResolver(fn () => $admin);

        $this->expectException(ValidationException::class);
        $this->service->validateLayoutTarget($request, requireWidgets: true);
    }

    public function test_validate_layout_target_aborts_403_when_editor_targets_admin_layout(): void
    {
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();

        $request = Request::create('/dashboard/layout', 'PUT', [
            'role_slug' => Role::ADMIN,
            'widgets' => ['admin-summary'],
        ]);
        $request->setUserResolver(fn () => $editor);

        try {
            $this->service->validateLayoutTarget($request, requireWidgets: true);
            $this->fail('Expected HttpException with status 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_validate_layout_target_enforces_widget_allowlist_cols_and_custom_chart_config(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        // Unknown widget key
        $invalidKeyReq = Request::create('/dashboard/layout', 'PUT', [
            'role_slug' => Role::ADMIN,
            'widgets' => ['unknown-widget'],
        ]);
        $invalidKeyReq->setUserResolver(fn () => $admin);
        try {
            $this->service->validateLayoutTarget($invalidKeyReq, requireWidgets: true);
            $this->fail('Expected ValidationException for unknown widget key.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('widgets.0', $e->errors());
        }

        // Invalid cols
        $invalidColsReq = Request::create('/dashboard/layout', 'PUT', [
            'role_slug' => Role::ADMIN,
            'widgets' => [['key' => 'admin-summary', 'cols' => 4]],
        ]);
        $invalidColsReq->setUserResolver(fn () => $admin);
        try {
            $this->service->validateLayoutTarget($invalidColsReq, requireWidgets: true);
            $this->fail('Expected ValidationException for cols outside 1..3.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('widgets.0.cols', $e->errors());
        }

        // Invalid custom-chart config
        $invalidChartReq = Request::create('/dashboard/layout', 'PUT', [
            'role_slug' => Role::ADMIN,
            'widgets' => [
                [
                    'key' => 'custom-chart',
                    'cols' => 2,
                    'config' => [
                        'title' => str_repeat('a', 61),
                        'chart_type' => 'pie',
                        'metric' => 'invalid_metric',
                    ],
                ],
            ],
        ]);
        $invalidChartReq->setUserResolver(fn () => $admin);
        try {
            $this->service->validateLayoutTarget($invalidChartReq, requireWidgets: true);
            $this->fail('Expected ValidationException for invalid custom-chart config.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('widgets.0.config.title', $e->errors());
            $this->assertArrayHasKey('widgets.0.config.chart_type', $e->errors());
            $this->assertArrayHasKey('widgets.0.config.metric', $e->errors());
        }
    }

    public function test_save_layout_and_delete_layout_persist_and_remove_records(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $division = Division::factory()->create(['slug' => 'perencanaan-pengusahaan']);

        $saved = $this->service->saveLayout(
            $admin,
            'staff_divisi:perencanaan-pengusahaan',
            Role::STAFF_DIVISI,
            $division->id,
            ['kpi-summary', 'trend-chart'],
        );

        $this->assertInstanceOf(DashboardLayout::class, $saved);
        $this->assertDatabaseHas('dashboard_layouts', [
            'layout_key' => 'staff_divisi:perencanaan-pengusahaan',
            'role_slug' => Role::STAFF_DIVISI,
            'division_id' => $division->id,
            'updated_by' => $admin->id,
        ]);
        $this->assertSame(['kpi-summary', 'trend-chart'], $saved->widgets);

        // Update existing record
        $updated = $this->service->saveLayout(
            $admin,
            'staff_divisi:perencanaan-pengusahaan',
            Role::STAFF_DIVISI,
            $division->id,
            ['kpi-rag-status'],
        );
        $this->assertSame($saved->id, $updated->id);
        $this->assertSame(['kpi-rag-status'], $updated->widgets);

        // Delete existing record
        $this->service->deleteLayout('staff_divisi:perencanaan-pengusahaan', Role::STAFF_DIVISI, $division->id);
        $this->assertDatabaseMissing('dashboard_layouts', [
            'layout_key' => 'staff_divisi:perencanaan-pengusahaan',
        ]);

        // Deleting non-existent record is a safe no-op
        $this->service->deleteLayout('staff_divisi:perencanaan-pengusahaan', Role::STAFF_DIVISI, $division->id);
        $this->assertDatabaseMissing('dashboard_layouts', [
            'layout_key' => 'staff_divisi:perencanaan-pengusahaan',
        ]);
    }
}
