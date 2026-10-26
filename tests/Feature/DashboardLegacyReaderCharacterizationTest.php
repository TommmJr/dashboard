<?php

namespace Tests\Feature;

use App\Http\Controllers\DashboardController;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\PerformanceMetric;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * CHARACTERIZATION tests untuk reader dashboard LEGACY (Unit 5B, Fase 1).
 *
 * Tujuan: mengunci perilaku legacy yang ADA SEKARANG, apa adanya, sebelum
 * reader dimigrasi ke read model terstruktur. Test di sini BUKAN izin
 * memperbaiki bug legacy:
 *
 * - polaritas (kinerjaDetail) dibiarkan apa adanya, tidak "dikoreksi";
 * - agregasi total targetVsRealisasi dibiarkan apa adanya (C-1 belum diputuskan);
 * - perilaku NKO (pencapaianNko / nkoDonut) hanya dipotret, tidak dimigrasi.
 *
 * Semua reader dashboard bersifat private, jadi kontraknya (array hasil)
 * dipanggil lewat reflection supaya bentuk dan nilainya terkunci persis —
 * assertion HTML tidak bisa membedakan null vs 0 vs "0".
 */
class DashboardLegacyReaderCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // Karakterisasi ini mengunci kontrak LAIN (rumus, polaritas, ambang,
        // cap, MAX, urutan) memakai nama unit sebagai stand-in bebas. Batas
        // unit TAMPIL (keputusan 2026-10-12, `kpi.visible_units`) dimatikan di
        // sini supaya stand-in non-TJBT tetap valid; batas itu diuji tersendiri
        // di `KpiUnitScopeTest`.
        config(['kpi.visible_units' => null]);
    }

    /**
     * Panggil method private DashboardController tanpa mengubah aksesibilitas
     * permanen.
     */
    private function reader(string $method, array $args = []): mixed
    {
        $controller = app(DashboardController::class);
        $reflection = new ReflectionMethod($controller, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($controller, ...$args);
    }

    private function division(string $name): Division
    {
        return Division::factory()->create(['name' => $name]);
    }

    private function metric(Dataset $dataset, Division $division, string $name, float $value, string $period = '2026-08-31'): PerformanceMetric
    {
        return PerformanceMetric::factory()->create([
            'dataset_id' => $dataset->id,
            'division_id' => $division->id,
            'metric_name' => $name,
            'value' => $value,
            'period' => $period,
        ]);
    }

    private function management(): User
    {
        return User::factory()->withRole(Role::MANAGEMENT)->create();
    }

    // ---------------------------------------------------------------- R6 ----

    public function test_r6_kinerja_realisasi_menghitung_pencapaian_per_unit(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        // TJBT: 2 indikator -> 110% dan 90% ; rata-rata 100.0 -> Tercapai.
        $this->metric($dataset, $division, 'TJBT | Indikator A | target', 100.0);
        $this->metric($dataset, $division, 'TJBT | Indikator A | realisasi', 110.0);
        $this->metric($dataset, $division, 'TJBT | Indikator B | target', 100.0);
        $this->metric($dataset, $division, 'TJBT | Indikator B | realisasi', 90.0);

        // RST: 1 indikator -> 96% -> Hampir Tercapai.
        $this->metric($dataset, $division, 'RST | Indikator C | target', 100.0);
        $this->metric($dataset, $division, 'RST | Indikator C | realisasi', 96.0);

        $result = $this->reader('kinerjaRealisasi', [$this->management()]);

        $this->assertSame('2026-08-31', $result['period']);
        $this->assertTrue($result['has_data']);

        $byUnit = collect($result['rows'])->keyBy('unit');

        $this->assertSame(2, $byUnit['TJBT']['total']);
        $this->assertSame(1, $byUnit['TJBT']['achieved']);
        $this->assertSame(100.0, $byUnit['TJBT']['avg']);
        $this->assertSame('Tercapai', $byUnit['TJBT']['status']);

        $this->assertSame(0, $byUnit['RST']['achieved']);
        $this->assertSame(96.0, $byUnit['RST']['avg']);
        $this->assertSame('Hampir Tercapai', $byUnit['RST']['status']);
    }

    /**
     * Keputusan Pak Gama 2026-10-25: skor dibatasi 0..110% juga di jalur legacy
     * (ATTB rasio > 6x tidak boleh menyeret rata-rata). Legacy tidak punya skor
     * Excel/polaritas/baris induk, jadi hanya batas yang berlaku di sini.
     */
    public function test_r6_legacy_skor_dibatasi_110_persen(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->metric($dataset, $division, 'TJBT | ATTB | target', 28800.0);
        $this->metric($dataset, $division, 'TJBT | ATTB | realisasi', 181979.53);
        $this->metric($dataset, $division, 'TJBT | Indikator B | target', 100.0);
        $this->metric($dataset, $division, 'TJBT | Indikator B | realisasi', 90.0);

        $row = $this->reader('kinerjaRealisasi', [$this->management()])['rows'][0];

        // (110 + 90) / 2 = 100, bukan (632 + 90) / 2 = 361.
        $this->assertSame(100.0, $row['avg']);
        $this->assertSame(1, $row['achieved']);
    }

    public function test_r6_urut_menurun_berdasarkan_rata_rata(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->metric($dataset, $division, 'LOW | I | target', 100.0);
        $this->metric($dataset, $division, 'LOW | I | realisasi', 80.0);
        $this->metric($dataset, $division, 'HIGH | I | target', 100.0);
        $this->metric($dataset, $division, 'HIGH | I | realisasi', 120.0);

        $result = $this->reader('kinerjaRealisasi', [$this->management()]);

        $this->assertSame(['HIGH', 'LOW'], array_column($result['rows'], 'unit'));
    }

    public function test_r6_mengecualikan_target_nol_dan_realisasi_kosong(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        // Target 0 -> dibuang dari hitungan (pembagi nol).
        $this->metric($dataset, $division, 'A | I | target', 0.0);
        $this->metric($dataset, $division, 'A | I | realisasi', 50.0);
        // Realisasi tanpa target -> dibuang.
        $this->metric($dataset, $division, 'A | J | realisasi', 90.0);

        $result = $this->reader('kinerjaRealisasi', [$this->management()]);

        $this->assertFalse($result['has_data']);
        $this->assertSame([], $result['rows']);
    }

    public function test_r6_tanpa_data_mengembalikan_periode_null(): void
    {
        $result = $this->reader('kinerjaRealisasi', [$this->management()]);

        $this->assertNull($result['period']);
        $this->assertSame([], $result['rows']);
        $this->assertFalse($result['has_data']);
    }

    public function test_r6_dataset_archived_tidak_dihitung(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->archived()->create(['division_id' => $division->id]);

        $this->metric($dataset, $division, 'TJBT | I | target', 100.0);
        $this->metric($dataset, $division, 'TJBT | I | realisasi', 100.0);

        $result = $this->reader('kinerjaRealisasi', [$this->management()]);

        $this->assertNull($result['period']);
        $this->assertFalse($result['has_data']);
    }

    public function test_r6_staff_hanya_melihat_divisinya(): void
    {
        $own = $this->division('Divisi Sendiri');
        $other = $this->division('Divisi Lain');

        $ownDataset = Dataset::factory()->create(['division_id' => $own->id]);
        $otherDataset = Dataset::factory()->create(['division_id' => $other->id]);

        $this->metric($ownDataset, $own, 'OWN | I | target', 100.0);
        $this->metric($ownDataset, $own, 'OWN | I | realisasi', 100.0);
        $this->metric($otherDataset, $other, 'OTHER | I | target', 100.0);
        $this->metric($otherDataset, $other, 'OTHER | I | realisasi', 100.0);

        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $own->id]);

        $result = $this->reader('kinerjaRealisasi', [$staff]);

        $this->assertSame(['OWN'], array_column($result['rows'], 'unit'));
    }

    // ---------------------------------------------------------------- R7 ----

    public function test_r7_kinerja_detail_menggabungkan_target_realisasi_dan_nko(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->metric($dataset, $division, 'TJBT | Indikator A | target', 200.0);
        $this->metric($dataset, $division, 'TJBT | Indikator A | realisasi', 100.0);
        $this->metric($dataset, $division, 'TJBT | NKO', 105.5);

        $result = $this->reader('kinerjaDetail', [$this->management()]);

        $this->assertSame('2026-08-31', $result['period']);
        $this->assertFalse($result['truncated']);
        $this->assertSame(1, $result['total_rows']);
        $this->assertCount(1, $result['rows']);

        $row = $result['rows'][0];
        $this->assertSame('TJBT', $row['unit']);
        $this->assertSame('Indikator A', $row['indikator']);
        $this->assertSame(200.0, $row['target']);
        $this->assertSame(100.0, $row['realisasi']);

        // KARAKTERISASI, BUKAN KOREKSI: arah pencapaian legacy di sini adalah
        // realisasi/target * 100 = 50.0. Polaritas TIDAK dikoreksi di unit ini.
        // Baris R7 tidak punya kolom status; itu hanya ada di R6.
        $this->assertSame(50.0, $row['pencapaian']);
        $this->assertSame(105.5, $row['nko']);
    }

    public function test_r7_pencapaian_numerik_melebihi_110_persen_tanpa_cap(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->metric($dataset, $division, 'TJBT | Indikator Tinggi | target', 100.0);
        $this->metric($dataset, $division, 'TJBT | Indikator Tinggi | realisasi', 250.0);

        $result = $this->reader('kinerjaDetail', [$this->management()]);
        $row = $result['rows'][0];

        // R7 mempertahankan rasio murni round(realisasi / target * 100, 1) tanpa cap 110%
        $this->assertSame(250.0, $row['pencapaian']);
        $this->assertGreaterThan(110.0, $row['pencapaian']);
    }

    public function test_r7_baris_nko_tidak_menjadi_baris_detail(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->metric($dataset, $division, 'TJBT | NKO', 105.5);

        $result = $this->reader('kinerjaDetail', [$this->management()]);

        // NKO hanya menempel sebagai kolom unit, tidak menambah baris.
        $this->assertSame(0, $result['total_rows']);
        $this->assertSame([], $result['rows']);
    }

    public function test_r7_target_nol_menghasilkan_pencapaian_null(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->metric($dataset, $division, 'TJBT | I | target', 0.0);
        $this->metric($dataset, $division, 'TJBT | I | realisasi', 55.0);

        $result = $this->reader('kinerjaDetail', [$this->management()]);

        $row = $result['rows'][0];
        $this->assertSame(0.0, $row['target']);
        $this->assertSame(55.0, $row['realisasi']);
        $this->assertNull($row['pencapaian']);
        $this->assertNull($row['nko']);
    }

    public function test_r7_tanpa_data_struktur_kosong(): void
    {
        $result = $this->reader('kinerjaDetail', [$this->management()]);

        $this->assertSame(
            ['period' => null, 'rows' => [], 'truncated' => false, 'total_rows' => 0],
            $result,
        );
    }

    public function test_r7_dataset_archived_tidak_dihitung(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->archived()->create(['division_id' => $division->id]);

        $this->metric($dataset, $division, 'TJBT | I | target', 100.0);
        $this->metric($dataset, $division, 'TJBT | I | realisasi', 100.0);

        $result = $this->reader('kinerjaDetail', [$this->management()]);

        $this->assertNull($result['period']);
        $this->assertSame(0, $result['total_rows']);
    }

    // ---------------------------------------------------------------- R8 ----

    public function test_r8_target_vs_realisasi_menjumlahkan_seluruh_target_dan_realisasi(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->metric($dataset, $division, 'TJBT | A | target', 100.0);
        $this->metric($dataset, $division, 'TJBT | A | realisasi', 110.0);
        $this->metric($dataset, $division, 'TJBT | B | target', 200.0);
        $this->metric($dataset, $division, 'TJBT | B | realisasi', 190.0);

        $result = $this->reader('targetVsRealisasi', [$this->management()]);

        $this->assertSame('2026-08-31', $result['period']);

        // KARAKTERISASI agregasi legacy apa adanya (C-1 belum diputuskan):
        // target = 300 dan realisasi = 300, dijumlah mentah lintas indikator.
        $this->assertSame(['TJBT'], $result['labels']);
        $this->assertSame([300.0], $result['target']);
        $this->assertSame([300.0], $result['realisasi']);
    }

    public function test_r8_unit_hanya_target_tetap_muncul_dengan_realisasi_nol(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->metric($dataset, $division, 'TJBT | A | target', 150.0);

        $result = $this->reader('targetVsRealisasi', [$this->management()]);

        $this->assertSame(['TJBT'], $result['labels']);
        $this->assertSame([150.0], $result['target']);
        $this->assertSame([0.0], $result['realisasi']);
    }

    public function test_r8_tanpa_data_struktur_kosong(): void
    {
        $result = $this->reader('targetVsRealisasi', [$this->management()]);

        $this->assertSame(
            ['period' => null, 'labels' => [], 'target' => [], 'realisasi' => []],
            $result,
        );
    }

    public function test_r8_dataset_archived_tidak_dihitung(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->archived()->create(['division_id' => $division->id]);

        $this->metric($dataset, $division, 'TJBT | A | target', 100.0);

        $result = $this->reader('targetVsRealisasi', [$this->management()]);

        $this->assertNull($result['period']);
        $this->assertSame([], $result['labels']);
    }

    // ---------------------------------------------------------------- R9 ----

    public function test_r9_pencapaian_nko_memakai_rata_rata_kinerja_realisasi(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        // Nama widget menyebut NKO, tapi sumbernya baris target/realisasi R6.
        // Bentuk hasil: labels = unit terurut rata-rata desc, data = avg-nya.
        $this->metric($dataset, $division, 'TJBT | I | target', 100.0);
        $this->metric($dataset, $division, 'TJBT | I | realisasi', 105.0);

        $result = $this->reader('pencapaianNko', [$this->management()]);

        $this->assertSame('2026-08-31', $result['period']);
        $this->assertSame(['TJBT'], $result['labels']);
        $this->assertSame([105.0], $result['data']);
    }

    public function test_r9_tanpa_data_array_kosong(): void
    {
        $result = $this->reader('pencapaianNko', [$this->management()]);

        $this->assertNull($result['period']);
        $this->assertSame([], $result['labels']);
        $this->assertSame([], $result['data']);
    }

    // --------------------------------------------------------------- R10 ----

    public function test_r10_nko_donut_mengelompokkan_nilai_nko_per_kategori(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->metric($dataset, $division, 'TJBT | NKO', 105.5);
        $this->metric($dataset, $division, 'RST | NKO', 98.0);
        $this->metric($dataset, $division, 'MES | NKO', 80.0);

        $result = $this->reader('nkoDonut', [$this->management()]);

        // Urutan kategori mengikuti urutan array $counts di controller
        // (Tercapai, Hampir Tercapai, Perlu Peningkatan), bukan urutan input.
        $this->assertSame('2026-08-31', $result['period']);
        $this->assertSame([
            ['name' => 'Tercapai', 'value' => 1],
            ['name' => 'Hampir Tercapai', 'value' => 1],
            ['name' => 'Perlu Peningkatan', 'value' => 1],
        ], $result['data']);
    }

    public function test_r10_batas_kategori_95_dan_100_inklusif(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->metric($dataset, $division, 'A | NKO', 100.0);  // Tercapai (>=100)
        $this->metric($dataset, $division, 'B | NKO', 95.0);   // Hampir Tercapai (>=95)
        $this->metric($dataset, $division, 'C | NKO', 94.99);  // Perlu Peningkatan

        $result = $this->reader('nkoDonut', [$this->management()]);

        $this->assertSame([
            ['name' => 'Tercapai', 'value' => 1],
            ['name' => 'Hampir Tercapai', 'value' => 1],
            ['name' => 'Perlu Peningkatan', 'value' => 1],
        ], $result['data']);
    }

    public function test_r10_kategori_nol_tidak_dimunculkan(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->metric($dataset, $division, 'TJBT | NKO', 105.5);

        $result = $this->reader('nkoDonut', [$this->management()]);

        $this->assertSame([['name' => 'Tercapai', 'value' => 1]], $result['data']);
    }

    public function test_r10_tanpa_data_kosong(): void
    {
        $result = $this->reader('nkoDonut', [$this->management()]);

        $this->assertNull($result['period']);
        $this->assertSame([], $result['data']);
    }

    // ---------------------------------------------------------------- R1 ----

    /**
     * Asimetri dataset_id NULL antara R1 (chart API) dan reader dashboard lain.
     *
     * R1 memakai leftJoin + whereNull(dataset_id) sehingga baris tanpa dataset
     * TETAP tampil; R3/R6/R7/R8/R10 memakai inner join ke datasets sehingga
     * baris itu hilang. Ini karakterisasi perilaku legacy apa adanya.
     */
    public function test_r1_dataset_id_null_tetap_tampil_di_chart_tapi_hilang_di_reader_lain(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->metric($dataset, $division, 'DENGAN_DATASET | NKO', 100.0);
        // Tanpa dataset: tetap ada di performance_metrics, tetap dihitung chart.
        PerformanceMetric::factory()->create([
            'dataset_id' => null,
            'division_id' => $division->id,
            'metric_name' => 'TANPA_DATASET | NKO',
            'value' => 20.0,
            'period' => '2026-08-31',
        ]);

        $this->assertSame(2, PerformanceMetric::query()->count());

        // R1: kedua unit muncul (left join + whereNull). Urutan series
        // mengikuti kemunculan pertama dari query, jadi dibandingkan sebagai
        // himpunan, bukan indeks.
        $chart = $this->actingAs($this->management())
            ->get(route('performance.data'))
            ->assertOk()
            ->json();

        $this->assertSame(['2026-08-31'], $chart['labels']);
        $this->assertEqualsCanonicalizing(
            ['DENGAN_DATASET', 'TANPA_DATASET'],
            array_column($chart['series'], 'name'),
        );

        // R3 (nkoRanking): inner join -> hanya unit ber-dataset.
        $ranking = $this->reader('nkoRanking', [$this->management()]);
        $this->assertSame(['DENGAN_DATASET'], $ranking->pluck('unit')->all());

        // R10 (nkoDonut): inner join -> hanya 1 baris NKO yang dihitung.
        $donut = $this->reader('nkoDonut', [$this->management()]);
        $this->assertSame([['name' => 'Tercapai', 'value' => 1]], $donut['data']);
    }
}
