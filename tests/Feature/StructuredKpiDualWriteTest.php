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
use App\Services\KpiReportConverter;
use App\Services\StructuredKpiImporter;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitDivisionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

/**
 * Unit 4B — dual-write terstruktur pada jalur impor KPI.
 *
 * Fokus: apa yang MASUK tabel struktural (org_units/indicators/
 * performance_observations), apa yang HARUS tetap di luar, dan bahwa jalur
 * legacy (performance_metrics) tidak berubah perilakunya.
 *
 * Angka di fixture sengaja kecil dan netral. Nilai bisnis nyata (mis. NKO
 * 105.5) hanya dipakai sebagai bukti bahwa jalur legacy masih menerimanya,
 * BUKAN sebagai kebenaran aplikasi di tabel terstruktur.
 */
class StructuredKpiDualWriteTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-08-31';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(UnitDivisionSeeder::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Org unit
    // ------------------------------------------------------------------

    public function test_importer_menulis_org_unit_dari_judul_sheet(): void
    {
        $this->importViaRoute($this->makeKpiReport());

        $this->assertSame(['TJBB', 'TJBT'], OrgUnit::orderBy('name')->pluck('name')->all());
    }

    public function test_rekap_dan_sheet_bulan_legacy_bukan_org_unit(): void
    {
        $this->importViaRoute($this->makeKpiReport());

        $this->assertSame(0, OrgUnit::whereIn('name', ['REKAP', 'JAN'])->count());
        $this->assertSame(
            0,
            PerformanceObservation::whereIn('source_sheet', ['REKAP', 'JAN'])->count(),
        );
    }

    // ------------------------------------------------------------------
    // Dual-write: legacy tetap, terstruktur ikut terisi, tidak digabung
    // ------------------------------------------------------------------

    public function test_jalur_legacy_performance_metrics_tetap_terisi(): void
    {
        $this->importViaRoute($this->makeKpiReport());

        $dataset = Dataset::sole();

        $this->assertGreaterThan(0, PerformanceMetric::where('dataset_id', $dataset->id)->count());
        $this->assertSame(
            105.5,
            (float) PerformanceMetric::where('dataset_id', $dataset->id)
                ->where('metric_name', 'TJBT | NKO')->sole()->value,
        );

        // Sumber yang sama juga mengisi tabel terstruktur.
        // 11 indikator KPI + 2 baris NKO kanonis (TJBT, TJBB) = 13.
        $this->assertSame(13, Indicator::count());
        $this->assertSame(13, PerformanceObservation::count());
        $this->assertSame(2, Indicator::where('category', 'nko')->count());
    }

    public function test_dua_tabel_tetap_terpisah_bukan_digabung(): void
    {
        $this->importViaRoute($this->makeKpiReport());

        // Bukti struktural: tidak ada kolom penghubung yang menyatukan kedua
        // tabel, dan observation tidak menyimpan nama metric bergaya legacy.
        $this->assertFalse(Schema::hasColumn('performance_metrics', 'indicator_id'));
        $this->assertFalse(Schema::hasColumn('performance_observations', 'metric_name'));

        foreach (Indicator::pluck('name') as $name) {
            $this->assertStringNotContainsString(' | ', (string) $name);
        }
    }

    public function test_dataset_dan_divisi_tercatat_di_observation(): void
    {
        $owner = Division::where('slug', 'perencanaan-pengusahaan')->sole();
        $this->importViaRoute($this->makeKpiReport());

        foreach (PerformanceObservation::all() as $observation) {
            $this->assertSame(Dataset::sole()->id, $observation->dataset_id);
            $this->assertSame($owner->id, $observation->division_id);
            $this->assertSame(self::PERIOD, $observation->period->format('Y-m-d'));
        }
    }

    // ------------------------------------------------------------------
    // Identitas indikator + hierarki
    // ------------------------------------------------------------------

    public function test_identitas_indikator_per_unit_bukan_global(): void
    {
        $this->importViaRoute($this->makeKpiReport());

        $label = 'Faktor Ketersediaan Trafo dan Transmisi';
        $tjbt = OrgUnit::where('name', 'TJBT')->sole();
        $tjbb = OrgUnit::where('name', 'TJBB')->sole();

        $fromTjbt = Indicator::where('org_unit_id', $tjbt->id)->where('name', $label)->sole();
        $fromTjbb = Indicator::where('org_unit_id', $tjbb->id)->where('name', $label)->sole();

        $this->assertSame(2, Indicator::where('name', $label)->count());
        $this->assertNotSame($fromTjbt->id, $fromTjbb->id);
    }

    public function test_parent_id_berasal_dari_parent_row(): void
    {
        $this->importViaRoute($this->makeKpiReport());

        $parent = $this->indicator('Faktor Ketersediaan Trafo dan Transmisi');
        $child = $this->indicator('a. Faktor Ketersediaan Trafo');

        $this->assertTrue($parent->is_group);
        $this->assertNull($parent->parent_id);
        $this->assertFalse($child->is_group);
        $this->assertSame($parent->id, $child->parent_id);
    }

    public function test_hierarki_tidak_disimpulkan_dari_bobot(): void
    {
        $this->importViaRoute($this->makeKpiReport());

        $parent = $this->indicator('Faktor Ketersediaan Trafo dan Transmisi');
        $bandChild = $this->indicator('b. Selisih Tenggat');

        // Induk memegang bobot numerik, anak justru berbobot band. Kalau
        // hierarki ditebak dari bobot, arahnya akan terbalik.
        $this->assertSame('20', $parent->bobot);
        $this->assertSame('Max -3', $bandChild->bobot);
        $this->assertSame($parent->id, $bandChild->parent_id);
    }

    public function test_kpi_independen_top_level_tetap_kandidat_induk(): void
    {
        $this->importViaRoute($this->makeKpiReport());

        $independent = $this->indicator('Indikator Independen');
        $child = $this->indicator('d. Anak dari Bukan Grup');

        // A terisi + C terisi: top-level tanpa induk, tetapi anak A-kosong tepat
        // di bawahnya tetap menunjuk ke sini.
        $this->assertFalse($independent->is_group);
        $this->assertNull($independent->parent_id);
        $this->assertSame($independent->id, $child->parent_id);
    }

    // ------------------------------------------------------------------
    // Metadata indikator
    // ------------------------------------------------------------------

    public function test_kode_agregasi_3_berarti_higher_is_better(): void
    {
        $this->importViaRoute($this->makeKpiReport());

        $indicator = $this->indicator('Indikator Independen');
        $this->assertSame('3', $indicator->aggregation_code);
        $this->assertSame('higher_is_better', $indicator->polarity);
    }

    public function test_kode_agregasi_1_berarti_lower_is_better(): void
    {
        $this->importViaRoute($this->makeKpiReport());

        $indicator = $this->indicator('Indikator Polaritas Satu');
        $this->assertSame('1', $indicator->aggregation_code);
        $this->assertSame('lower_is_better', $indicator->polarity);
        $this->assertNotSame('higher_is_better', $indicator->polarity);
    }

    public function test_formula_sumber_dipertahankan_apa_adanya(): void
    {
        $this->importViaRoute($this->makeKpiReport());

        $indicator = $this->indicator('Faktor Ketersediaan Trafo dan Transmisi');
        $this->assertSame('=AVERAGE(M11:M12)', $indicator->formula);
    }

    public function test_satuan_sumber_dipertahankan_tanpa_normalisasi_karangan(): void
    {
        $this->importViaRoute($this->makeKpiReport());

        $indicator = $this->indicator('Faktor Ketersediaan Trafo dan Transmisi');
        $this->assertSame('%', $indicator->satuan);
        // Normalisasi satuan masih blocker bisnis: jangan diisi tebakan.
        $this->assertNull($indicator->satuan_normalized);
    }

    public function test_bobot_numerik_mengisi_bobot_value(): void
    {
        $this->importViaRoute($this->makeKpiReport());

        $indicator = $this->indicator('Faktor Ketersediaan Trafo dan Transmisi');
        $this->assertSame('20', $indicator->bobot);
        $this->assertSame('20.0000', $indicator->bobot_value);
        $this->assertNull($indicator->bobot_min);
        $this->assertNull($indicator->bobot_max);
    }

    public function test_bobot_band_max_minus_3_tetap_mentah_dan_value_null(): void
    {
        $this->importViaRoute($this->makeKpiReport());

        $indicator = $this->indicator('b. Selisih Tenggat');
        $this->assertSame('Max -3', $indicator->bobot);
        // "Max -3" ambigu (plafon pengurangan vs nilai maksimum): jangan
        // mengarang angka maupun batas min/max.
        $this->assertNull($indicator->bobot_value);
        $this->assertNull($indicator->bobot_min);
        $this->assertNull($indicator->bobot_max);
    }

    public function test_jenis_formula_pencapaian_disalin_dari_sumber(): void
    {
        $this->importViaRoute($this->makeKpiReport());

        $this->assertSame(
            'average',
            $this->indicator('Faktor Ketersediaan Trafo dan Transmisi')->pencapaian_formula_kind,
        );
        $this->assertSame(
            'polarity_ratio',
            $this->indicator('a. Faktor Ketersediaan Trafo')->pencapaian_formula_kind,
        );
        $this->assertSame(
            'date_delta',
            $this->indicator('b. Selisih Tenggat')->pencapaian_formula_kind,
        );
    }

    public function test_jenis_formula_tidak_disimpulkan_dari_tipe_tanggal(): void
    {
        $this->importViaRoute($this->makeKpiReport());

        $observation = $this->observationFor('Indikator Bertanggal');

        // Baris ini target/realisasinya bertipe date, tetapi kolom pencapaiannya
        // bukan formula $L-$K: jenis formula harus tetap null.
        $this->assertSame('date', $observation->target_type);
        $this->assertNull($observation->indicator->pencapaian_formula_kind);
    }

    // ------------------------------------------------------------------
    // Baris yang HARUS dibuang
    // ------------------------------------------------------------------

    public function test_total_bobot_tidak_menjadi_indikator(): void
    {
        $this->importViaRoute($this->makeKpiReport());

        $this->assertSame(0, Indicator::where('name', 'like', '%TOTAL BOBOT%')->count());
    }

    public function test_nko_menjadi_indikator_kanonis_berkategori_nko(): void
    {
        $this->importViaRoute($this->makeKpiReport());

        // Dua sheet ber-NKO (TJBT + TJBB) -> satu indikator NKO per unit.
        $nko = Indicator::where('category', 'nko')->get();

        $this->assertCount(2, $nko);
        // Varian label "NILAI KINERJA ORGANISASI (NKO)" dikerucutkan ke satu
        // nama kanonis, bukan disimpan mentah.
        $this->assertSame(
            [KpiReportConverter::NKO_LABEL, KpiReportConverter::NKO_LABEL],
            $nko->pluck('name')->sort()->values()->all(),
        );

        // NKO tidak menyamar sebagai KPI: tanpa bobot, tanpa jenjang.
        foreach ($nko as $indicator) {
            $this->assertNull($indicator->bobot);
            $this->assertNull($indicator->bobot_value);
            $this->assertFalse($indicator->is_group);
            $this->assertNull($indicator->parent_id);
        }

        // NKO tetap dilayani jalur legacy seperti sebelumnya.
        $this->assertSame(1, PerformanceMetric::where('metric_name', 'TJBB | NKO')->count());
    }

    public function test_nilai_nko_mentah_disimpan_di_kolom_nilai(): void
    {
        $this->importViaRoute($this->makeKpiReport());

        $observation = PerformanceObservation::whereHas(
            'indicator',
            fn ($q) => $q->where('category', 'nko'),
        )->whereHas('orgUnit', fn ($q) => $q->where('name', 'TJBT'))->sole();

        // NKO = nilai mentah workbook (N21 = 105.5), bukan hasil hitung ulang.
        $this->assertSame(105.5, (float) $observation->nilai);
        $this->assertSame('TJBT', $observation->source_sheet);
        $this->assertSame(21, $observation->source_row);
    }

    public function test_baris_kop_laporan_tidak_menjadi_indikator(): void
    {
        $this->importViaRoute($this->makeKpiReport());

        $this->assertSame(0, Indicator::whereIn('name', [
            'LAPORAN PENCAPAIAN KPI TAHUN 2026',
            'Divisi Contoh Kop Laporan',
        ])->count());
    }

    public function test_baris_grup_tanpa_nilai_tetap_jadi_indikator(): void
    {
        $this->importViaRoute($this->makeKpiReport());

        // "Kepatuhan" hanya wadah hierarki (tanpa bobot/nilai). Indikatornya
        // harus ada supaya anak-anaknya punya induk, dan observation-nya
        // kosong bertipe 'empty' -- bukan dikarang jadi nol.
        $indicator = $this->indicator('Kepatuhan');
        $this->assertTrue($indicator->is_group);
        $this->assertNull($indicator->bobot);

        $observation = PerformanceObservation::where('indicator_id', $indicator->id)->sole();
        $this->assertSame('empty', $observation->target_type);
        $this->assertNull($observation->target_value);
        $this->assertNull($observation->nilai);
    }

    // ------------------------------------------------------------------
    // Bobot: KPI independen tidak boleh hilang
    // ------------------------------------------------------------------

    public function test_bobot_kpi_independen_tidak_hilang(): void
    {
        $this->importViaRoute($this->makeKpiReport());

        $independent = $this->indicator('Indikator Independen');
        $this->assertFalse($independent->is_group);
        $this->assertSame('5', $independent->bobot);
        $this->assertSame('5.0000', $independent->bobot_value);
    }

    public function test_total_bobot_tidak_dihitung_dari_penjumlahan_grup_saja(): void
    {
        $this->importViaRoute($this->makeKpiReport());

        $tjbt = OrgUnit::where('name', 'TJBT')->sole();

        // Seluruh bobot numerik yang benar-benar ada di unit ini: 20 (grup),
        // 5 dan 10 (KPI independen). Angka 25 dari baris TOTAL BOBOT dan 100
        // dari NKO tidak boleh muncul di sini.
        $weights = Indicator::where('org_unit_id', $tjbt->id)
            ->whereNotNull('bobot_value')
            ->pluck('bobot_value')
            ->map(fn ($value): float => (float) $value)
            ->sort()
            ->values()
            ->all();

        $this->assertSame([5.0, 10.0, 20.0], $weights);
    }

    // ------------------------------------------------------------------
    // Nilai bertipe
    // ------------------------------------------------------------------

    public function test_target_numerik(): void
    {
        $this->importViaRoute($this->makeKpiReport());
        $observation = $this->observationFor('a. Faktor Ketersediaan Trafo');

        $this->assertSame('numeric', $observation->target_type);
        $this->assertSame('99.5000', $observation->target_value);
        $this->assertNull($observation->target_text);
        $this->assertNull($observation->target_date);
    }

    public function test_target_teks(): void
    {
        $this->importViaRoute($this->makeKpiReport());
        $observation = $this->observationFor('Indikator Target Teks');

        $this->assertSame('text', $observation->target_type);
        $this->assertSame('Sesuai Target Beyond RKAP 2026', $observation->target_text);
        $this->assertNull($observation->target_value);
        $this->assertNull($observation->target_date);
    }

    public function test_target_tanggal(): void
    {
        $this->importViaRoute($this->makeKpiReport());
        $observation = $this->observationFor('Indikator Bertanggal');

        $this->assertSame('date', $observation->target_type);
        $this->assertSame('2026-05-31', $observation->target_date->format('Y-m-d'));
        // Serial tanggal tidak disimpan sebagai angka.
        $this->assertNull($observation->target_value);
        $this->assertNull($observation->target_text);
    }

    public function test_realisasi_numerik(): void
    {
        $this->importViaRoute($this->makeKpiReport());
        $observation = $this->observationFor('a. Faktor Ketersediaan Trafo');

        $this->assertSame('numeric', $observation->realisasi_type);
        $this->assertSame('99.7500', $observation->realisasi_value);
        $this->assertNull($observation->realisasi_text);
        $this->assertNull($observation->realisasi_date);
    }

    public function test_realisasi_teks(): void
    {
        $this->importViaRoute($this->makeKpiReport());
        $observation = $this->observationFor('Indikator Target Teks');

        $this->assertSame('text', $observation->realisasi_type);
        $this->assertSame('Belum tersedia', $observation->realisasi_text);
        $this->assertNull($observation->realisasi_value);
        $this->assertNull($observation->realisasi_date);
    }

    public function test_realisasi_tanggal(): void
    {
        $this->importViaRoute($this->makeKpiReport());
        $observation = $this->observationFor('Indikator Bertanggal');

        $this->assertSame('date', $observation->realisasi_type);
        $this->assertSame('2026-05-21', $observation->realisasi_date->format('Y-m-d'));
        $this->assertNull($observation->realisasi_value);
        $this->assertNull($observation->realisasi_text);
    }

    public function test_target_kosong_tidak_dikonversi_jadi_nol(): void
    {
        $this->importViaRoute($this->makeKpiReport());
        $observation = $this->observationFor('d. Anak dari Bukan Grup');

        $this->assertSame('empty', $observation->target_type);
        $this->assertNull($observation->target_value);
        $this->assertNull($observation->target_text);
        $this->assertNull($observation->target_date);
    }

    public function test_pencapaian_nilai_dan_ket_dipertahankan(): void
    {
        $this->importViaRoute($this->makeKpiReport());
        $observation = $this->observationFor('a. Faktor Ketersediaan Trafo');

        $this->assertSame('1.0025', $observation->pencapaian);
        $this->assertSame('20.0500', $observation->nilai);
        $this->assertSame('Aman', $observation->ket);
    }

    public function test_ket_numerik_tidak_dipaksa_jadi_teks(): void
    {
        $this->importViaRoute($this->makeKpiReport());

        // Di workbook nyata kolom KET kadang berisi angka hasil formula. Itu
        // bukan keterangan tekstual, jadi kolom ket dibiarkan null daripada
        // disimpan sebagai "1.0025".
        $this->assertNull($this->observationFor('Indikator Ket Numerik')->ket);
    }

    public function test_source_sheet_dan_source_row_dipertahankan(): void
    {
        $this->importViaRoute($this->makeKpiReport());
        $observation = $this->observationFor('a. Faktor Ketersediaan Trafo');

        $this->assertSame('TJBT', $observation->source_sheet);
        $this->assertSame(11, $observation->source_row);
    }

    // ------------------------------------------------------------------
    // Periode
    // ------------------------------------------------------------------

    public function test_period_eksplisit_importer_menang_atas_banner_sheet(): void
    {
        $this->importViaRoute($this->makeKpiReport(), period: '2026-07-31');

        // Banner fixture berbunyi "S.D. AGUSTUS 2026", tetapi period yang
        // dipakai tetap period eksplisit dari form.
        foreach (PerformanceObservation::all() as $observation) {
            $this->assertSame('2026-07-31', $observation->period->format('Y-m-d'));
        }
    }

    public function test_period_berbeda_membuat_observation_terpisah(): void
    {
        $this->importViaRoute($this->makeKpiReport(), period: '2026-07-31');
        $this->importViaRoute($this->makeKpiReport(), period: '2026-08-31');

        $indicator = $this->indicator('a. Faktor Ketersediaan Trafo');

        $this->assertSame(
            2,
            PerformanceObservation::where('indicator_id', $indicator->id)->count(),
        );
        $this->assertSame(
            1,
            PerformanceObservation::where('indicator_id', $indicator->id)
                ->where('period', '2026-07-31')->count(),
        );
    }

    // ------------------------------------------------------------------
    // Idempotensi
    // ------------------------------------------------------------------

    public function test_impor_ulang_pada_dataset_sama_tidak_menggandakan(): void
    {
        $dataset = $this->readyDataset();
        $importer = app(StructuredKpiImporter::class);
        $path = $this->makeKpiReport()->getRealPath();

        $first = $importer->import($path, $dataset, self::PERIOD);
        $observationsBefore = PerformanceObservation::count();

        $second = $importer->import($path, $dataset, self::PERIOD);

        $this->assertSame(2, $first['org_units']);
        $this->assertSame(13, $first['indicators']);
        $this->assertSame(13, $first['observations']);

        // Impor ulang = pembaruan, bukan penggandaan.
        $this->assertSame(0, $second['org_units']);
        $this->assertSame(0, $second['indicators']);
        $this->assertSame(0, $second['observations']);

        $this->assertSame(2, OrgUnit::count());
        $this->assertSame(13, Indicator::count());
        $this->assertSame($observationsBefore, PerformanceObservation::count());
    }

    public function test_tidak_ada_observation_ganda_pada_kunci_unik(): void
    {
        $this->importViaRoute($this->makeKpiReport());

        $duplicates = PerformanceObservation::query()
            ->selectRaw('dataset_id, indicator_id, org_unit_id, period, COUNT(*) as total')
            ->groupBy('dataset_id', 'indicator_id', 'org_unit_id', 'period')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $this->assertTrue($duplicates->isEmpty(), 'Ada observation ganda pada kunci unik.');
    }

    public function test_importer_reuses_existing_indicator_with_case_insensitivity_and_whitespace_tolerance(): void
    {
        $tjbt = OrgUnit::firstOrCreate(['name' => 'TJBT']);
        $preExisting = Indicator::create([
            'org_unit_id' => $tjbt->id,
            'name' => '  A. FAKTOR KETERSEDIAAN TRAFO  ',
            'satuan' => 'Old Satuan',
        ]);

        $dataset = $this->readyDataset();
        $importer = app(StructuredKpiImporter::class);
        $path = $this->makeKpiReport()->getRealPath();

        $result = $importer->import($path, $dataset, self::PERIOD);

        // Pre-existing indicator harus di-reuse dan tidak membuat row baru
        $matching = Indicator::where('org_unit_id', $tjbt->id)
            ->whereRaw('LOWER(TRIM(name)) = ?', ['a. faktor ketersediaan trafo'])
            ->get();

        $this->assertCount(1, $matching);
        $this->assertSame($preExisting->id, $matching->first()->id);

        $observation = PerformanceObservation::where('dataset_id', $dataset->id)
            ->where('indicator_id', $preExisting->id)
            ->first();
        $this->assertNotNull($observation);
    }

    // ------------------------------------------------------------------
    // Integritas kegagalan: structured failure menggagalkan dataset & membersihkan legacy
    // ------------------------------------------------------------------

    public function test_kegagalan_tulis_terstruktur_menggagalkan_dataset_dan_membersihkan_legacy(): void
    {
        $this->mock(StructuredKpiImporter::class, function ($mock): void {
            $mock->shouldReceive('import')->andThrow(new RuntimeException('gagal disengaja'));
        });

        $this->importViaRoute($this->makeKpiReport());

        $dataset = Dataset::sole();

        // Kontrak integritas baru: status FAILED, metrik legacy dibersihkan (0 baris),
        // tabel terstruktur kosong, dan kegagalan dicatat pada session.
        $this->assertSame(Dataset::STATUS_FAILED, $dataset->status->value);
        $this->assertSame(0, PerformanceMetric::where('dataset_id', $dataset->id)->count());
        $this->assertSame(0, Indicator::count());
        $this->assertSame(0, PerformanceObservation::count());

        $failed = session('kpi_failed');
        $this->assertIsArray($failed);
        $this->assertStringContainsString('tabel terstruktur gagal', $failed[0]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function importViaRoute(UploadedFile $file, string $period = self::PERIOD): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $this->actingAs($admin)->post(route('datasets.kpi-import'), [
            'file' => $file,
            'period' => $period,
        ])->assertRedirect(route('datasets.create'));
    }

    private function readyDataset(): Dataset
    {
        return Dataset::factory()->create([
            'division_id' => Division::where('slug', 'perencanaan-pengusahaan')->sole()->id,
            'status' => Dataset::STATUS_READY,
        ]);
    }

    private function observationFor(string $indicatorName, string $unit = 'TJBT'): PerformanceObservation
    {
        $indicator = $this->indicator($indicatorName, $unit);

        return PerformanceObservation::where('indicator_id', $indicator->id)->sole();
    }

    private function indicator(string $name, string $unit = 'TJBT'): Indicator
    {
        $orgUnit = OrgUnit::where('name', $unit)->sole();

        return Indicator::where('org_unit_id', $orgUnit->id)->where('name', $name)->sole();
    }

    /**
     * Fixture dua sheet unit (TJBT + TJBB), satu REKAP, satu sheet bulan legacy.
     * Layout standar K/L/M/N/O dengan banner di baris 6.
     */
    private function makeKpiReport(): UploadedFile
    {
        $spreadsheet = new Spreadsheet;

        $rekap = $spreadsheet->getActiveSheet();
        $rekap->setTitle('REKAP');
        $rekap->setCellValue('B3', 'REKAP LINTAS UNIT');
        $rekap->setCellValue('N3', 999);
        $this->setValueHeaders($rekap);

        $jan = $spreadsheet->createSheet();
        $jan->setTitle('JAN');
        $jan->setCellValue('B10', 'Indikator Bulan Legacy');
        $jan->setCellValue('N10', 5);
        $this->setValueHeaders($jan);

        $tjbt = $spreadsheet->createSheet();
        $tjbt->setTitle('TJBT');
        $tjbt->setCellValue('B6', 'INDIKATOR KINERJA KUNCI');
        $this->setValueHeaders($tjbt);

        // Kop laporan: B terisi, A kosong, tanpa nilai -> bukan indikator.
        $this->text($tjbt, 'B2', 'LAPORAN PENCAPAIAN KPI TAHUN 2026');
        $this->text($tjbt, 'B3', 'Divisi Contoh Kop Laporan');

        // Grup (A terisi, C kosong) + dua anak.
        $tjbt->setCellValue('A10', 1);
        $this->text($tjbt, 'B10', 'Faktor Ketersediaan Trafo dan Transmisi');
        $tjbt->setCellValue('D10', '=AVERAGE(M11:M12)');
        $tjbt->setCellValue('E10', '%');
        $tjbt->setCellValue('F10', 20);
        $tjbt->setCellValue('M10', '=AVERAGE(M11:M12)');
        $tjbt->setCellValue('N10', 80);

        $this->text($tjbt, 'B11', 'a. Faktor Ketersediaan Trafo');
        $tjbt->setCellValue('C11', 3);
        $tjbt->setCellValue('E11', '%');
        $tjbt->setCellValue('K11', 99.5);
        $tjbt->setCellValue('L11', 99.75);
        $tjbt->setCellValue('M11', '=IF($C11=3,L11/K11,2-(L11/K11))');
        $tjbt->setCellValue('N11', 20.05);
        $this->text($tjbt, 'O11', 'Aman');

        $this->text($tjbt, 'B12', 'b. Selisih Tenggat');
        $tjbt->setCellValue('C12', 3);
        $tjbt->setCellValue('E12', 'Hari');
        $tjbt->setCellValue('F12', 'Max -3');
        $tjbt->setCellValue('K12', 10);
        $tjbt->setCellValue('L12', 7);
        $tjbt->setCellValue('M12', '=IF($C12=3,($L12-$K12),0)');

        // KPI independen: A dan C terisi -> top-level, bukan grup, tetapi tetap
        // kandidat induk untuk anak A-kosong di bawahnya.
        $tjbt->setCellValue('A13', 2);
        $this->text($tjbt, 'B13', 'Indikator Independen');
        $tjbt->setCellValue('C13', 3);
        $tjbt->setCellValue('E13', '%');
        $tjbt->setCellValue('F13', 5);
        $tjbt->setCellValue('K13', 10);
        $tjbt->setCellValue('L13', 12);
        $tjbt->setCellValue('M13', 1);
        $tjbt->setCellValue('N13', 5);

        $this->text($tjbt, 'B14', 'd. Anak dari Bukan Grup');
        $tjbt->setCellValue('M14', 1);

        // Polaritas 1: lower_is_better, tidak boleh dibalik.
        $tjbt->setCellValue('A15', 3);
        $this->text($tjbt, 'B15', 'Indikator Polaritas Satu');
        $tjbt->setCellValue('C15', 1);
        $tjbt->setCellValue('E15', 'Jam');
        $tjbt->setCellValue('F15', 10);
        $tjbt->setCellValue('K15', 5);
        $tjbt->setCellValue('L15', 4);
        $tjbt->setCellValue('M15', 1);

        // Target teks + realisasi teks.
        $tjbt->setCellValue('A16', 4);
        $this->text($tjbt, 'B16', 'Indikator Target Teks');
        $tjbt->setCellValue('C16', 3);
        $tjbt->setCellValue('E16', 'Dokumen');
        $this->text($tjbt, 'K16', 'Sesuai Target Beyond RKAP 2026');
        $this->text($tjbt, 'L16', 'Belum tersedia');
        $tjbt->setCellValue('M16', 1);

        // Target tanggal + realisasi tanggal (serial Excel), tanpa formula $L-$K.
        $tjbt->setCellValue('A17', 5);
        $this->text($tjbt, 'B17', 'Indikator Bertanggal');
        $tjbt->setCellValue('C17', 3);
        $tjbt->setCellValue('E17', 'Tanggal');
        $tjbt->setCellValue('K17', 46173); // 2026-05-31
        $tjbt->setCellValue('L17', 46163); // 2026-05-21
        $tjbt->setCellValue('M17', 1);

        // KET berisi angka hasil formula, seperti workbook nyata.
        $tjbt->setCellValue('A18', 6);
        $this->text($tjbt, 'B18', 'Indikator Ket Numerik');
        $tjbt->setCellValue('C18', 3);
        $tjbt->setCellValue('E18', '%');
        $tjbt->setCellValue('K18', 1);
        $tjbt->setCellValue('L18', 1);
        $tjbt->setCellValue('M18', 1);
        $tjbt->setCellValue('O18', 1.0025);

        // Grup wadah tanpa bobot dan tanpa nilai.
        $tjbt->setCellValue('A19', 7);
        $this->text($tjbt, 'B19', 'Kepatuhan');

        // Ringkasan + NKO: keduanya bukan indikator.
        $this->text($tjbt, 'B20', 'TOTAL BOBOT');
        $tjbt->setCellValue('F20', 25);
        $this->text($tjbt, 'B21', 'NILAI KINERJA ORGANISASI (NKO)');
        $tjbt->setCellValue('F21', 100);
        $tjbt->setCellValue('N21', 105.5);

        $tjbb = $spreadsheet->createSheet();
        $tjbb->setTitle('TJBB');
        $this->setValueHeaders($tjbb);
        $tjbb->setCellValue('A10', 1);
        $this->text($tjbb, 'B10', 'Faktor Ketersediaan Trafo dan Transmisi');
        $tjbb->setCellValue('C10', 3);
        $tjbb->setCellValue('F10', 20);
        $tjbb->setCellValue('M10', 1);
        $tjbb->setCellValue('N10', 80);
        $this->text($tjbb, 'B40', 'NILAI KINERJA ORGANISASI (NKO)');
        $tjbb->setCellValue('N40', 100);

        return $this->save($spreadsheet);
    }

    private function setValueHeaders(Worksheet $sheet): void
    {
        $sheet->setCellValue('K6', 'S.D. AGUSTUS 2026');
        $sheet->setCellValue('K7', 'TARGET');
        $sheet->setCellValue('L7', 'REALISASI');
        $sheet->setCellValue('M7', 'PENCAPAIAN');
        $sheet->setCellValue('N7', 'NILAI');
        $sheet->setCellValue('O7', 'KET');
    }

    /**
     * Tulis teks yang diawali "=" sebagai string, bukan formula.
     */
    private function text(Worksheet $sheet, string $cell, string $value): void
    {
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
    }

    private function save(Spreadsheet $spreadsheet): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'kpi_dual_').'.xlsx';
        $this->tempFiles[] = $path;

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(true);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'laporan-kpi-dual-write.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
