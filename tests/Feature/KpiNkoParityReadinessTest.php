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
use App\Services\StructuredKpiImporter;
use App\Services\StructuredNkoReader;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * PARITY NKO terstruktur vs legacy
 *
 * Sumber NKO sekarang NYATA: indikator berkategori 'nko' + kolom
 * performance_observations.nilai, diisi StructuredKpiImporter dari nilai
 * mentah workbook.
 *
 * Yang diuji di sini:
 *
 *   1. Paritas nilai pada satu dataset READY.
 *   2. Aturan "data terbaru" saat beberapa dataset READY punya periode sama.
 *   3. Versi ganda dipertahankan (tidak digabung, tidak dihapus).
 *   4. Dataset ARCHIVED dikecualikan.
 *   5. Scoping divisi.
 *   6. Konflik periode form vs banner (provenance).
 *   7. Idempotensi importer.
 *
 * DIVERGENSI YANG SAH — jangan "diperbaiki" tanpa keputusan baru:
 *
 *   - R1 terstruktur TIDAK menampilkan baris legacy tanpa dataset
 *     (dataset_id NULL): aturan data terbaru bersandar pada dataset.
 *   - Beberapa dataset READY memuat periode yang sama: terstruktur menampilkan
 *     dataset terbaru saja, legacy menampilkan semuanya (R1 lewat MAX).
 */
class KpiNkoParityReadinessTest extends TestCase
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

        // Parity legacy vs terstruktur diuji dengan nama unit stand-in bebas.
        // Batas unit TAMPIL (keputusan 2026-10-12, `kpi.visible_units`) dimatikan
        // supaya stand-in non-TJBT tetap valid; batas itu diuji di
        // `KpiUnitScopeTest`.
        config(['kpi.visible_units' => null]);
    }

    // ------------------------------------------------------------------
    // 1. Paritas nilai pada satu dataset READY
    // ------------------------------------------------------------------

    public function test_paritas_nilai_nko_satu_dataset_ready(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->legacyNko($dataset, $division, 'TJBT', 105.5);
        $this->structuredNko($dataset, $division, 'TJBT', 105.5);

        $user = $this->management();

        $legacyRanking = $this->reader('nkoRanking', [$user]);
        $structuredRanking = $this->structured(fn () => $this->reader('nkoRanking', [$user]));

        $this->assertSame($legacyRanking->pluck('unit')->all(), $structuredRanking->pluck('unit')->all());
        $this->assertSame(
            (float) $legacyRanking->first()->nko,
            (float) $structuredRanking->first()->nko,
        );
    }

    public function test_paritas_donut_satu_dataset_ready(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        foreach ([['TJBT', 105.5], ['RST', 97.0], ['MES', 80.0]] as [$unit, $value]) {
            $this->legacyNko($dataset, $division, $unit, $value);
            $this->structuredNko($dataset, $division, $unit, $value);
        }

        $user = $this->management();

        $legacy = $this->reader('nkoDonut', [$user]);
        $structured = $this->structured(fn () => $this->reader('nkoDonut', [$user]));

        $this->assertSame($legacy, $structured);
    }

    public function test_paritas_chart_r1_satu_dataset_ready(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->legacyNko($dataset, $division, 'TJBT', 105.5);
        $this->structuredNko($dataset, $division, 'TJBT', 105.5);

        $user = $this->management();

        $legacy = $this->chart($user);
        $structured = $this->structured(fn () => $this->chart($user));

        // kpi.* dihitung dari performance_metrics di kedua branch, jadi utuh.
        $this->assertSame($legacy['labels'], $structured['labels']);
        $this->assertSame($legacy['series'], $structured['series']);
        $this->assertSame($legacy['y_axis_min'], $structured['y_axis_min']);
        $this->assertSame($legacy['kpi'], $structured['kpi']);
    }

    public function test_paritas_kolom_nko_r7_satu_dataset_ready(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->legacyMetric($dataset, $division, 'TJBT | Indikator A | target', 200.0);
        $this->legacyMetric($dataset, $division, 'TJBT | Indikator A | realisasi', 100.0);
        $this->structuredObservation($dataset, $division, 'TJBT', 'Indikator A', 200.0, 100.0);
        $this->legacyNko($dataset, $division, 'TJBT', 105.5);
        $this->structuredNko($dataset, $division, 'TJBT', 105.5);

        $user = $this->management();

        $legacy = $this->reader('kinerjaDetail', [$user]);
        $structured = $this->structured(fn () => $this->reader('kinerjaDetail', [$user]));

        $this->assertSame($legacy, $structured);
        $this->assertSame(105.5, $structured['rows'][0]['nko']);
    }

    // ------------------------------------------------------------------
    // 2. Aturan "data terbaru": beberapa dataset READY, periode sama
    // ------------------------------------------------------------------

    /**
     * Keputusan Pak Gama 2026-10-07: nilai NKO mengikuti data sumber apa
     * adanya — tidak dibulatkan. Precision 4 desimal harus bertahan sampai
     * reader (R3). Legacy sudah membulatkan ke 2 desimal; yang diuji di sini
     * adalah kontrak sisi structured.
     */
    public function test_precision_nko_structured_setara_sumber(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);
        $this->structuredNko($dataset, $division, 'TJBT', 99.9876);

        $stored = PerformanceObservation::whereHas(
            'indicator',
            fn ($q) => $q->where('category', 'nko'),
        )->sole();

        $this->assertSame('99.9876', (string) $stored->nilai);

        $ranking = $this->structured(fn () => $this->reader('nkoRanking', [$this->management()]));

        $this->assertSame(99.9876, (float) $ranking->first()->nko);
    }

    public function test_precision_nko_structured_tetap_raw_di_r1_dan_threshold_r10(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);
        $this->structuredNko($dataset, $division, 'TJBT', 99.9999);
        $user = $this->management();

        $chart = $this->structured(fn () => $this->chart($user));
        $this->assertEquals([99.9999], $chart['series'][0]['data']);
        $this->assertEquals(90, $chart['y_axis_min']);

        $donut = $this->structured(fn () => $this->reader('nkoDonut', [$user]));
        // Tahap 1: ragTier() mengevaluasi round(99.9999, 1) = 100.0 yang tampil sebagai 100,0% -> Tercapai.
        $this->assertSame([['name' => 'Tercapai', 'value' => 1]], $donut['data']);
    }

    /**
     * Keputusan Pak Gama 2026-10-07: kapitalisasi nama unit dipertahankan,
     * jadi TJBT dan tjbt adalah DUA unit berbeda. C8 (structured R7 mencari
     * NKO per unit dengan lookup exact): TJBT tidak boleh menerima NKO
     * milik tjbt dan sebaliknya. Bug lama pada MySQL (collation ci): lookup
     * ini digabung lalu satu baris kehilangan NKO-nya.
     */
    public function test_r7_structured_tidak_menggabungkan_case_variant_unit(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->structuredObservation($dataset, $division, 'TJBT', 'Indikator A', 200.0, 100.0);
        $this->structuredObservation($dataset, $division, 'tjbt', 'Indikator A', 200.0, 100.0);
        $this->structuredNko($dataset, $division, 'TJBT', 105.5);
        $this->structuredNko($dataset, $division, 'tjbt', 80.0);

        // Kedua identitas tetap dua baris; unique(name) tidak mengabungkannya.
        $this->assertSame(['TJBT', 'tjbt'], OrgUnit::orderBy('name')->pluck('name')->all());

        $detail = $this->structured(fn () => $this->reader('kinerjaDetail', [$this->management()]));
        $byUnit = collect($detail['rows'])->keyBy('unit');

        // Masing-masing unit mengambil NKO miliknya sendiri, bukan null.
        $this->assertSame(105.5, $byUnit['TJBT']['nko']);
        $this->assertSame(80.0, $byUnit['tjbt']['nko']);
    }

    /**
     * Visualisasi memakai periode terbaru, tetapi periode lama tidak boleh
     * dihapus: keduanya harus tetap tersimpan dan dapat di-query.
     */
    public function test_semua_periode_tersimpan_walau_visualisasi_periode_terbaru(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->structuredNko($dataset, $division, 'TJBT', 90.0, '2026-07-31');
        $this->structuredNko($dataset, $division, 'TJBT', 105.5, '2026-08-31');

        $this->assertSame(2, PerformanceObservation::whereHas(
            'indicator',
            fn ($q) => $q->where('category', 'nko'),
        )->count());

        $ranking = $this->structured(fn () => $this->reader('nkoRanking', [$this->management()]));

        $this->assertSame(105.5, (float) $ranking->first()->nko);
    }

    public function test_dataset_terbaru_menang_saat_periode_sama(): void
    {
        $division = $this->division('Pengusahaan');

        // Dataset lama (dibuat lebih dulu) -> NKO 105.5.
        $older = Dataset::factory()->create([
            'division_id' => $division->id,
            'created_at' => now()->subDays(2),
        ]);
        $this->legacyNko($older, $division, 'TJBT', 105.5);
        $this->structuredNko($older, $division, 'TJBT', 105.5);

        // Dataset baru -> NKO 101.0. Keduanya valid dan tersimpan.
        $newer = Dataset::factory()->create([
            'division_id' => $division->id,
            'created_at' => now(),
        ]);
        $this->legacyNko($newer, $division, 'TJBT', 101.0);
        $this->structuredNko($newer, $division, 'TJBT', 101.0);

        // Kedua versi benar-benar masih ada di tabel terstruktur.
        $this->assertSame(2, PerformanceObservation::whereHas(
            'indicator',
            fn ($q) => $q->where('category', 'nko'),
        )->count());

        $ranking = $this->structured(fn () => $this->reader('nkoRanking', [$this->management()]));

        $this->assertSame(['TJBT'], $ranking->pluck('unit')->all());
        $this->assertSame(101.0, (float) $ranking->first()->nko);
    }

    public function test_tie_created_at_dipecah_oleh_id_dataset(): void
    {
        $division = $this->division('Pengusahaan');
        $createdAt = now();

        $first = Dataset::factory()->create(['division_id' => $division->id, 'created_at' => $createdAt]);
        $second = Dataset::factory()->create(['division_id' => $division->id, 'created_at' => $createdAt]);

        $this->structuredNko($first, $division, 'TJBT', 90.0);
        $this->structuredNko($second, $division, 'TJBT', 120.0);

        $ranking = $this->structured(fn () => $this->reader('nkoRanking', [$this->management()]));

        // id lebih besar = terbaru, jadi 120.0 yang tampil meski created_at sama.
        $this->assertSame(120.0, (float) $ranking->first()->nko);
    }

    public function test_semua_dataset_periode_sama_tetap_tersimpan(): void
    {
        $division = $this->division('Pengusahaan');

        foreach ([105.5, 101.0, 99.0] as $value) {
            $dataset = Dataset::factory()->create(['division_id' => $division->id]);
            $this->structuredNko($dataset, $division, 'TJBT', $value);
        }

        $this->assertSame(3, Dataset::count());
        $this->assertSame(3, PerformanceObservation::whereHas(
            'indicator',
            fn ($q) => $q->where('category', 'nko'),
        )->count());
    }

    // ------------------------------------------------------------------
    // 3. Versi ganda: tidak ada unit x period uniqueness untuk NKO
    // ------------------------------------------------------------------

    public function test_nko_tidak_punya_uniqueness_unit_periode_lintas_dataset(): void
    {
        $division = $this->division('Pengusahaan');
        $unit = OrgUnit::factory()->create(['name' => 'TJBT']);
        $indicator = Indicator::factory()->create([
            'org_unit_id' => $unit->id,
            'name' => KpiReportConverter::NKO_LABEL,
            'category' => 'nko',
        ]);

        $datasetA = Dataset::factory()->create(['division_id' => $division->id]);
        $datasetB = Dataset::factory()->create(['division_id' => $division->id]);

        foreach ([$datasetA, $datasetB] as $dataset) {
            PerformanceObservation::factory()->create([
                'dataset_id' => $dataset->id,
                'indicator_id' => $indicator->id,
                'org_unit_id' => $unit->id,
                'division_id' => $division->id,
                'period' => self::PERIOD,
                'nilai' => 105.5,
            ]);
        }

        // Dua baris (dataset, unit, periode) yang sama tetap hidup berdampingan.
        $this->assertSame(2, PerformanceObservation::count());
    }

    // ------------------------------------------------------------------
    // 4. Dataset ARCHIVED dikecualikan
    // ------------------------------------------------------------------

    public function test_dataset_archived_tidak_dipakai_reader_terstruktur(): void
    {
        $division = $this->division('Pengusahaan');
        $archived = Dataset::factory()->archived()->create(['division_id' => $division->id]);
        $this->structuredNko($archived, $division, 'TJBT', 105.5);

        // Dataset archived tidak dianggap "terbaru": tidak ada kandidat READY.
        $this->assertSame([], app(StructuredNkoReader::class)->latestForPeriod($division->id, self::PERIOD)->all());

        $donut = $this->structured(fn () => $this->reader('nkoDonut', [$this->management()]));
        $this->assertNull($donut['period']);
        $this->assertSame([], $donut['data']);
    }

    public function test_dataset_archived_lebih_baru_tidak_menutup_dataset_ready(): void
    {
        $division = $this->division('Pengusahaan');

        $ready = Dataset::factory()->create([
            'division_id' => $division->id,
            'created_at' => now()->subDay(),
        ]);
        $this->structuredNko($ready, $division, 'TJBT', 105.5);

        // Archived dibuat SETELAH ready, tapi tidak boleh ikut seleksi.
        $archived = Dataset::factory()->archived()->create([
            'division_id' => $division->id,
            'created_at' => now(),
        ]);
        $this->structuredNko($archived, $division, 'TJBT', 10.0);

        $this->assertSame(
            105.5,
            app(StructuredNkoReader::class)->latestForPeriod($division->id, self::PERIOD)->first()['nko'],
        );
    }

    // ------------------------------------------------------------------
    // 5. Scoping divisi
    // ------------------------------------------------------------------

    public function test_staff_hanya_melihat_divisinya_sendiri(): void
    {
        $own = $this->division('Divisi Sendiri');
        $other = $this->division('Divisi Lain');

        $ownDataset = Dataset::factory()->create(['division_id' => $own->id]);
        $otherDataset = Dataset::factory()->create(['division_id' => $other->id]);

        $this->structuredNko($ownDataset, $own, 'OWN_UNIT', 80.0);
        $this->structuredNko($otherDataset, $other, 'OTHER_UNIT', 105.5);

        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $own->id]);

        $ranking = $this->structured(fn () => $this->reader('nkoRanking', [$staff]));

        $this->assertSame(['OWN_UNIT'], $ranking->pluck('unit')->all());
    }

    public function test_dataset_terbaru_dihitung_per_divisi(): void
    {
        $own = $this->division('Divisi Sendiri');
        $other = $this->division('Divisi Lain');

        // Divisi lain punya dataset paling baru; itu tidak boleh menutup
        // dataset divisi sendiri (pemilihan per divisi, bukan global).
        $ownDataset = Dataset::factory()->create([
            'division_id' => $own->id,
            'created_at' => now()->subDay(),
        ]);
        $otherDataset = Dataset::factory()->create([
            'division_id' => $other->id,
            'created_at' => now(),
        ]);

        $this->structuredNko($ownDataset, $own, 'OWN_UNIT', 88.0);
        $this->structuredNko($otherDataset, $other, 'OTHER_UNIT', 105.5);

        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $own->id]);

        $ranking = $this->structured(fn () => $this->reader('nkoRanking', [$staff]));

        $this->assertSame(['OWN_UNIT'], $ranking->pluck('unit')->all());
    }

    // ------------------------------------------------------------------
    // 6. Konflik periode: form vs banner (provenance)
    // ------------------------------------------------------------------

    public function test_importer_mencatat_konflik_periode_tanpa_gagal(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        // Banner fixture "S.D. AGUSTUS 2026", periode form 2026-07-31.
        $counts = app(StructuredKpiImporter::class)->import(
            $this->kpiReportPath(),
            $dataset,
            '2026-07-31',
        );

        $this->assertGreaterThan(0, $counts['observations']);

        $nko = PerformanceObservation::whereHas(
            'indicator',
            fn ($q) => $q->where('category', 'nko'),
        )->firstOrFail();

        // Periode efektif = periode form, banner tetap disimpan apa adanya.
        $this->assertSame('2026-07-31', $nko->period->format('Y-m-d'));
        $this->assertSame('S.D. AGUSTUS 2026', $nko->sheet_period);
        $this->assertTrue($nko->period_conflict);
    }

    public function test_importer_tanpa_konflik_saat_banner_cocok(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        app(StructuredKpiImporter::class)->import($this->kpiReportPath(), $dataset, self::PERIOD);

        $nko = PerformanceObservation::whereHas(
            'indicator',
            fn ($q) => $q->where('category', 'nko'),
        )->firstOrFail();

        $this->assertSame('2026-08-31', $nko->period->format('Y-m-d'));
        $this->assertSame('S.D. AGUSTUS 2026', $nko->sheet_period);
        $this->assertFalse($nko->period_conflict);
    }

    // ------------------------------------------------------------------
    // 7. Idempotensi
    // ------------------------------------------------------------------

    public function test_impor_ulang_dataset_sama_tidak_menggandakan_nko(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $importer = app(StructuredKpiImporter::class);
        $path = $this->kpiReportPath();

        $importer->import($path, $dataset, self::PERIOD);
        $afterFirst = PerformanceObservation::count();

        $second = $importer->import($path, $dataset, self::PERIOD);

        $this->assertSame(0, $second['observations']);
        $this->assertSame($afterFirst, PerformanceObservation::count());
    }

    // ------------------------------------------------------------------
    // 8. Divergensi yang disah: baris legacy tanpa dataset
    // ------------------------------------------------------------------

    public function test_r1_terstruktur_mengecualikan_baris_tanpa_dataset(): void
    {
        $division = $this->division('Pengusahaan');

        $withDataset = Dataset::factory()->create(['division_id' => $division->id]);
        $this->legacyNko($withDataset, $division, 'READY_UNIT', 105.5);
        $this->structuredNko($withDataset, $division, 'READY_UNIT', 105.5);

        // Baris legacy tanpa dataset: tetap tampil di legacy, tidak di terstruktur.
        PerformanceMetric::factory()->create([
            'dataset_id' => null,
            'division_id' => $division->id,
            'metric_name' => 'ORPHAN_UNIT | NKO',
            'value' => 99.0,
            'period' => self::PERIOD,
        ]);

        $user = $this->management();

        $legacyUnits = array_column($this->chart($user)['series'], 'name');
        $structuredUnits = array_column($this->structured(fn () => $this->chart($user))['series'], 'name');

        $this->assertEqualsCanonicalizing(['READY_UNIT', 'ORPHAN_UNIT'], $legacyUnits);
        $this->assertSame(['READY_UNIT'], $structuredUnits);
    }

    // ------------------------------------------------------------------
    // 9. Regresi: correlation divisi NULL-safe
    // ------------------------------------------------------------------

    /**
     * Observation dengan division_id NULL tidak boleh membuat dataset lama
     * ikut lolos sebagai "terbaru".
     *
     * Bug lama: `newer.division_id = po.division_id` bernilai UNKNOWN saat
     * keduanya NULL, sehingga NOT EXISTS selalu TRUE dan SEMUA dataset READY
     * pada periode itu dianggap terbaru.
     */
    public function test_dataset_terbaru_menang_saat_observation_tanpa_divisi(): void
    {
        $division = $this->division('Pengusahaan');

        $older = Dataset::factory()->create([
            'division_id' => $division->id,
            'created_at' => now()->subDays(2),
        ]);
        $this->structuredNkoTanpaDivisi($older, 'TJBT', 105.5);

        $newer = Dataset::factory()->create([
            'division_id' => $division->id,
            'created_at' => now(),
        ]);
        $this->structuredNkoTanpaDivisi($newer, 'TJBT', 101.0);

        // Kedua versi tetap tersimpan (retensi tidak berubah).
        $this->assertSame(2, PerformanceObservation::whereHas(
            'indicator',
            fn ($q) => $q->where('category', 'nko'),
        )->count());

        $reader = app(StructuredNkoReader::class);

        // Hanya dataset terbaru yang boleh lolos seleksi.
        $this->assertSame([$newer->id], $reader->latestDatasetIds(null, self::PERIOD));

        $rows = $reader->latestForPeriod(null, self::PERIOD);

        $this->assertCount(1, $rows);
        $this->assertSame(101.0, $rows->first()['nko']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Jalankan callback dengan flag 'structured', lalu kembalikan hasilnya.
     */
    private function structured(callable $callback): mixed
    {
        $previous = config('kpi.read_model');
        config(['kpi.read_model' => 'structured']);

        try {
            return $callback();
        } finally {
            config(['kpi.read_model' => $previous]);
        }
    }

    private function reader(string $method, array $args = []): mixed
    {
        $controller = app(DashboardController::class);
        $reflection = new \ReflectionMethod($controller, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($controller, ...$args);
    }

    /**
     * @return array<string, mixed>
     */
    private function chart(User $user): array
    {
        return $this->actingAs($user)->get(route('performance.data'))->assertOk()->json();
    }

    private function division(string $name): Division
    {
        return Division::factory()->create(['name' => $name]);
    }

    private function management(): User
    {
        return User::factory()->withRole(Role::MANAGEMENT)->create();
    }

    private function legacyNko(Dataset $dataset, Division $division, string $unit, float $value, string $period = self::PERIOD): void
    {
        $this->legacyMetric($dataset, $division, $unit.KpiReportConverter::NKO_SUFFIX, $value, $period);
    }

    private function legacyMetric(Dataset $dataset, Division $division, string $name, float $value, string $period = self::PERIOD): PerformanceMetric
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
     * Tulis baris NKO terstruktur seperti yang dilakukan importer: satu
     * indikator kanonis berkategori 'nko' per unit.
     */
    private function structuredNko(Dataset $dataset, Division $division, string $unit, float $value, string $period = self::PERIOD): PerformanceObservation
    {
        $orgUnit = OrgUnit::firstOrCreate(['name' => $unit]);

        $indicator = Indicator::firstOrCreate(
            ['org_unit_id' => $orgUnit->id, 'name' => KpiReportConverter::NKO_LABEL],
            ['category' => 'nko', 'bobot' => null],
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

    private function structuredObservation(
        Dataset $dataset,
        Division $division,
        string $unit,
        string $name,
        float $target,
        float $realisasi,
        string $period = self::PERIOD,
    ): PerformanceObservation {
        $orgUnit = OrgUnit::firstOrCreate(['name' => $unit]);
        $indicator = Indicator::firstOrCreate(
            ['org_unit_id' => $orgUnit->id, 'name' => $name],
            ['category' => 'kinerja', 'is_group' => false],
        );

        return PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indicator->id,
            'org_unit_id' => $orgUnit->id,
            'division_id' => $division->id,
            'period' => $period,
            'target_value' => $target,
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_value' => $realisasi,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'source_sheet' => $unit,
        ]);
    }

    /**
     * Sama seperti structuredNko(), tetapi DIVISI SENGAJA dikosongkan.
     *
     * Merepresentasikan observation yang dibuat sebelum scoping divisi
     * terpasang, atau baris yatim setelah divisi dihapus (kolomnya memang
     * nullable). Baris seperti ini dulu membuat correlation divisi bernilai
     * UNKNOWN sehingga tidak pernah tersupersede.
     */
    private function structuredNkoTanpaDivisi(Dataset $dataset, string $unit, float $value, string $period = self::PERIOD): PerformanceObservation
    {
        $orgUnit = OrgUnit::firstOrCreate(['name' => $unit]);

        $indicator = Indicator::firstOrCreate(
            ['org_unit_id' => $orgUnit->id, 'name' => KpiReportConverter::NKO_LABEL],
            ['category' => 'nko', 'bobot' => null],
        );

        return PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indicator->id,
            'org_unit_id' => $orgUnit->id,
            'division_id' => null,
            'period' => $period,
            'nilai' => $value,
            'target_type' => PerformanceObservation::TYPE_EMPTY,
            'realisasi_type' => PerformanceObservation::TYPE_EMPTY,
            'source_sheet' => $unit,
        ]);
    }

    private function kpiReportPath(): string
    {
        $spreadsheet = new Spreadsheet;

        $tjbt = $spreadsheet->getActiveSheet();
        $tjbt->setTitle('TJBT');
        $tjbt->setCellValue('K6', 'S.D. AGUSTUS 2026');
        $tjbt->setCellValue('K7', 'TARGET');
        $tjbt->setCellValue('L7', 'REALISASI');
        $tjbt->setCellValue('N7', 'NILAI');

        $tjbt->setCellValue('A10', 1);
        $tjbt->setCellValue('B10', 'Indikator A');
        $tjbt->setCellValue('N10', 80);

        // Baris NKO: nilai mentah workbook 105.5.
        $tjbt->setCellValueExplicit('B21', 'NILAI KINERJA ORGANISASI (NKO)', DataType::TYPE_STRING);
        $tjbt->setCellValue('F21', 100);
        $tjbt->setCellValue('N21', 105.5);

        $path = tempnam(sys_get_temp_dir(), 'kpi_parity_').'.xlsx';

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(true);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
