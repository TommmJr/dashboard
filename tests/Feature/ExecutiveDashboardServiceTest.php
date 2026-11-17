<?php

namespace Tests\Feature;

use App\Enums\DatasetStatus;
use App\Models\AuditLog;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\Indicator;
use App\Models\OrgUnit;
use App\Models\PerformanceMetric;
use App\Models\PerformanceObservation;
use App\Models\Role;
use App\Models\User;
use App\Services\AssetStockConverter;
use App\Services\Dashboard\ExecutiveDashboardService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitDivisionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ExecutiveDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(UnitDivisionSeeder::class);
    }

    public function test_is_privileged_identifies_admin_management_and_editor_only(): void
    {
        $service = app(ExecutiveDashboardService::class);
        $kpiDivision = Division::where('slug', 'perencanaan-pengusahaan')->firstOrFail();

        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);
        $management = User::factory()->withRole(Role::MANAGEMENT)->create(['division_id' => null]);
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create(['division_id' => null]);
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $kpiDivision->id]);

        $this->assertTrue($service->isPrivileged($admin));
        $this->assertTrue($service->isPrivileged($management));
        $this->assertTrue($service->isPrivileged($editor));
        $this->assertFalse($service->isPrivileged($staff));
        $this->assertFalse($service->isPrivileged(null));
    }

    public function test_build_executive_meta_isolates_ready_datasets_to_kpi_division_and_excludes_asset_metrics(): void
    {
        $service = app(ExecutiveDashboardService::class);
        $kpiDivision = Division::where('slug', 'perencanaan-pengusahaan')->firstOrFail();
        $asetDivision = Division::where('slug', 'managemen-aset')->firstOrFail();
        $otherDivision = Division::factory()->create(['name' => 'Operasi Sistem', 'slug' => 'operasi-sistem']);

        $management = User::factory()->withRole(Role::MANAGEMENT)->create(['division_id' => null]);
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create(['division_id' => null]);
        $kpiStaff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $kpiDivision->id]);
        $asetStaff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $asetDivision->id]);

        // 1. Valid KPI READY dataset in perencanaan-pengusahaan (should be counted)
        $kpiDataset = Dataset::factory()->create([
            'division_id' => $kpiDivision->id,
            'status' => Dataset::STATUS_READY,
        ]);
        PerformanceMetric::factory()->create([
            'dataset_id' => $kpiDataset->id,
            'division_id' => $kpiDivision->id,
            'period' => '2026-10-31',
            'metric_name' => 'TJBT | NKO',
            'value' => 102.5,
        ]);

        // 2. Non-READY KPI dataset in perencanaan-pengusahaan (should NOT be counted)
        Dataset::factory()->create([
            'division_id' => $kpiDivision->id,
            'status' => Dataset::STATUS_ARCHIVED,
        ]);

        // 3. READY dataset in perencanaan-pengusahaan that contains AssetStockConverter::PREFIX metric (should NOT be counted)
        $pollutedDataset = Dataset::factory()->create([
            'division_id' => $kpiDivision->id,
            'status' => Dataset::STATUS_READY,
        ]);
        PerformanceMetric::factory()->create([
            'dataset_id' => $pollutedDataset->id,
            'division_id' => $kpiDivision->id,
            'period' => '2026-10-31',
            'metric_name' => AssetStockConverter::PREFIX.'BANDUNG | BANDUNG | Tower 500 KV | SU',
            'value' => 100.0,
        ]);

        // 4. READY dataset in managemen-aset (should NOT be counted)
        $asetDataset = Dataset::factory()->create([
            'division_id' => $asetDivision->id,
            'status' => Dataset::STATUS_READY,
        ]);
        PerformanceMetric::factory()->create([
            'dataset_id' => $asetDataset->id,
            'division_id' => $asetDivision->id,
            'period' => '2026-10-31',
            'metric_name' => AssetStockConverter::PREFIX.'BANDUNG | BANDUNG | Tower 500 KV | SU',
            'value' => 250.0,
        ]);

        // 5. READY dataset in another unrelated division (should NOT be counted)
        Dataset::factory()->create([
            'division_id' => $otherDivision->id,
            'status' => Dataset::STATUS_READY,
        ]);

        $widgets = [
            [
                'key' => 'kpi-summary',
                'data' => ['achieved_count' => 10, 'total_count' => 12, 'has_data' => true],
            ],
            [
                'key' => 'kpi-rag-status',
                'data' => ['period' => '2026-10-31'],
            ],
            [
                'key' => 'nko-ranking',
                'data' => [
                    (object) ['unit' => 'TJBT', 'nko' => 102.5],
                    (object) ['unit' => 'OTHER', 'nko' => 90.0],
                ],
            ],
        ];
        $filters = [
            'start_date' => null,
            'end_date' => null,
            'units' => ['TJBT'],
        ];

        $metaManagement = $service->buildExecutiveMeta($management, $widgets, $filters);
        $metaEditor = $service->buildExecutiveMeta($editor, $widgets, $filters);
        $metaKpiStaff = $service->buildExecutiveMeta($kpiStaff, $widgets, $filters);
        $metaAsetStaff = $service->buildExecutiveMeta($asetStaff, $widgets, $filters);

        $this->assertSame(1, $metaManagement['ready_datasets']);
        $this->assertSame(1, $metaEditor['ready_datasets']);
        $this->assertSame(1, $metaKpiStaff['ready_datasets']);
        $this->assertSame(0, $metaAsetStaff['ready_datasets']);

        $this->assertSame('TJBT', $metaManagement['unit_label']);
        $this->assertSame('2026-10-31', $metaManagement['period']);
        $this->assertSame('Oktober 2026', $metaManagement['period_label']);
        $this->assertSame(102.5, $metaManagement['average_nko']);
        $this->assertSame(10, $metaManagement['achieved_count']);
        $this->assertSame(12, $metaManagement['total_count']);
        $this->assertTrue($metaManagement['has_data']);
    }

    public function test_build_executive_meta_fails_closed_when_kpi_division_does_not_exist(): void
    {
        $service = app(ExecutiveDashboardService::class);
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);
        $asetDivision = Division::where('slug', 'managemen-aset')->firstOrFail();

        Dataset::factory()->create([
            'division_id' => $asetDivision->id,
            'status' => Dataset::STATUS_READY,
        ]);

        // Delete perencanaan-pengusahaan division
        Division::where('slug', 'perencanaan-pengusahaan')->delete();

        $meta = $service->buildExecutiveMeta(
            $admin,
            [],
            ['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'units' => []],
            fn () => ['achieved_count' => 3, 'total_count' => 4, 'has_data' => true],
            fn () => collect([(object) ['unit' => 'TJBT', 'nko' => 101.0]]),
            fn () => '2026-09-30',
        );

        $this->assertSame(0, $meta['ready_datasets']);
        $this->assertSame('2026-09-30', $meta['period']);
        $this->assertSame(101.0, $meta['average_nko']);
        $this->assertSame(3, $meta['achieved_count']);
        $this->assertSame(4, $meta['total_count']);
        $this->assertTrue($meta['has_data']);
    }

    public function test_admin_summary_and_failed_datasets_return_accurate_counts_and_latest_failures(): void
    {
        $service = app(ExecutiveDashboardService::class);
        $division = Division::where('slug', 'perencanaan-pengusahaan')->firstOrFail();
        $user = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);

        Dataset::factory()->create([
            'division_id' => $division->id,
            'user_id' => $user->id,
            'status' => DatasetStatus::READY,
        ]);

        $failedIds = [];
        for ($i = 1; $i <= 4; $i++) {
            $ds = Dataset::factory()->create([
                'division_id' => $division->id,
                'user_id' => $user->id,
                'status' => DatasetStatus::FAILED,
                'original_name' => "failed-{$i}.xlsx",
                'created_at' => now()->subMinutes(10 - $i),
            ]);
            $failedIds[] = $ds->id;
        }

        // AuditLog created automatically via Auditable + explicit entry today and yesterday
        AuditLog::factory()->create([
            'user_id' => $user->id,
            'action' => AuditLog::ACTION_LOGIN,
            'created_at' => now(),
        ]);
        AuditLog::factory()->create([
            'user_id' => $user->id,
            'action' => AuditLog::ACTION_LOGIN,
            'created_at' => now()->subDay(),
        ]);

        $summary = $service->adminSummary();
        $failed = $service->failedDatasets();

        $this->assertSame(Dataset::query()->count(), $summary['total_datasets']);
        $this->assertSame(4, $summary['failed_datasets']);
        $this->assertSame(AuditLog::query()->whereDate('created_at', today())->count(), $summary['audit_today']);
        $this->assertSame(User::query()->count(), $summary['total_users']);

        $this->assertSame(4, $failed['count']);
        $this->assertCount(3, $failed['rows']);
        $this->assertSame($failedIds[3], $failed['rows']->first()->id);
        $this->assertTrue($failed['rows']->first()->relationLoaded('division'));
        $this->assertTrue($failed['rows']->first()->relationLoaded('user'));
    }

    public function test_recent_audit_scopes_entries_by_audit_view_all_permission(): void
    {
        $service = app(ExecutiveDashboardService::class);
        $management = User::factory()->withRole(Role::MANAGEMENT)->create(['division_id' => null]);
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create(['division_id' => null]);

        $loginLog = AuditLog::factory()->create([
            'user_id' => $management->id,
            'action' => AuditLog::ACTION_LOGIN,
            'auditable_type' => User::class,
            'auditable_id' => $management->id,
            'created_at' => now()->addSeconds(30),
        ]);

        $datasetLog = AuditLog::factory()->create([
            'user_id' => $management->id,
            'action' => AuditLog::ACTION_IMPORT,
            'auditable_type' => (new Dataset)->getMorphClass(),
            'auditable_id' => 1,
            'created_at' => now()->addSeconds(20),
        ]);

        $metricLog = AuditLog::factory()->create([
            'user_id' => $management->id,
            'action' => AuditLog::ACTION_UPDATE,
            'auditable_type' => (new PerformanceMetric)->getMorphClass(),
            'auditable_id' => 1,
            'created_at' => now()->addSeconds(10),
        ]);

        $excludedLog = AuditLog::factory()->create([
            'user_id' => $management->id,
            'action' => AuditLog::ACTION_CREATE,
            'auditable_type' => PerformanceObservation::class,
            'auditable_id' => 1,
            'created_at' => now()->addSeconds(40),
        ]);

        $managementAudit = $service->recentAudit($management);
        $editorAudit = $service->recentAudit($editor);
        $guestAudit = $service->recentAudit(null);

        $this->assertInstanceOf(Collection::class, $managementAudit);
        $this->assertTrue($managementAudit->contains('id', $loginLog->id));
        $this->assertTrue($managementAudit->contains('id', $datasetLog->id));
        $this->assertTrue($managementAudit->contains('id', $metricLog->id));
        $this->assertFalse($managementAudit->contains('id', $excludedLog->id));

        $this->assertFalse($editorAudit->contains('id', $loginLog->id));
        $this->assertTrue($editorAudit->contains('id', $datasetLog->id));
        $this->assertTrue($editorAudit->contains('id', $metricLog->id));
        $this->assertFalse($editorAudit->contains('id', $excludedLog->id));

        $this->assertFalse($guestAudit->contains('id', $loginLog->id));
        $this->assertTrue($guestAudit->contains('id', $datasetLog->id));
    }

    public function test_aset_upt_chart_fails_closed_for_non_asset_staff_and_aggregates_for_asset_staff_and_privileged(): void
    {
        $service = app(ExecutiveDashboardService::class);
        $kpiDivision = Division::where('slug', 'perencanaan-pengusahaan')->firstOrFail();
        $asetDivision = Division::where('slug', 'managemen-aset')->firstOrFail();

        $kpiStaff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $kpiDivision->id]);
        $noDivisionStaff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => null]);
        $asetStaff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $asetDivision->id]);
        $management = User::factory()->withRole(Role::MANAGEMENT)->create(['division_id' => null]);

        $dataset = Dataset::factory()->create([
            'division_id' => $asetDivision->id,
            'status' => Dataset::STATUS_READY,
        ]);

        $period = '2026-10-31';
        PerformanceMetric::factory()->create([
            'dataset_id' => $dataset->id,
            'division_id' => $asetDivision->id,
            'period' => $period,
            'metric_name' => 'ASET | BANDUNG | BANDUNG | Tower 500 KV | SU',
            'value' => 120.0,
        ]);
        PerformanceMetric::factory()->create([
            'dataset_id' => $dataset->id,
            'division_id' => $asetDivision->id,
            'period' => $period,
            'metric_name' => 'ASET | BANDUNG | BANDUNG | Transmisi 500 KV | SU (kms)',
            'value' => 45.5,
        ]);
        PerformanceMetric::factory()->create([
            'dataset_id' => $dataset->id,
            'division_id' => $asetDivision->id,
            'period' => $period,
            'metric_name' => 'ASET | BANDUNG | BANDUNG | Trafo 500/150 KV | MVA',
            'value' => 500.0,
        ]);
        // Non-focused attribute (should be filtered out from series)
        PerformanceMetric::factory()->create([
            'dataset_id' => $dataset->id,
            'division_id' => $asetDivision->id,
            'period' => $period,
            'metric_name' => 'ASET | BANDUNG | BANDUNG | DIAMETER | CUT OFF CB',
            'value' => 999.0,
        ]);
        // UIT aggregate metric
        PerformanceMetric::factory()->create([
            'dataset_id' => $dataset->id,
            'division_id' => $asetDivision->id,
            'period' => $period,
            'metric_name' => 'ASET | UIT | Tower 500 KV | SU',
            'value' => 150.0,
        ]);

        $deniedKpiStaff = $service->asetUptChart($kpiStaff);
        $this->assertNull($deniedKpiStaff['period']);
        $this->assertSame([], $deniedKpiStaff['labels']);
        $this->assertSame([], $deniedKpiStaff['series']);
        $this->assertSame([], $deniedKpiStaff['table_rows']);
        $this->assertSame(0.0, $deniedKpiStaff['summary']['total_tower']);

        $deniedNoDiv = $service->asetUptChart($noDivisionStaff);
        $this->assertNull($deniedNoDiv['period']);
        $this->assertSame([], $deniedNoDiv['labels']);

        foreach ([$asetStaff, $management] as $authorizedUser) {
            $chart = $service->asetUptChart($authorizedUser);

            $this->assertSame($period, $chart['period']);
            $this->assertSame(['BANDUNG'], $chart['labels']);
            $this->assertCount(3, $chart['series']);
            $this->assertSame('uit', $chart['summary']['source']);
            $this->assertSame(150.0, $chart['summary']['total_tower']);
            $this->assertSame(120.0, $chart['summary_upt']['total_tower']);
            $this->assertSame(45.5, $chart['summary_upt']['total_transmisi']);
            $this->assertSame(500.0, $chart['summary_upt']['total_trafo']);
            $this->assertCount(8, $chart['table_rows']);
            $this->assertCount(1, $chart['series_by_category']['tower']);
            $this->assertCount(1, $chart['series_by_category']['transmisi']);
            $this->assertCount(1, $chart['series_by_category']['trafo']);
            $this->assertSame([$period], $chart['available_periods']);
            $this->assertArrayHasKey('ultg_by_upt', $chart);
            $this->assertArrayHasKey('capacity_ratios', $chart);
            $this->assertCount(1, $chart['ultg_by_upt']['BANDUNG']);
            $this->assertSame([], $chart['ultg_by_upt']['BANDUNG'][0]['attributes'] ? array_keys(array_filter(
                $chart['ultg_by_upt']['BANDUNG'][0]['attributes'],
                fn (string $k): bool => str_starts_with($k, 'DIAMETER'),
                ARRAY_FILTER_USE_KEY,
            )) : []);

            $rowsByUpt = collect($chart['table_rows'])->keyBy('upt');
            $this->assertSame(120.0, $rowsByUpt['BANDUNG']['tower']);
            $this->assertNull($rowsByUpt['BEKASI']['tower']);
        }
    }

    public function test_dataset_status_legacy_and_structured_modes(): void
    {
        $service = app(ExecutiveDashboardService::class);
        $kpiDivision = Division::where('slug', 'perencanaan-pengusahaan')->firstOrFail();
        $asetDivision = Division::where('slug', 'managemen-aset')->firstOrFail();

        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);
        $kpiStaff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $kpiDivision->id]);
        $asetStaff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $asetDivision->id]);

        $kpiDataset = Dataset::factory()->create([
            'division_id' => $kpiDivision->id,
            'status' => Dataset::STATUS_READY,
        ]);
        Dataset::factory()->create([
            'division_id' => $kpiDivision->id,
            'status' => Dataset::STATUS_ARCHIVED,
        ]);
        Dataset::factory()->create([
            'division_id' => $asetDivision->id,
            'status' => Dataset::STATUS_FAILED,
        ]);

        $recentPeriod = Carbon::now()->startOfMonth()->toDateString();
        $recentEndOfMonth = Carbon::parse($recentPeriod)->endOfMonth()->toDateString();

        PerformanceMetric::factory()->create([
            'dataset_id' => $kpiDataset->id,
            'division_id' => $kpiDivision->id,
            'period' => $recentEndOfMonth,
            'metric_name' => 'TJBT | Indikator A',
            'value' => 100.0,
        ]);
        PerformanceMetric::factory()->create([
            'dataset_id' => $kpiDataset->id,
            'division_id' => $kpiDivision->id,
            'period' => $recentEndOfMonth,
            'metric_name' => 'OTHER | Indikator A',
            'value' => 95.0,
        ]);

        // Legacy mode
        config(['kpi.read_model' => 'legacy', 'kpi.visible_units' => ['TJBT']]);
        $this->assertFalse($service->kpiReadModelStructured());
        $this->assertSame($recentEndOfMonth, $service->latestReadyPeriod($admin));

        $legacyAdmin = $service->datasetStatus($admin);
        $this->assertSame($recentEndOfMonth, $legacyAdmin['latest_period']);
        $this->assertSame(1, $legacyAdmin['ready']);
        $this->assertSame(1, $legacyAdmin['archived']);
        $this->assertSame(1, $legacyAdmin['failed']);
        $this->assertSame(1, $legacyAdmin['latest_rows']);
        $this->assertTrue($legacyAdmin['fresh']);

        $legacyKpiStaff = $service->datasetStatus($kpiStaff);
        $this->assertSame(1, $legacyKpiStaff['ready']);
        $this->assertSame(1, $legacyKpiStaff['archived']);
        $this->assertSame(0, $legacyKpiStaff['failed']);

        // Structured mode
        config(['kpi.read_model' => 'structured', 'kpi.visible_units' => ['TJBT']]);
        $this->assertTrue($service->kpiReadModelStructured());

        $tjbtUnit = OrgUnit::firstOrCreate(['name' => 'TJBT']);
        $otherUnit = OrgUnit::firstOrCreate(['name' => 'OTHER']);
        $indTjbt = Indicator::factory()->create(['org_unit_id' => $tjbtUnit->id, 'name' => 'Indikator 1']);
        $indOther = Indicator::factory()->create(['org_unit_id' => $otherUnit->id, 'name' => 'Indikator 2']);

        PerformanceObservation::factory()->create([
            'dataset_id' => $kpiDataset->id,
            'division_id' => $kpiDivision->id,
            'org_unit_id' => $tjbtUnit->id,
            'indicator_id' => $indTjbt->id,
            'period' => $recentPeriod,
        ]);
        PerformanceObservation::factory()->create([
            'dataset_id' => $kpiDataset->id,
            'division_id' => $kpiDivision->id,
            'org_unit_id' => $otherUnit->id,
            'indicator_id' => $indOther->id,
            'period' => $recentPeriod,
        ]);

        $this->assertSame($recentPeriod, $service->latestStructuredReadyPeriod($admin));
        $this->assertSame($recentPeriod, $service->latestReadyPeriod($admin));

        $structuredAdmin = $service->datasetStatus($admin);
        $this->assertSame($recentEndOfMonth, $structuredAdmin['latest_period']);
        $this->assertSame(1, $structuredAdmin['latest_rows']);
        $this->assertSame(1, $structuredAdmin['ready']);

        // Non-KPI fallback in structured mode when division has only performance_metrics
        $asetReady = Dataset::factory()->create([
            'division_id' => $asetDivision->id,
            'status' => Dataset::STATUS_READY,
        ]);
        PerformanceMetric::factory()->create([
            'dataset_id' => $asetReady->id,
            'division_id' => $asetDivision->id,
            'period' => $recentEndOfMonth,
            'metric_name' => 'ASET | BANDUNG | BANDUNG | Tower 500 KV | SU',
            'value' => 50.0,
        ]);

        $structuredAsetStaff = $service->datasetStatusStructured($asetStaff);
        $this->assertSame($recentEndOfMonth, $structuredAsetStaff['latest_period']);
        $this->assertSame(1, $structuredAsetStaff['latest_rows']);
        $this->assertSame(1, $structuredAsetStaff['ready']);
        $this->assertSame(1, $structuredAsetStaff['failed']);
    }
}
