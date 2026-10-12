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

        // Baris target/realisasi R7 tetap dari performance_metrics (legacy);
        // hanya NKO dari observasi terstruktur.
        $this->metric($dataset, $division, 'TJBT | A | target', 200.0);
        $this->metric($dataset, $division, 'TJBT | A | realisasi', 100.0);
        $this->metric($dataset, $division, 'MES | B | target', 10.0);
        $this->metric($dataset, $division, 'MES | B | realisasi', 5.0);

        $this->observe($dataset, $division, 'TJBT', 105.5);
        $this->observe($dataset, $division, 'MES', 100.0);

        $result = $this->reader('kinerjaDetail', [$this->management()]);

        $this->assertSame(['TJBT'], array_column($result['rows'], 'unit'));
        $this->assertSame(105.5, $result['rows'][0]['nko']);
    }

    // ------------------------------------------------------------------ R8 ----

    public function test_r8_labels_hanya_tjbt_dan_agregasi_dalam_unit_utuh(): void
    {
        $division = $this->division();
        $dataset = $this->readyDataset($division);

        // TJBT: 2 indikator -> target 150, realisasi 130 (agregasi lintas
        // indikator C-1 tetap dijumlahkan APA ADANYA di dalam unit).
        $this->metric($dataset, $division, 'TJBT | A | target', 100.0);
        $this->metric($dataset, $division, 'TJBT | A | realisasi', 80.0);
        $this->metric($dataset, $division, 'TJBT | B | target', 50.0);
        $this->metric($dataset, $division, 'TJBT | B | realisasi', 50.0);

        // MES harus hilang, dan TIDAK boleh mengubah total TJBT.
        $this->metric($dataset, $division, 'MES | C | target', 999.0);
        $this->metric($dataset, $division, 'MES | C | realisasi', 999.0);

        $result = $this->reader('targetVsRealisasi', [$this->management()]);

        $this->assertSame(['TJBT'], $result['labels']);
        $this->assertSame([150.0], $result['target']);
        $this->assertSame([130.0], $result['realisasi']);
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
}
