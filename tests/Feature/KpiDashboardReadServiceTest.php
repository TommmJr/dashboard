<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Division;
use App\Models\Indicator;
use App\Models\OrgUnit;
use App\Models\PerformanceMetric;
use App\Models\PerformanceObservation;
use App\Models\Role;
use App\Models\User;
use App\Services\Dashboard\KpiDashboardReadService;
use App\Services\KpiReportConverter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KpiDashboardReadServiceTest extends TestCase
{
    use RefreshDatabase;

    private KpiDashboardReadService $service;

    private Division $division;

    private Division $otherDivision;

    private User $admin;

    private User $management;

    private User $editor;

    private User $staff;

    private User $staffWithoutDivision;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        config(['kpi.visible_units' => null]);

        $this->service = app(KpiDashboardReadService::class);
        $this->division = Division::factory()->create(['slug' => 'perencanaan-pengusahaan', 'name' => 'Perencanaan Pengusahaan']);
        $this->otherDivision = Division::factory()->create(['slug' => 'managemen-aset', 'name' => 'Managemen Aset']);

        $this->admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);
        $this->management = User::factory()->withRole(Role::MANAGEMENT)->create(['division_id' => null]);
        $this->editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create(['division_id' => null]);
        $this->staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $this->division->id]);
        $this->staffWithoutDivision = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => null]);
    }

    private function createDataset(Division $division, string $status = Dataset::STATUS_READY): Dataset
    {
        return Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => $status,
        ]);
    }

    private function addMetric(Dataset $dataset, string $name, float $value, string $period): void
    {
        PerformanceMetric::factory()->create([
            'dataset_id' => $dataset->id,
            'division_id' => $dataset->division_id,
            'metric_name' => $name,
            'value' => $value,
            'period' => $period,
        ]);
    }

    private function addIndicator(OrgUnit $unit, string $name, array $overrides = []): Indicator
    {
        return Indicator::factory()->create(array_merge([
            'org_unit_id' => $unit->id,
            'name' => $name,
            'is_group' => false,
            'category' => null,
        ], $overrides));
    }

    private function addObservation(
        Dataset $dataset,
        OrgUnit $unit,
        Indicator $indicator,
        string $period,
        array $overrides = [],
    ): PerformanceObservation {
        return PerformanceObservation::factory()->create(array_merge([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indicator->id,
            'org_unit_id' => $unit->id,
            'division_id' => $dataset->division_id,
            'period' => $period,
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
        ], $overrides));
    }

    public function test_role_privileges_and_read_model_flag_resolution(): void
    {
        $this->assertTrue($this->service->isPrivileged($this->admin));
        $this->assertTrue($this->service->isPrivileged($this->management));
        $this->assertTrue($this->service->isPrivileged($this->editor));
        $this->assertFalse($this->service->isPrivileged($this->staff));
        $this->assertFalse($this->service->isPrivileged(null));

        config(['kpi.read_model' => 'structured']);
        $this->assertTrue($this->service->kpiReadModelStructured());

        config(['kpi.read_model' => 'legacy']);
        $this->assertFalse($this->service->kpiReadModelStructured());
    }

    public function test_normalize_excel_score_distinguishes_ratio_and_percent(): void
    {
        $this->assertEqualsWithDelta(101.43, $this->service->normalizeExcelScore(1.0143), 0.001);
        $this->assertEqualsWithDelta(110.0, $this->service->normalizeExcelScore(1.10), 0.001);
        $this->assertEqualsWithDelta(110.0, $this->service->normalizeExcelScore(110.0), 0.001);
        $this->assertEqualsWithDelta(98.5, $this->service->normalizeExcelScore(98.5), 0.001);
    }

    public function test_available_units_in_legacy_and_structured_modes_with_rbac_and_visible_scope(): void
    {
        $dsMain = $this->createDataset($this->division);
        $dsOther = $this->createDataset($this->otherDivision);
        $dsArchived = $this->createDataset($this->division, Dataset::STATUS_ARCHIVED);

        $tjbt = OrgUnit::factory()->create(['name' => 'TJBT']);
        $upb = OrgUnit::factory()->create(['name' => 'UPB']);
        $unitOther = OrgUnit::factory()->create(['name' => 'UPT_OTHER']);

        $this->addMetric($dsMain, 'TJBT | NKO', 100.5, '2026-08-31');
        $this->addMetric($dsMain, 'UPB | NKO', 97.0, '2026-08-31');
        $this->addMetric($dsOther, 'UPT_OTHER | NKO', 95.0, '2026-08-31');
        $this->addMetric($dsArchived, 'ARCHIVED_UNIT | NKO', 99.0, '2026-08-31');

        $indTjbt = $this->addIndicator($tjbt, 'Indikator A');
        $indUpb = $this->addIndicator($upb, 'Indikator B');
        $indOther = $this->addIndicator($unitOther, 'Indikator C');

        $this->addObservation($dsMain, $tjbt, $indTjbt, '2026-08-31');
        $this->addObservation($dsMain, $upb, $indUpb, '2026-08-31');
        $this->addObservation($dsOther, $unitOther, $indOther, '2026-08-31');

        config(['kpi.read_model' => 'legacy']);
        $this->assertSame(['TJBT', 'UPB', 'UPT_OTHER'], $this->service->availableUnits($this->management));
        $this->assertSame(['TJBT', 'UPB'], $this->service->availableUnits($this->staff));
        $this->assertSame([], $this->service->availableUnits($this->staffWithoutDivision));

        config(['kpi.read_model' => 'structured']);
        $this->assertSame(['TJBT', 'UPB', 'UPT_OTHER'], $this->service->availableUnits($this->management));
        $this->assertSame(['TJBT', 'UPB'], $this->service->availableUnits($this->staff));
        $this->assertSame([], $this->service->availableUnits($this->staffWithoutDivision));

        config(['kpi.visible_units' => ['TJBT']]);
        $this->assertSame(['TJBT'], $this->service->availableUnits($this->management));
    }

    public function test_period_resolvers_respect_ready_status_date_range_and_read_model_flag(): void
    {
        $dsMain = $this->createDataset($this->division);
        $dsOther = $this->createDataset($this->otherDivision);
        $dsArchived = $this->createDataset($this->division, Dataset::STATUS_ARCHIVED);

        $tjbt = OrgUnit::factory()->create(['name' => 'TJBT']);
        $groupInd = $this->addIndicator($tjbt, 'Group Ind', ['is_group' => true]);
        $leafInd = $this->addIndicator($tjbt, 'Leaf Ind', ['is_group' => false]);
        $nkoInd = $this->addIndicator($tjbt, 'NKO', ['category' => KpiReportConverter::INDICATOR_CATEGORY_NKO]);

        $this->addMetric($dsMain, 'TJBT | NKO', 100.0, '2026-06-30');
        $this->addMetric($dsOther, 'UPB | NKO', 101.0, '2026-09-30');
        $this->addMetric($dsArchived, 'TJBT | NKO', 102.0, '2026-10-31');

        $this->addObservation($dsMain, $tjbt, $groupInd, '2026-07-01', ['pencapaian' => 1.0]);
        $this->addObservation($dsMain, $tjbt, $leafInd, '2026-05-01', ['pencapaian' => 0.98]);
        $this->addObservation($dsMain, $tjbt, $nkoInd, '2026-07-01', ['nilai' => 101.2]);
        $this->addObservation($dsOther, $tjbt, $leafInd, '2026-08-01', ['pencapaian' => 1.05]);

        $this->assertSame('2026-09-30', $this->service->latestLegacyReadyPeriod($this->admin));
        $this->assertSame('2026-06-30', $this->service->latestLegacyReadyPeriod($this->staff));
        $this->assertNull($this->service->latestLegacyReadyPeriod($this->admin, '2026-10-01', '2026-12-31'));

        $this->assertSame('2026-08-01', $this->service->latestStructuredReadyPeriod($this->admin));
        $this->assertSame('2026-07-01', $this->service->latestStructuredReadyPeriod($this->staff));
        $this->assertNull($this->service->latestStructuredReadyPeriod($this->staffWithoutDivision));

        $this->assertSame('2026-08-01', $this->service->latestLegacyScoredObservationPeriod($this->admin));
        $this->assertSame('2026-05-01', $this->service->latestLegacyScoredObservationPeriod($this->staff));

        config(['kpi.read_model' => 'legacy']);
        $this->assertSame('2026-09-30', $this->service->latestReadyPeriod($this->admin));
        $this->assertSame('2026-08-01', $this->service->latestStructuredPanelPeriod($this->admin));

        config(['kpi.read_model' => 'structured']);
        $this->assertSame('2026-08-01', $this->service->latestReadyPeriod($this->admin));

        $this->assertSame('2026-07-01', $this->service->structuredNkoPeriod($this->division->id, null, null));
        $this->assertSame(['TJBT' => 101.2], $this->service->structuredNkoValues($this->division->id, '2026-07-01'));
    }

    public function test_legacy_and_structured_parity_for_nko_ranking_donut_pencapaian_summary_and_rag(): void
    {
        $ds = $this->createDataset($this->division);
        $tjbt = OrgUnit::factory()->create(['name' => 'TJBT']);
        $upb = OrgUnit::factory()->create(['name' => 'UPB']);
        $upt = OrgUnit::factory()->create(['name' => 'UPT']);

        // Legacy metrics
        $this->addMetric($ds, 'TJBT | NKO', 102.5, '2026-08-31');
        $this->addMetric($ds, 'UPB | NKO', 97.5, '2026-08-31');
        $this->addMetric($ds, 'UPT | NKO', 90.0, '2026-08-31');

        $this->addMetric($ds, 'TJBT | Indikator 1 | target', 100.0, '2026-08-31');
        $this->addMetric($ds, 'TJBT | Indikator 1 | realisasi', 105.0, '2026-08-31');
        $this->addMetric($ds, 'UPB | Indikator 2 | target', 100.0, '2026-08-31');
        $this->addMetric($ds, 'UPB | Indikator 2 | realisasi', 96.0, '2026-08-31');
        $this->addMetric($ds, 'UPT | Indikator 3 | target', 100.0, '2026-08-31');
        $this->addMetric($ds, 'UPT | Indikator 3 | realisasi', 85.0, '2026-08-31');

        // Structured observations mirroring the exact same scores/NKO
        $nkoTjbt = $this->addIndicator($tjbt, 'NKO', ['category' => KpiReportConverter::INDICATOR_CATEGORY_NKO]);
        $nkoUpb = $this->addIndicator($upb, 'NKO', ['category' => KpiReportConverter::INDICATOR_CATEGORY_NKO]);
        $nkoUpt = $this->addIndicator($upt, 'NKO', ['category' => KpiReportConverter::INDICATOR_CATEGORY_NKO]);

        $this->addObservation($ds, $tjbt, $nkoTjbt, '2026-08-31', ['nilai' => 102.5]);
        $this->addObservation($ds, $upb, $nkoUpb, '2026-08-31', ['nilai' => 97.5]);
        $this->addObservation($ds, $upt, $nkoUpt, '2026-08-31', ['nilai' => 90.0]);

        $ind1 = $this->addIndicator($tjbt, 'Indikator 1');
        $ind2 = $this->addIndicator($upb, 'Indikator 2');
        $ind3 = $this->addIndicator($upt, 'Indikator 3');

        $this->addObservation($ds, $tjbt, $ind1, '2026-08-31', ['target_value' => 100.0, 'realisasi_value' => 105.0, 'pencapaian' => 1.05]);
        $this->addObservation($ds, $upb, $ind2, '2026-08-31', ['target_value' => 100.0, 'realisasi_value' => 96.0, 'pencapaian' => 0.96]);
        $this->addObservation($ds, $upt, $ind3, '2026-08-31', ['target_value' => 100.0, 'realisasi_value' => 85.0, 'pencapaian' => 0.85]);

        config(['kpi.read_model' => 'legacy']);
        $legacyRanking = $this->service->nkoRanking($this->admin);
        $legacyDonut = $this->service->nkoDonut($this->admin);
        $legacyPencapaian = $this->service->pencapaianNko($this->admin);
        $legacySummary = $this->service->kpiSummary($this->admin);
        $legacyRag = $this->service->kpiRagStatus($this->admin);

        config(['kpi.read_model' => 'structured']);
        $structuredRanking = $this->service->nkoRanking($this->admin);
        $structuredDonut = $this->service->nkoDonut($this->admin);
        $structuredPencapaian = $this->service->pencapaianNko($this->admin);
        $structuredSummary = $this->service->kpiSummary($this->admin);
        $structuredRag = $this->service->kpiRagStatus($this->admin);

        $this->assertEquals($legacyRanking->toArray(), $structuredRanking->toArray());
        $this->assertSame($legacyDonut, $structuredDonut);
        $this->assertSame($legacyPencapaian, $structuredPencapaian);
        $this->assertSame($legacySummary, $structuredSummary);
        $this->assertSame($legacyRag, $structuredRag);

        // Explicit checks on payload values
        $this->assertSame(['TJBT', 'UPB', 'UPT'], array_column($structuredRanking->all(), 'unit'));
        $this->assertSame('2026-08-31', $structuredDonut['period']);
        $this->assertSame([
            ['name' => 'Tercapai', 'value' => 1],
            ['name' => 'Hampir Tercapai', 'value' => 1],
            ['name' => 'Perlu Peningkatan', 'value' => 1],
        ], $structuredDonut['data']);
        $this->assertSame([
            'has_data' => true,
            'avg_pencapaian' => 95.3,
            'achieved_count' => 1,
            'total_count' => 3,
        ], $structuredSummary);
        $this->assertSame([
            'period' => '2026-08-31',
            'has_data' => true,
            'counts' => [
                'tercapai' => 1,
                'hampir' => 1,
                'perlu' => 1,
                'total' => 3,
            ],
        ], $structuredRag);

        // Direct calls to structured helpers
        $this->assertEquals($structuredRanking->toArray(), $this->service->nkoRankingStructured($this->admin)->toArray());
        $this->assertSame($structuredDonut, $this->service->nkoDonutStructured($this->admin));
    }

    public function test_kinerja_realisasi_legacy_merges_observation_scores_and_tracks_ambiguity(): void
    {
        config(['kpi.read_model' => 'legacy']);

        $ds1 = $this->createDataset($this->division);
        $ds2 = $this->createDataset($this->division);
        $tjbt = OrgUnit::factory()->create(['name' => 'TJBT']);
        $upb = OrgUnit::factory()->create(['name' => 'UPB']);

        // TJBT | Ind A: reverse polarity in workbook -> Excel score 1.10 overrides raw ratio 80%
        $this->addMetric($ds1, 'TJBT | Ind A | target', 100.0, '2026-08-31');
        $this->addMetric($ds1, 'TJBT | Ind A | realisasi', 80.0, '2026-08-31');
        $indA = $this->addIndicator($tjbt, 'Ind A');
        $this->addObservation($ds1, $tjbt, $indA, '2026-08-31', ['pencapaian' => 1.10]);

        // TJBT | Ind B: injectable leaf score with no legacy metrics
        $indB = $this->addIndicator($tjbt, 'Ind B');
        $this->addObservation($ds1, $tjbt, $indB, '2026-08-31', ['pencapaian' => 0.97]);

        // UPB | Ind Ambig: appears across two datasets -> dropped & counted ambiguous
        $this->addMetric($ds1, 'UPB | Ind Ambig | target', 100.0, '2026-08-31');
        $this->addMetric($ds2, 'UPB | Ind Ambig | realisasi', 100.0, '2026-08-31');

        $result = $this->service->kinerjaRealisasi($this->admin);

        $this->assertSame('2026-08-31', $result['period']);
        $this->assertTrue($result['has_data']);
        $this->assertSame(1, $this->service->getKinerjaRealisasiAmbiguousCount());
        $this->assertCount(1, $result['rows']);
        $this->assertSame('TJBT', $result['rows'][0]['unit']);
        $this->assertSame(1, $result['rows'][0]['achieved']);
        $this->assertSame(1, $result['rows'][0]['hampir']);
        $this->assertSame(2, $result['rows'][0]['total']);
        $this->assertEqualsWithDelta(103.5, $result['rows'][0]['avg'], 0.01);
        $this->assertSame('Tercapai', $result['rows'][0]['status']);
    }

    public function test_kinerja_realisasi_structured_ignores_groups_clamps_scores_and_resets_ambiguity(): void
    {
        $ds = $this->createDataset($this->division);
        $tjbt = OrgUnit::factory()->create(['name' => 'TJBT']);

        $groupInd = $this->addIndicator($tjbt, 'Group', ['is_group' => true]);
        $leafHigh = $this->addIndicator($tjbt, 'Leaf High');
        $leafNull = $this->addIndicator($tjbt, 'Leaf Null');

        $this->addObservation($ds, $tjbt, $groupInd, '2026-08-31', ['pencapaian' => 0.50]);
        $this->addObservation($ds, $tjbt, $leafHigh, '2026-08-31', ['pencapaian' => 150.0]);
        $this->addObservation($ds, $tjbt, $leafNull, '2026-08-31', ['target_value' => 100.0, 'realisasi_value' => 90.0, 'pencapaian' => null]);

        $res = $this->service->kinerjaRealisasiStructured($this->admin);

        $this->assertSame('2026-08-31', $res['period']);
        $this->assertTrue($res['has_data']);
        $this->assertSame(1, $res['rows'][0]['total']);
        $this->assertEqualsWithDelta(110.0, $res['rows'][0]['avg'], 0.01);

        // Calling kinerjaRealisasi() resets ambiguous count from previous legacy runs
        config(['kpi.read_model' => 'structured']);
        $this->service->kinerjaRealisasi($this->admin);
        $this->assertSame(0, $this->service->getKinerjaRealisasiAmbiguousCount());
    }

    public function test_kinerja_realisasi_rows_and_apply_legacy_observation_scores_direct_contracts(): void
    {
        $rows = $this->service->kinerjaRealisasiRows('2026-08-31', [
            'UPB' => [
                'I1' => ['target' => 100.0, 'realisasi' => 96.0],
            ],
            'TJBT' => [
                'I1' => ['pencapaian' => 125.0],
                'I2' => ['target' => 100.0, 'realisasi' => 80.0],
            ],
        ]);

        $this->assertSame('2026-08-31', $rows['period']);
        $this->assertTrue($rows['has_data']);
        $this->assertSame(['UPB', 'TJBT'], array_column($rows['rows'], 'unit'));
        $this->assertEqualsWithDelta(96.0, $rows['rows'][0]['avg'], 0.01);
        $this->assertEqualsWithDelta(95.0, $rows['rows'][1]['avg'], 0.01);

        $pairs = ['TJBT' => ['Ind X' => ['target' => 10.0, 'realisasi' => 5.0]]];
        $applied = $this->service->applyLegacyObservationScores(
            $pairs,
            ['TJBT' => ['Ind X' => [1 => 1]]],
            ['TJBT' => ['Ind X' => ['target' => 1, 'realisasi' => 1]]],
            ['TJBT' => [1 => 1]],
            '2026-08-31',
            true,
            $this->admin,
        );
        $this->assertSame($pairs, $applied);
    }

    public function test_kinerja_detail_legacy_and_structured_handle_dates_ordering_filtering_and_enrichment(): void
    {
        $ds = $this->createDataset($this->division);
        $tjbt = OrgUnit::factory()->create(['name' => 'TJBT']);
        $alpha = OrgUnit::factory()->create(['name' => 'ALPHA']);

        $nkoTjbt = $this->addIndicator($tjbt, 'NKO', ['category' => KpiReportConverter::INDICATOR_CATEGORY_NKO]);
        $nkoAlpha = $this->addIndicator($alpha, 'NKO', ['category' => KpiReportConverter::INDICATOR_CATEGORY_NKO]);
        $dateInd = $this->addIndicator($tjbt, 'Date Target Ind', ['satuan' => 'Tanggal', 'polarity' => KpiReportConverter::POLARITY_LOWER_IS_BETTER]);
        $numInd = $this->addIndicator($alpha, 'Numeric Ind', ['satuan' => '%', 'polarity' => KpiReportConverter::POLARITY_HIGHER_IS_BETTER]);

        // Legacy metrics
        $this->addMetric($ds, 'TJBT | NKO', 101.5, '2026-08-31');
        $this->addMetric($ds, 'ALPHA | NKO', 98.0, '2026-08-31');
        $this->addMetric($ds, 'TJBT | Date Target Ind | target', 46200.0, '2026-08-31');
        $this->addMetric($ds, 'TJBT | Date Target Ind | realisasi', 46195.0, '2026-08-31');
        $this->addMetric($ds, 'ALPHA | Numeric Ind | target', 100.0, '2026-08-31');
        $this->addMetric($ds, 'ALPHA | Numeric Ind | realisasi', 105.0, '2026-08-31');

        // Structured observations
        $this->addObservation($ds, $tjbt, $nkoTjbt, '2026-08-31', ['nilai' => 101.5]);
        $this->addObservation($ds, $alpha, $nkoAlpha, '2026-08-31', ['nilai' => 98.0]);
        $this->addObservation($ds, $tjbt, $dateInd, '2026-08-31', [
            'target_value' => null,
            'realisasi_value' => null,
            'target_type' => PerformanceObservation::TYPE_DATE,
            'realisasi_type' => PerformanceObservation::TYPE_DATE,
            'target_date' => '2026-08-20',
            'realisasi_date' => '2026-08-15',
            'pencapaian' => 1.05,
        ]);
        $this->addObservation($ds, $alpha, $numInd, '2026-08-31', [
            'target_value' => 100.0,
            'realisasi_value' => 105.0,
            'pencapaian' => 1.05,
        ]);

        config(['kpi.read_model' => 'legacy']);
        $legacyDetail = $this->service->kinerjaDetail($this->admin);
        $this->assertSame(['TJBT', 'ALPHA'], $this->service->getKinerjaDetailUnits());
        $this->assertSame('2026-08-31', $legacyDetail['period']);
        $this->assertFalse($legacyDetail['truncated']);
        $this->assertSame(2, $legacyDetail['total_rows']);
        $this->assertSame('20/08/2026', $legacyDetail['rows'][1]['target_display']);
        $this->assertSame('15/08/2026', $legacyDetail['rows'][1]['realisasi_display']);
        $this->assertEqualsWithDelta(105.0, $legacyDetail['rows'][1]['pencapaian'], 0.01);

        config(['kpi.read_model' => 'structured']);
        $structuredDetail = $this->service->kinerjaDetail($this->admin, null, null, ['TJBT']);
        $this->assertSame(['TJBT', 'ALPHA'], $this->service->getKinerjaDetailUnits());
        $this->assertCount(1, $structuredDetail['rows']);
        $this->assertSame('TJBT', $structuredDetail['rows'][0]['unit']);
        $this->assertSame('20/08/2026', $structuredDetail['rows'][0]['target_display']);
        $this->assertEqualsWithDelta(101.5, $structuredDetail['rows'][0]['nko'], 0.01);

        $enriched = $this->service->enrichKinerjaDetailDisplayMeta($structuredDetail, $this->admin);
        $this->assertSame('Tanggal', $enriched['rows'][0]['satuan']);
        $this->assertSame(KpiReportConverter::POLARITY_LOWER_IS_BETTER, $enriched['rows'][0]['polarity']);
        $this->assertSame('2026-08-20', $enriched['rows'][0]['target_date']);

        $this->assertSame(['TJBT', 'ALPHA', 'BETA'], $this->service->kinerjaDetailUnitOrder(['BETA', 'ALPHA', 'TJBT', 'ALPHA']));
        $this->assertSame([], $this->service->kinerjaDetailDateDisplays([], '2026-08-31'));
    }

    public function test_kinerja_detail_structured_truncates_at_fifty_rows(): void
    {
        $ds = $this->createDataset($this->division);
        $tjbt = OrgUnit::factory()->create(['name' => 'TJBT']);

        for ($i = 1; $i <= 55; $i++) {
            $ind = $this->addIndicator($tjbt, sprintf('Indikator %02d', $i));
            $this->addObservation($ds, $tjbt, $ind, '2026-08-31', [
                'target_value' => 100.0,
                'realisasi_value' => 100.0,
                'pencapaian' => 1.0,
            ]);
        }

        $detail = $this->service->kinerjaDetailStructured($this->admin);

        $this->assertTrue($detail['truncated']);
        $this->assertSame(55, $detail['total_rows']);
        $this->assertCount(50, $detail['rows']);
    }

    public function test_target_realisasi_per_indikator_and_fullscreen_chart_data(): void
    {
        $emptyPanels = $this->service->targetRealisasiPerIndikator($this->admin);
        $this->assertSame(['period' => null, 'units' => [], 'sections' => []], $emptyPanels);

        $ds = $this->createDataset($this->division);
        $tjbt = OrgUnit::factory()->create(['name' => 'TJBT']);
        $ind = $this->addIndicator($tjbt, 'Susut Transmisi', [
            'satuan' => '%',
            'polarity' => KpiReportConverter::POLARITY_LOWER_IS_BETTER,
            'bobot' => 15.0,
            'pencapaian_formula_kind' => KpiReportConverter::PENCAPAIAN_FORMULA_POLARITY_RATIO,
        ]);
        $this->addObservation($ds, $tjbt, $ind, '2026-08-31', [
            'target_value' => 2.5,
            'realisasi_value' => 2.1,
            'pencapaian' => 1.08,
        ]);

        // Must still read structured observations even when read_model = legacy
        config(['kpi.read_model' => 'legacy']);
        $panels = $this->service->targetRealisasiPerIndikator($this->admin);
        $this->assertSame('2026-08-31', $panels['period']);
        $this->assertSame(['TJBT'], $panels['units']);
        $this->assertNotEmpty($panels['sections']);

        $detail = $this->service->kinerjaDetailStructured($this->admin, null, null, ['TJBT']);
        $fullscreen = $this->service->kpiFullscreenChartData($panels, $detail, ['TJBT']);

        $this->assertSame('2026-08-31', $fullscreen['period']);
        $this->assertSame('TJBT', $fullscreen['unitLabel']);
        $this->assertArrayHasKey('tercapai_min', $fullscreen['ragThresholds']);
        $this->assertCount(1, $fullscreen['indicators']);
        $this->assertSame('Susut Transmisi', $fullscreen['indicators'][0]['indikator']);
        $this->assertSame('Negatif', $fullscreen['indicators'][0]['polaritas']);
        $this->assertSame('15', rtrim(rtrim($fullscreen['indicators'][0]['bobot'], '0'), '.') ?: $fullscreen['indicators'][0]['bobot']);
        $this->assertSame('Tercapai', $fullscreen['indicators'][0]['status']);

        // Fallback to kinerjaDetailData['rows'] when targetRealisasiData has no sections
        $fallback = $this->service->kpiFullscreenChartData(
            ['period' => '2026-08-31', 'units' => ['TJBT'], 'sections' => []],
            $detail,
            [],
        );
        $this->assertCount(1, $fallback['indicators']);
        $this->assertSame('Umum', $fallback['indicators'][0]['group']);
    }
}
