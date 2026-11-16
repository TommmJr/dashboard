<?php

namespace Tests\Feature;

use App\Http\Controllers\DashboardController;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\Indicator;
use App\Models\OrgUnit;
use App\Models\PerformanceMetric;
use App\Models\PerformanceObservation;
use App\Models\Role;
use App\Models\User;
use App\Services\KpiReportConverter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Batas unit TAMPIL dashboard KPI (keputusan bisnis 2026-10-12).
 *
 * Kontrak yang dikunci:
 *
 * 1. Hanya unit di `config('kpi.visible_units')` (default `['TJBT']`) yang
 *    muncul di SETIAP read-path aktif: R1 (chart API), R2 (dropdown unit),
 *    R3 (peringkat NKO), R6/R9 (realisasi vs target), R7 (detail metrik +
 *    dropdown), R8 (target vs realisasi), R10 (distribusi NKO).
 * 2. Pembatasan HANYA soal TAMPILAN: baris unit lain tetap ADA di DB
 *    (riwayat tidak dihapus) dan impor unit lain tidak ditolak.
 * 3. Filter diterapkan pada batas PRESENTASI — SETELAH pemilihan periode dan
 *    SETELAH pemilihan dataset terbaru — sehingga "latest dataset wins" tidak
 *    berubah, dan agregasi DI DALAM unit yang tersisa (mis. C-1 R8) tetap utuh.
 * 4. Identitas unit CASE-SENSITIVE: `'tjbt'` bukan `'TJBT'`.
 * 5. `kpi.visible_units = null` (atau []) mengembalikan perilaku lama
 *    (tanpa pembatasan) — jaring rollback.
 */
class KpiUnitScopeTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-08-31';

    protected function setUp(): void
    {
        parent::setUp();
        // Default produksi kini 'structured' (Fase 4.3 Tahap B). Test ini
        // mengunci jalur legacy, jadi mode legacy ditetapkan eksplisit.
        config(['kpi.read_model' => 'legacy']);

        $this->seed(RolePermissionSeeder::class);
    }

    // ------------------------------------------------------------- helpers --

    /**
     * Panggil method private DashboardController tanpa mengubah aksesibilitas
     * permanen. Instance-nya bisa diberikan supaya properti yang diisi sebagai
     * efek samping (mis. `kinerjaDetailUnits`) bisa dibaca dari instance yang
     * sama.
     */
    private function reader(string $method, array $args = [], ?DashboardController $controller = null): mixed
    {
        $controller ??= app(DashboardController::class);
        $reflection = new ReflectionMethod($controller, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($controller, ...$args);
    }

    /**
     * Baca properti private dari instance controller yang diberikan.
     */
    private function property(DashboardController $controller, string $name): mixed
    {
        $reflection = new \ReflectionProperty($controller, $name);
        $reflection->setAccessible(true);

        return $reflection->getValue($controller);
    }

    private function division(string $name = 'Pengusahaan'): Division
    {
        return Division::factory()->create(['name' => $name]);
    }

    private function management(): User
    {
        return User::factory()->withRole(Role::MANAGEMENT)->create();
    }

    private function admin(): User
    {
        return User::factory()->withRole(Role::ADMIN)->create();
    }

    private function readyDataset(Division $division): Dataset
    {
        return Dataset::factory()->create(['division_id' => $division->id]);
    }

    private function metric(Dataset $dataset, Division $division, string $name, float $value, string $period = self::PERIOD): PerformanceMetric
    {
        return PerformanceMetric::factory()->create([
            'dataset_id' => $dataset->id,
            'division_id' => $division->id,
            'metric_name' => $name,
            'value' => $value,
            'period' => $period,
        ]);
    }

    /**
     * Baris NKO legacy (`<unit> | NKO`) seperti yang ditulis importer.
     */
    private function legacyNko(Dataset $dataset, Division $division, string $unit, float $value, string $period = self::PERIOD): void
    {
        $this->metric($dataset, $division, $unit.KpiReportConverter::NKO_SUFFIX, $value, $period);
    }

    /**
     * Baris NKO terstruktur (indikator kanonis berkategori 'nko').
     */
    private function observe(Dataset $dataset, Division $division, string $unit, float $value, string $period = self::PERIOD): PerformanceObservation
    {
        $orgUnit = OrgUnit::firstOrCreate(['name' => $unit]);

        $indicator = Indicator::firstOrCreate(
            ['org_unit_id' => $orgUnit->id, 'name' => KpiReportConverter::NKO_LABEL],
            ['category' => KpiReportConverter::INDICATOR_CATEGORY_NKO, 'bobot' => null],
        );

        return PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indicator->id,
            'org_unit_id' => $orgUnit->id,
            'division_id' => $division->id,
            'period' => $period,
            'nilai' => $value,
            'source_sheet' => $unit,
        ]);
    }

    /**
     * Observasi target/realisasi numerik (untuk R6 terstruktur).
     */
    private function observeNumeric(Dataset $dataset, Division $division, string $unit, float $target, float $realisasi, string $period = self::PERIOD): PerformanceObservation
    {
        $orgUnit = OrgUnit::firstOrCreate(['name' => $unit]);

        $indicator = Indicator::firstOrCreate(
            ['org_unit_id' => $orgUnit->id, 'name' => 'Indikator '.$unit],
            ['category' => 'kinerja', 'bobot' => null],
        );

        return PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indicator->id,
            'org_unit_id' => $orgUnit->id,
            'division_id' => $division->id,
            'period' => $period,
            'target_value' => $target,
            'realisasi_value' => $realisasi,
            'pencapaian' => $target > 0 ? $realisasi / $target : null,
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'source_sheet' => $unit,
        ]);
    }

    // --------------------------------------------------- konfigurasi default --

    public function test_config_default_membatasi_ke_tjbt(): void
    {
        $this->assertSame(['TJBT'], config('kpi.visible_units'));
    }

    // ------------------------------------------------------------------ R2 ----

    public function test_r2_legacy_hanya_tjbt(): void
    {
        $division = $this->division();
        $dataset = $this->readyDataset($division);

        $this->legacyNko($dataset, $division, 'TJBT', 105.5);
        $this->legacyNko($dataset, $division, 'MES', 100.0);
        $this->legacyNko($dataset, $division, 'RST', 99.0);

        $this->assertSame(['TJBT'], $this->reader('availableUnits', [$this->management()]));
    }

    public function test_r2_structured_hanya_tjbt(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division();
        $dataset = $this->readyDataset($division);

        $this->observe($dataset, $division, 'TJBT', 105.5);
        $this->observe($dataset, $division, 'MES', 100.0);

        $this->assertSame(['TJBT'], $this->reader('availableUnits', [$this->management()]));
    }

    // ------------------------------------------------------------------ R3 ----

    public function test_r3_legacy_hanya_tjbt(): void
    {
        $division = $this->division();
        $dataset = $this->readyDataset($division);

        $this->legacyNko($dataset, $division, 'TJBT', 105.5);
        $this->legacyNko($dataset, $division, 'MES', 96.0);

        $rows = $this->reader('nkoRanking', [$this->management()]);

        $this->assertSame(['TJBT'], $rows->pluck('unit')->all());
    }

    public function test_r3_structured_hanya_tjbt(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division();
        $dataset = $this->readyDataset($division);

        $this->observe($dataset, $division, 'TJBT', 105.5);
        $this->observe($dataset, $division, 'MES', 96.0);

        $rows = $this->reader('nkoRanking', [$this->management()]);

        $this->assertSame(['TJBT'], $rows->pluck('unit')->all());
    }

    // ------------------------------------------------------------------ R6 ----

    public function test_r6_legacy_hanya_tjbt(): void
    {
        $division = $this->division();
        $dataset = $this->readyDataset($division);

        $this->metric($dataset, $division, 'TJBT | A | target', 100.0);
        $this->metric($dataset, $division, 'TJBT | A | realisasi', 100.0);
        $this->metric($dataset, $division, 'TJBT | B | target', 100.0);
        $this->metric($dataset, $division, 'TJBT | B | realisasi', 110.0);

        $this->metric($dataset, $division, 'MES | C | target', 100.0);
        $this->metric($dataset, $division, 'MES | C | realisasi', 90.0);

        $result = $this->reader('kinerjaRealisasi', [$this->management()]);

        $this->assertSame(['TJBT'], array_column($result['rows'], 'unit'));
        $this->assertSame(2, $result['rows'][0]['total']);
        $this->assertSame(2, $result['rows'][0]['achieved']);
        $this->assertSame(105.0, $result['rows'][0]['avg']);
    }

    public function test_r6_structured_hanya_tjbt(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division();
        $dataset = $this->readyDataset($division);

        $this->observeNumeric($dataset, $division, 'TJBT', 100.0, 100.0);
        $this->observeNumeric($dataset, $division, 'MES', 100.0, 90.0);

        $result = $this->reader('kinerjaRealisasi', [$this->management()]);

        $this->assertSame(['TJBT'], array_column($result['rows'], 'unit'));
    }

    // ------------------------------------------------------------------ R7 ----

    public function test_r7_legacy_baris_dan_dropdown_hanya_tjbt(): void
    {
        $division = $this->division();
        $dataset = $this->readyDataset($division);

        $this->metric($dataset, $division, 'TJBT | A | target', 200.0);
        $this->metric($dataset, $division, 'TJBT | A | realisasi', 100.0);
        $this->metric($dataset, $division, 'TJBT | NKO', 105.5);
        $this->metric($dataset, $division, 'MES | B | target', 10.0);
        $this->metric($dataset, $division, 'MES | B | realisasi', 5.0);
        $this->metric($dataset, $division, 'MES | NKO', 100.0);

        $controller = app(DashboardController::class);
        $result = $this->reader('kinerjaDetail', [$this->management()], $controller);

        $this->assertSame(['TJBT'], array_column($result['rows'], 'unit'));
        $this->assertSame(1, $result['total_rows']);
        // Dropdown (properti terpisah pada instance yang sama) juga hanya TJBT.
        $this->assertSame(['TJBT'], $this->property($controller, 'kinerjaDetailUnits'));
    }

    public function test_r7_structured_hanya_tjbt(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division();
        $dataset = $this->readyDataset($division);

        $this->observeNumeric($dataset, $division, 'TJBT', 200.0, 100.0);
        $this->observeNumeric($dataset, $division, 'MES', 10.0, 5.0);

        $this->observe($dataset, $division, 'TJBT', 105.5);
        $this->observe($dataset, $division, 'MES', 100.0);

        $controller = app(DashboardController::class);
        $result = $this->reader('kinerjaDetail', [$this->management()], $controller);

        $this->assertSame(['TJBT'], array_column($result['rows'], 'unit'));
        $this->assertSame(105.5, $result['rows'][0]['nko']);
        $this->assertSame(['TJBT'], $this->property($controller, 'kinerjaDetailUnits'));
    }

    // ----------------------------------------------------------------- R10 ----

    public function test_r10_legacy_hanya_tjbt(): void
    {
        $division = $this->division();
        $dataset = $this->readyDataset($division);

        // TJBT 101 -> Tercapai; MES 96 -> Hampir Tercapai (harus hilang).
        $this->legacyNko($dataset, $division, 'TJBT', 101.0);
        $this->legacyNko($dataset, $division, 'MES', 96.0);

        $result = $this->reader('nkoDonut', [$this->management()]);

        $this->assertSame([['name' => 'Tercapai', 'value' => 1]], $result['data']);
    }

    public function test_r10_structured_hanya_tjbt(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division();
        $dataset = $this->readyDataset($division);

        $this->observe($dataset, $division, 'TJBT', 101.0);
        $this->observe($dataset, $division, 'MES', 96.0);

        $result = $this->reader('nkoDonut', [$this->management()]);

        $this->assertSame([['name' => 'Tercapai', 'value' => 1]], $result['data']);
    }

    // ------------------------------------------------------------------ R1 ----

    public function test_r1_http_legacy_hanya_tjbt(): void
    {
        $division = $this->division();
        $dataset = $this->readyDataset($division);

        $this->legacyNko($dataset, $division, 'TJBT', 105.5);
        $this->legacyNko($dataset, $division, 'MES', 96.0);

        $names = array_column(
            $this->actingAs($this->management())->getJson(route('performance.data'))->assertOk()->json('series'),
            'name',
        );

        $this->assertSame(['TJBT'], $names);
    }

    public function test_r1_http_structured_hanya_tjbt(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division();
        $dataset = $this->readyDataset($division);

        $this->observe($dataset, $division, 'TJBT', 105.5);
        $this->observe($dataset, $division, 'MES', 96.0);

        $names = array_column(
            $this->actingAs($this->management())->getJson(route('performance.data'))->assertOk()->json('series'),
            'name',
        );

        $this->assertSame(['TJBT'], $names);
    }

    // ------------------------------------- latest-wins + urutan penerapan ----

    public function test_latest_dataset_wins_tetap_berlaku_dengan_filter(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division();
        $older = Dataset::factory()->create(['division_id' => $division->id, 'created_at' => now()->subDays(2)]);
        $newer = Dataset::factory()->create(['division_id' => $division->id, 'created_at' => now()]);

        $this->observe($older, $division, 'TJBT', 100.0);
        $this->observe($newer, $division, 'TJBT', 111.0);
        $this->observe($newer, $division, 'MES', 999.0);

        $json = $this->actingAs($this->management())->getJson(route('performance.data'))->assertOk()->json();

        $this->assertSame(['TJBT'], array_column($json['series'], 'name'));
        $this->assertEquals([111.0], array_map('floatval', $json['series'][0]['data']));
    }

    public function test_filter_diterapkan_setelah_pemilihan_dataset_terbaru(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division();
        $older = Dataset::factory()->create(['division_id' => $division->id, 'created_at' => now()->subDays(2)]);
        $newer = Dataset::factory()->create(['division_id' => $division->id, 'created_at' => now()]);

        // Dataset terbaru TIDAK memuat TJBT (hanya unit di luar scope).
        // Pemilihan dataset tidak boleh melihat unit: dataset terbaru tetap
        // otoritatif, sehingga TJBT dari dataset lama TIDAK dihidupkan lagi.
        $this->observe($older, $division, 'TJBT', 100.0);
        $this->observe($newer, $division, 'MES', 999.0);

        $json = $this->actingAs($this->management())->getJson(route('performance.data'))->assertOk()->json();

        $this->assertSame([], $json['series']);
        $this->assertSame([], $json['labels']);
    }

    // ------------------------------------------- riwayat & case-sensitivity --

    public function test_unit_di_luar_scope_tidak_dihapus_dari_db(): void
    {
        $division = $this->division();
        $dataset = $this->readyDataset($division);

        $this->legacyNko($dataset, $division, 'TJBT', 105.5);
        $this->legacyNko($dataset, $division, 'MES', 96.0);

        $this->reader('nkoRanking', [$this->management()]);
        $this->actingAs($this->management())->getJson(route('performance.data'))->assertOk();

        // Pembatasan = TAMPILAN saja: baris MES tetap ada.
        $this->assertDatabaseHas('performance_metrics', [
            'metric_name' => 'MES'.KpiReportConverter::NKO_SUFFIX,
        ]);
    }

    public function test_identitas_unit_case_sensitive(): void
    {
        $division = $this->division();
        $dataset = $this->readyDataset($division);

        // 'tjbt' huruf kecil BUKAN 'TJBT' (collation org_units = utf8mb4_bin).
        $this->legacyNko($dataset, $division, 'tjbt', 105.5);
        $this->legacyNko($dataset, $division, 'TJBT', 100.0);

        $this->assertSame(['TJBT'], $this->reader('availableUnits', [$this->management()]));
    }

    // ------------------------------------------------------------- rollback --

    public function test_rollback_visible_units_null_mengembalikan_semua_unit(): void
    {
        config(['kpi.visible_units' => null]);

        $division = $this->division();
        $dataset = $this->readyDataset($division);

        $this->legacyNko($dataset, $division, 'TJBT', 105.5);
        $this->legacyNko($dataset, $division, 'MES', 96.0);

        $rows = $this->reader('nkoRanking', [$this->management()]);

        $this->assertSame(['MES', 'TJBT'], $rows->pluck('unit')->sort()->values()->all());
    }

    // ------------------------------- kartu KPI R1 + R5 (kontrak sesi 36) -----
    //
    // metric_count : baris unit TAMPIL, semua periode READY, tanpa ASET.
    // ready_datasets: jumlah dataset READY per divisi, TIDAK difilter unit.
    // latest_rows  : baris unit TAMPIL pada periode terbaru; periode terbaru
    //                ditentukan TANPA filter unit.
    // Kontrak teknis sementara, BUKAN definisi KPI yang disahkan PLN.

    /**
     * Fixture campuran: D1 (TJBT 2 periode + MES), D2 (hanya MES),
     * D3 ARCHIVED (TJBT), D4 (ASET), plus varian nama yang mirip TJBT.
     */
    private function mixedKpiFixture(Division $division): void
    {
        $d1 = $this->readyDataset($division);
        $this->legacyNko($d1, $division, 'TJBT', 105.5);
        $this->legacyNko($d1, $division, 'TJBT', 101.0, '2026-07-31');
        $this->legacyNko($d1, $division, 'MES', 96.0);
        $this->metric($d1, $division, 'tjbt | NKO', 1.0);
        $this->metric($d1, $division, 'TJBTX | NKO', 1.0);

        $d2 = $this->readyDataset($division);
        $this->legacyNko($d2, $division, 'MES', 97.0);
        $this->legacyNko($d2, $division, 'RST', 98.0);

        $d3 = Dataset::factory()->archived()->create(['division_id' => $division->id]);
        $this->legacyNko($d3, $division, 'TJBT', 50.0);

        $d4 = $this->readyDataset($division);
        $this->metric($d4, $division, 'ASET | TJBT | Trafo', 10.0);
    }

    private function kpiDivision(): Division
    {
        return Division::firstOrCreate(
            ['slug' => 'perencanaan-pengusahaan'],
            ['name' => 'Perencanaan Pengusahaan'],
        );
    }

    public function test_kpi_card_legacy_metric_count_unit_tampil_ready_datasets_per_divisi(): void
    {
        $this->mixedKpiFixture($this->kpiDivision());

        $kpi = $this->actingAs($this->management())->getJson(route('performance.data'))->assertOk()->json('kpi');

        // Hanya 2 baris TJBT di D1 (dua periode). MES/RST/tjbt/TJBTX, baris
        // dataset ARCHIVED, dan baris ASET tidak dihitung.
        $this->assertSame(2, $kpi['metric_count']);
        // D1 + D2 (D2 hanya non-TJBT tetap dihitung); D3 ARCHIVED dan D4 ASET tidak.
        $this->assertSame(2, $kpi['ready_datasets']);
        // average_nko tetap hanya TJBT periode terbaru.
        $this->assertSame(105.5, (float) $kpi['average_nko']);
    }

    public function test_kpi_card_structured_identik_dengan_legacy(): void
    {
        $this->mixedKpiFixture($this->kpiDivision());

        $legacy = $this->actingAs($this->management())->getJson(route('performance.data'))->assertOk()->json('kpi');

        config(['kpi.read_model' => 'structured']);
        $structured = $this->actingAs($this->management())->getJson(route('performance.data'))->assertOk()->json('kpi');

        $this->assertSame($legacy['metric_count'], $structured['metric_count']);
        $this->assertSame($legacy['ready_datasets'], $structured['ready_datasets']);
    }

    public function test_kpi_card_rollback_null_menghitung_semua_unit(): void
    {
        config(['kpi.visible_units' => null]);
        $this->mixedKpiFixture($this->kpiDivision());

        $kpi = $this->actingAs($this->management())->getJson(route('performance.data'))->assertOk()->json('kpi');

        // D1 5 baris + D2 2 baris; ARCHIVED dan ASET tetap dikecualikan.
        $this->assertSame(7, $kpi['metric_count']);
        $this->assertSame(2, $kpi['ready_datasets']);
    }

    public function test_kpi_card_staf_divisi_hanya_divisi_sendiri(): void
    {
        $own = $this->kpiDivision();
        $other = $this->division('Other');

        $ownDataset = $this->readyDataset($own);
        $this->legacyNko($ownDataset, $own, 'TJBT', 105.5);

        $otherDataset = $this->readyDataset($other);
        $this->legacyNko($otherDataset, $other, 'TJBT', 99.0);
        $this->legacyNko($otherDataset, $other, 'TJBT', 98.0, '2026-07-31');

        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $own->id]);
        $kpi = $this->actingAs($staff)->getJson(route('performance.data'))->assertOk()->json('kpi');

        $this->assertSame(1, $kpi['metric_count']);
        $this->assertSame(1, $kpi['ready_datasets']);

        $status = $this->reader('datasetStatus', [$staff]);
        $this->assertSame(1, $status['latest_rows']);
    }

    public function test_r5_latest_rows_hanya_unit_tampil_periode_terbaru(): void
    {
        $division = $this->division();
        $dataset = $this->readyDataset($division);

        $this->metric($dataset, $division, 'TJBT | A | target', 100.0);
        $this->metric($dataset, $division, 'TJBT | A | realisasi', 90.0);
        $this->metric($dataset, $division, 'MES | B | target', 100.0);
        $this->metric($dataset, $division, 'MES | B | realisasi', 90.0);
        $this->metric($dataset, $division, 'tjbt | NKO', 1.0);
        // Periode lama TJBT tidak ikut dihitung.
        $this->legacyNko($dataset, $division, 'TJBT', 100.0, '2026-07-31');

        $status = $this->reader('datasetStatus', [$this->management()]);

        $this->assertSame(self::PERIOD, $status['latest_period']);
        $this->assertSame(2, $status['latest_rows']);
    }

    public function test_r5_periode_terbaru_tidak_ikut_filter_unit(): void
    {
        $division = $this->division();
        $older = $this->readyDataset($division);
        $this->legacyNko($older, $division, 'TJBT', 105.5, '2026-07-31');

        // Periode terbaru hanya memuat unit di luar scope.
        $newer = $this->readyDataset($division);
        $this->legacyNko($newer, $division, 'MES', 96.0);

        $status = $this->reader('datasetStatus', [$this->management()]);

        $this->assertSame(self::PERIOD, $status['latest_period']);
        $this->assertSame(0, $status['latest_rows']);
        $this->assertSame(2, $status['ready']);
    }

    public function test_r5_rollback_null_menghitung_semua_unit(): void
    {
        config(['kpi.visible_units' => null]);

        $division = $this->division();
        $dataset = $this->readyDataset($division);
        $this->legacyNko($dataset, $division, 'TJBT', 105.5);
        $this->legacyNko($dataset, $division, 'MES', 96.0);

        $this->assertSame(2, $this->reader('datasetStatus', [$this->management()])['latest_rows']);
    }

    // -------------------------------------------------------- HTML smoke -----

    public function test_dashboard_html_nko_ranking_hanya_tjbt(): void
    {
        $division = $this->division();
        $dataset = $this->readyDataset($division);

        $this->legacyNko($dataset, $division, 'TJBT', 105.5);
        $this->legacyNko($dataset, $division, 'MES', 96.0);

        // Layout management memasang widget nko-ranking.
        $response = $this->actingAs($this->management())->get(route('dashboard'))->assertOk();

        $response->assertSee('TJBT');
        $response->assertDontSee('MES');
    }

    public function test_dashboard_renders_dynamic_unit_scope_label(): void
    {
        config(['kpi.visible_units' => ['TJBT', 'UPT-SMG']]);

        $this->actingAs($this->management())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('TJBT, UPT-SMG');

        $division = $this->division();
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);

        $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('TJBT, UPT-SMG');
    }
}
