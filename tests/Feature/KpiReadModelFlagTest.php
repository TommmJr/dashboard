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
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Read-model flag KPI (Unit 5B): config('kpi.read_model').
 *
 * Kontrak yang dikunci di sini:
 *
 * 1. Default 'legacy' -> R2 dan R4 membaca performance_metrics persis.
 * 2. 'structured' -> R2 dan R4 membaca performance_observations / org_units.
 * 3. Reader yang BELUM dimigrasi (R5 datasetStatus, R8 targetVsRealisasi) TIDAK
 *    ikut flag: dengan data legacy saja mereka harus tetap mengembalikan hasil
 *    legacy, bukan kosong. Ini alasan keduanya memanggil latestLegacyReadyPeriod().
 * 4. R6 kinerjaRealisasi (dan R9 pencapaianNko yang mendelegasi ke sana) sudah
 *    pindah ke sumber terstruktur; kontraknya diuji di bawah.
 *
 * Catatan: 'structured' sengaja TIDAK aman untuk production (R5/R8 belum
 * dimigrasi). Test di sini menguji mekanisme flag, bukan kesiapan produksi
 * mode structured.
 */
class KpiReadModelFlagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // Test flag read-model mengunci MEKANISME sumber data (legacy vs
        // terstruktur) memakai nama unit stand-in bebas (RST, DARI_STRUKTUR,
        // OWN/OTHER). Batas unit TAMPIL (keputusan 2026-10-12,
        // `kpi.visible_units`) dimatikan supaya stand-in non-TJBT tetap valid;
        // batas itu diuji di `KpiUnitScopeTest`.
        config(['kpi.visible_units' => null]);
    }

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

    private function management(): User
    {
        return User::factory()->withRole(Role::MANAGEMENT)->create();
    }

    private function legacyMetric(Dataset $dataset, Division $division, string $name, float $value, string $period): void
    {
        PerformanceMetric::factory()->create([
            'dataset_id' => $dataset->id,
            'division_id' => $division->id,
            'metric_name' => $name,
            'value' => $value,
            'period' => $period,
        ]);
    }

    private function observation(Dataset $dataset, OrgUnit $unit, Division $division, string $period): PerformanceObservation
    {
        $indicator = Indicator::factory()->create(['org_unit_id' => $unit->id]);

        return PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indicator->id,
            'org_unit_id' => $unit->id,
            'division_id' => $division->id,
            'period' => $period,
        ]);
    }

    // ------------------------------------------------------ config default --

    public function test_default_read_model_adalah_legacy(): void
    {
        $this->assertSame('legacy', config('kpi.read_model'));
    }

    // ---------------------------------------------------------------- R4 ----

    public function test_r4_default_legacy_membaca_periode_dari_performance_metrics(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->legacyMetric($dataset, $division, 'TJBT | NKO', 100.0, '2026-08-31');
        $this->observation($dataset, OrgUnit::factory()->create(['name' => 'TJBT']), $division, '2026-07-01');

        $result = $this->reader('latestReadyPeriod', [$this->management()]);

        $this->assertSame('2026-08-31', $result);
    }

    public function test_r4_structured_membaca_periode_dari_performance_observations(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->legacyMetric($dataset, $division, 'TJBT | NKO', 100.0, '2026-08-31');
        $this->observation($dataset, OrgUnit::factory()->create(['name' => 'TJBT']), $division, '2026-07-01');

        $result = $this->reader('latestReadyPeriod', [$this->management()]);

        $this->assertSame('2026-07-01', $result);
    }

    public function test_r4_structured_tidak_menggeser_periode(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        // Tanggal 1 bulan tetap 1 bulan: tidak dikonversi ke akhir bulan.
        $this->observation($dataset, OrgUnit::factory()->create(['name' => 'TJBT']), $division, '2026-08-01');

        $result = $this->reader('latestReadyPeriod', [$this->management()]);

        $this->assertSame('2026-08-01', $result);
        $this->assertNotSame('2026-08-31', $result);
    }

    public function test_r4_structured_menghormati_status_dataset(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Pengusahaan');
        $ready = Dataset::factory()->create(['division_id' => $division->id]);
        $archived = Dataset::factory()->archived()->create(['division_id' => $division->id]);

        $this->observation($ready, OrgUnit::factory()->create(['name' => 'TJBT']), $division, '2026-07-01');
        $this->observation($archived, OrgUnit::factory()->create(['name' => 'RST']), $division, '2026-09-01');

        $result = $this->reader('latestReadyPeriod', [$this->management()]);

        // Dataset archived diabaikan -> periode terbaru tetap dari dataset READY.
        $this->assertSame('2026-07-01', $result);
    }

    public function test_r4_structured_menghormati_scope_divisi_staf(): void
    {
        config(['kpi.read_model' => 'structured']);

        $own = $this->division('Divisi Sendiri');
        $other = $this->division('Divisi Lain');

        $ownDataset = Dataset::factory()->create(['division_id' => $own->id]);
        $otherDataset = Dataset::factory()->create(['division_id' => $other->id]);

        $this->observation($ownDataset, OrgUnit::factory()->create(['name' => 'TJBT']), $own, '2026-07-01');
        $this->observation($otherDataset, OrgUnit::factory()->create(['name' => 'RST']), $other, '2026-09-01');

        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $own->id]);

        $this->assertSame('2026-07-01', $this->reader('latestReadyPeriod', [$staff]));
    }

    public function test_r4_structured_menghormati_rentang_tanggal(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);
        $unit = OrgUnit::factory()->create(['name' => 'TJBT']);

        $this->observation($dataset, $unit, $division, '2026-06-01');
        $this->observation($dataset, $unit, $division, '2026-08-01');

        $result = $this->reader('latestReadyPeriod', [$this->management(), '2026-06-01', '2026-06-30']);

        $this->assertSame('2026-06-01', $result);
    }

    public function test_r4_structured_tanpa_observasi_mengembalikan_null(): void
    {
        config(['kpi.read_model' => 'structured']);

        $this->assertNull($this->reader('latestReadyPeriod', [$this->management()]));
    }

    // ---------------------------------------------------------------- R2 ----

    public function test_r2_default_legacy_membaca_nama_unit_dari_metric_name(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->legacyMetric($dataset, $division, 'TJBT | NKO', 100.0, '2026-08-31');
        $this->legacyMetric($dataset, $division, 'RST | NKO', 99.0, '2026-08-31');

        $this->assertSame(['RST', 'TJBT'], $this->reader('availableUnits', [$this->management()]));
    }

    public function test_r2_structured_membaca_nama_unit_dari_org_units(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->observation($dataset, OrgUnit::factory()->create(['name' => 'TJBT']), $division, '2026-08-01');
        $this->observation($dataset, OrgUnit::factory()->create(['name' => 'RST']), $division, '2026-08-01');

        $this->assertSame(['RST', 'TJBT'], $this->reader('availableUnits', [$this->management()]));
    }

    public function test_r2_structured_tidak_memakai_metric_name_legacy(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        // Baris NKO legacy ada, tapi mode structured tidak boleh membacanya.
        $this->legacyMetric($dataset, $division, 'DARI_LEGACY | NKO', 100.0, '2026-08-31');
        $this->observation($dataset, OrgUnit::factory()->create(['name' => 'DARI_STRUKTUR']), $division, '2026-08-01');

        $this->assertSame(['DARI_STRUKTUR'], $this->reader('availableUnits', [$this->management()]));
    }

    public function test_r2_structured_menghormati_status_dataset(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Pengusahaan');
        $ready = Dataset::factory()->create(['division_id' => $division->id]);
        $archived = Dataset::factory()->archived()->create(['division_id' => $division->id]);

        $this->observation($ready, OrgUnit::factory()->create(['name' => 'READY_UNIT']), $division, '2026-08-01');
        $this->observation($archived, OrgUnit::factory()->create(['name' => 'ARCHIVED_UNIT']), $division, '2026-08-01');

        $this->assertSame(['READY_UNIT'], $this->reader('availableUnits', [$this->management()]));
    }

    public function test_r2_structured_menghormati_scope_divisi_staf(): void
    {
        config(['kpi.read_model' => 'structured']);

        $own = $this->division('Divisi Sendiri');
        $other = $this->division('Divisi Lain');

        $ownDataset = Dataset::factory()->create(['division_id' => $own->id]);
        $otherDataset = Dataset::factory()->create(['division_id' => $other->id]);

        $this->observation($ownDataset, OrgUnit::factory()->create(['name' => 'OWN_UNIT']), $own, '2026-08-01');
        $this->observation($otherDataset, OrgUnit::factory()->create(['name' => 'OTHER_UNIT']), $other, '2026-08-01');

        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $own->id]);

        $this->assertSame(['OWN_UNIT'], $this->reader('availableUnits', [$staff]));
    }

    public function test_r2_structured_tanpa_observasi_mengembalikan_array_kosong(): void
    {
        config(['kpi.read_model' => 'structured']);

        $this->assertSame([], $this->reader('availableUnits', [$this->management()]));
    }

    // --------------------------------- reader belum dimigrasi tetap legacy ----

    /**
     * Reader yang BELUM dimigrasi TIDAK boleh ikut flag: dengan data legacy
     * saja mereka harus tetap mengembalikan hasil legacy, bukan kosong.
     *
     * R3/R7/R10 SUDAH pindah ke sumber terstruktur (NKO punya representasi),
     * begitu juga R6 (+ R9 yang mendelegasi ke R6), jadi mereka TIDAK lagi
     * diuji di sini. Yang masih dipin ke legacy: R5 datasetStatus dan
     * R8 targetVsRealisasi.
     */
    public function test_reader_belum_dimigrasi_tetap_legacy_saat_flag_structured(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->legacyMetric($dataset, $division, 'TJBT | Indikator A | target', 100.0, '2026-08-31');
        $this->legacyMetric($dataset, $division, 'TJBT | Indikator A | realisasi', 110.0, '2026-08-31');

        $user = $this->management();

        // R8 targetVsRealisasi: tetap legacy, tidak kosong.
        $tvr = $this->reader('targetVsRealisasi', [$user]);
        $this->assertSame('2026-08-31', $tvr['period']);
        $this->assertSame(['TJBT'], $tvr['labels']);
    }

    // ---------------------------------------------------------------- R6 ----

    /**
     * R6 kinerjaRealisasi dengan flag structured: periode dan nilai diambil
     * dari performance_observations pada dataset TERBARU, sedangkan baris
     * legacy diabaikan.
     */
    public function test_r6_kinerja_realisasi_structured_membaca_observasi(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Pengusahaan');
        $unit = OrgUnit::factory()->create(['name' => 'TJBT']);
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        // Baris legacy di periode yang sama: HARUS diabaikan di mode structured.
        $this->legacyMetric($dataset, $division, 'TJBT | Indikator A | target', 1.0, '2026-08-31');
        $this->legacyMetric($dataset, $division, 'TJBT | Indikator A | realisasi', 1.0, '2026-08-31');

        $this->observation($dataset, $unit, $division, '2026-08-31')
            ->update(['target_value' => 100.0, 'realisasi_value' => 110.0, 'pencapaian' => 1.1]);

        $result = $this->reader('kinerjaRealisasi', [$this->management()]);

        $this->assertSame('2026-08-31', $result['period']);
        $this->assertTrue($result['has_data']);
        $this->assertSame(1, count($result['rows']));

        $row = $result['rows'][0];
        $this->assertSame('TJBT', $row['unit']);
        $this->assertSame(110.0, $row['avg']);
        $this->assertSame(1, $row['achieved']);
        $this->assertSame('Tercapai', $row['status']);
    }

    /**
     * Target teks tanpa target numerik TAPI berskor Excel: baris daun berskor
     * tetap dihitung (keputusan Pak Gama 2026-10-25). Sebelumnya dikunci
     * "tidak dihitung"; kontrak itu dibalik. Skor 110.0 tersimpan dalam persen
     * (kasus observasi #410) dan tidak boleh menjadi 11000%.
     */
    public function test_r6_structured_target_teks_berskor_ikut_dihitung(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Pengusahaan');
        $unit = OrgUnit::factory()->create(['name' => 'TJBT']);
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->observation($dataset, $unit, $division, '2026-08-31')
            ->update([
                'target_value' => null,
                'target_text' => 'Sesuai RKAP',
                'target_type' => PerformanceObservation::TYPE_TEXT,
                'realisasi_value' => 90.0,
                'pencapaian' => 110.0,
            ]);

        $result = $this->reader('kinerjaRealisasi', [$this->management()]);

        $this->assertSame('2026-08-31', $result['period']);
        $this->assertTrue($result['has_data']);
        $this->assertSame(1, $result['rows'][0]['total']);
        $this->assertSame(110.0, $result['rows'][0]['avg']);
    }

    /**
     * Dataset terbaru menang: dua dataset READY memuat (unit, indikator,
     * periode) yang sama, hanya yang paling baru dipakai. Jalur legacy
     * menampilkan keduanya (last-writer-wins), terstruktur tidak — divergensi
     * yang disengaja dan dikunci di sini.
     */
    public function test_r6_structured_memakai_dataset_terbaru_saja(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Pengusahaan');
        $unit = OrgUnit::factory()->create(['name' => 'TJBT']);

        $older = Dataset::factory()->create([
            'division_id' => $division->id,
            'created_at' => now()->subDay(),
        ]);
        $newer = Dataset::factory()->create([
            'division_id' => $division->id,
            'created_at' => now(),
        ]);

        $this->observation($older, $unit, $division, '2026-08-31')
            ->update(['target_value' => 100.0, 'realisasi_value' => 10.0, 'pencapaian' => 0.1]);
        $this->observation($newer, $unit, $division, '2026-08-31')
            ->update(['target_value' => 100.0, 'realisasi_value' => 80.0, 'pencapaian' => 0.8]);

        $result = $this->reader('kinerjaRealisasi', [$this->management()]);

        $this->assertSame(1, count($result['rows']));
        $this->assertSame(80.0, $result['rows'][0]['avg']);
    }

    /**
     * Scoping divisi ikut aturan yang sama: staf divisi hanya melihat divisinya.
     */
    public function test_r6_structured_staf_divisi_hanya_melihat_divisinya(): void
    {
        config(['kpi.read_model' => 'structured']);

        $own = $this->division('Pengusahaan');
        $other = $this->division('Niaga');

        $ownDataset = Dataset::factory()->create(['division_id' => $own->id]);
        $otherDataset = Dataset::factory()->create(['division_id' => $other->id]);

        $this->observation($ownDataset, OrgUnit::factory()->create(['name' => 'OWN']), $own, '2026-08-31')
            ->update(['target_value' => 100.0, 'realisasi_value' => 100.0, 'pencapaian' => 1.0]);
        $this->observation($otherDataset, OrgUnit::factory()->create(['name' => 'OTHER']), $other, '2026-08-31')
            ->update(['target_value' => 100.0, 'realisasi_value' => 100.0, 'pencapaian' => 1.0]);

        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $own->id]);

        $result = $this->reader('kinerjaRealisasi', [$staff]);

        $this->assertSame(['OWN'], array_column($result['rows'], 'unit'));
    }

    /**
     * Kasus acuan keputusan Pak Gama 2026-10-19: TJBT baris 33 workbook
     * Agustus 2026. Target 46173 (31 Mei 2026), realisasi 46163 (21 Mei 2026),
     * keduanya serial tanggal Excel; M33 berisi skor PENCAPAIAN
     * 1.0142857142857142 (format 0.00% = 101,43%).
     *
     * R6 structured harus memakai SKOR itu, bukan rasio 46163/46173, dan
     * mengalikannya ke skala persen yang dipakai consumer (rasio x 100).
     */
    public function test_r6_structured_target_tanggal_memakai_skor_excel_tjbt_baris_33(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Pengusahaan');
        $unit = OrgUnit::factory()->create(['name' => 'TJBT']);
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->observation($dataset, $unit, $division, '2026-08-31')
            ->update([
                'target_value' => null,
                'target_date' => '2026-05-31',
                'target_type' => PerformanceObservation::TYPE_DATE,
                'realisasi_value' => null,
                'realisasi_date' => '2026-05-21',
                'realisasi_type' => PerformanceObservation::TYPE_DATE,
                'pencapaian' => 1.0142857142857142,
                'source_sheet' => 'TJBT',
                'source_row' => 33,
            ]);

        $result = $this->reader('kinerjaRealisasi', [$this->management()]);

        $this->assertTrue($result['has_data']);
        $this->assertCount(1, $result['rows']);

        $row = $result['rows'][0];
        $this->assertSame('TJBT', $row['unit']);
        $this->assertSame(1, $row['total']);
        $this->assertSame(1, $row['achieved']);

        // round(1.0143 x 100, 1) = 101.4: skor workbook 1.0142857142857142
        // terbawa pada skala persen yang dipakai consumer.
        $this->assertSame(101.4, $row['avg']);
        $this->assertSame('Tercapai', $row['status']);
    }

    /**
     * Batas skor berasal dari rumus Excel (0..1,1), bukan dari kode aplikasi:
     * nilai di luar rentang itu diteruskan apa adanya karena datanya berasal
     * dari workbook. Yang dikunci di sini adalah skala, bukan clamp ulang.
     */
    public function test_r6_structured_target_tanggal_meneruskan_batas_skor_excel(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Pengusahaan');
        $unit = OrgUnit::factory()->create(['name' => 'TJBT']);
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $observations = [
            'Batas Atas' => ['realisasi_date' => '2026-04-01', 'pencapaian' => 1.1],
            'Batas Bawah' => ['realisasi_date' => '2027-05-31', 'pencapaian' => 0.0],
        ];

        foreach ($observations as $name => $values) {
            $indicator = Indicator::factory()->create(['org_unit_id' => $unit->id, 'name' => $name]);

            PerformanceObservation::factory()->create([
                'dataset_id' => $dataset->id,
                'indicator_id' => $indicator->id,
                'org_unit_id' => $unit->id,
                'division_id' => $division->id,
                'period' => '2026-08-31',
                'target_value' => null,
                'target_type' => PerformanceObservation::TYPE_DATE,
                'target_date' => '2026-05-31',
                'realisasi_value' => null,
                'realisasi_type' => PerformanceObservation::TYPE_DATE,
                ...$values,
            ]);
        }

        $row = $this->reader('kinerjaRealisasi', [$this->management()])['rows'][0];

        // (110 + 0) / 2 = 55 persen.
        $this->assertSame(2, $row['total']);
        $this->assertSame(1, $row['achieved']);
        $this->assertSame(55.0, $row['avg']);
        $this->assertSame('Perlu Peningkatan', $row['status']);
    }

    /**
     * Tanggal mentah (serial 46173) tidak boleh diperlakukan sebagai skor:
     * kalau ikut, pencapaian akan terbaca 4.617.300 persen.
     */
    public function test_r6_structured_tanggal_mentah_bukan_skor(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Pengusahaan');
        $unit = OrgUnit::factory()->create(['name' => 'TJBT']);
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        // target_date 2026-05-31 = serial 46173. Skor tidak ada.
        $this->observation($dataset, $unit, $division, '2026-08-31')
            ->update([
                'target_value' => null,
                'target_date' => '2026-05-31',
                'target_type' => PerformanceObservation::TYPE_DATE,
                'realisasi_value' => null,
                'realisasi_date' => '2026-05-21',
                'realisasi_type' => PerformanceObservation::TYPE_DATE,
                'pencapaian' => null,
            ]);

        $result = $this->reader('kinerjaRealisasi', [$this->management()]);

        $this->assertSame([], $result['rows']);
        $this->assertFalse($result['has_data']);
    }

    /**
     * Baris tanggal berskor dicampur dengan baris numerik berskor: keduanya
     * memakai skor Excel (keputusan 2026-10-25), bukan rasio realisasi/target.
     * Sebelumnya numerik tetap memakai rasio (110/100 = 110); kontrak itu
     * dibalik: skor Excel 1,05 menang -> 105.
     */
    public function test_r6_structured_numerik_berskor_memakai_skor_excel_bersama_baris_tanggal(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Pengusahaan');
        $unit = OrgUnit::factory()->create(['name' => 'TJBT']);
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        // Numerik: skor Excel 1,05 menang atas rasio 110/100.
        $this->observation($dataset, $unit, $division, '2026-08-31')
            ->update([
                'target_value' => 100.0,
                'realisasi_value' => 110.0,
                'pencapaian' => 1.05,
            ]);

        // Tanggal: skor Excel 1,0142857 -> 101,42857 persen.
        $this->observation($dataset, $unit, $division, '2026-08-31')
            ->update([
                'target_value' => null,
                'target_date' => '2026-05-31',
                'target_type' => PerformanceObservation::TYPE_DATE,
                'realisasi_value' => null,
                'realisasi_date' => '2026-05-21',
                'realisasi_type' => PerformanceObservation::TYPE_DATE,
                'pencapaian' => 1.0142857142857142,
            ]);

        $row = $this->reader('kinerjaRealisasi', [$this->management()])['rows'][0];

        $this->assertSame(2, $row['total']);
        $this->assertSame(2, $row['achieved']);
        // (105 + 101.42857142857) / 2 = 103.2142857 -> 103.2
        $this->assertSame(103.2, $row['avg']);
        $this->assertSame('Tercapai', $row['status']);
    }

    /**
     * Buat observasi daun untuk unit TJBT dengan polaritas/skor/target
     * dikendalikan test. Mengembalikan indikatornya agar bisa dijadikan induk.
     *
     * @param  array<string, mixed>  $indicator
     * @param  array<string, mixed>  $observation
     */
    private function leaf(Dataset $dataset, OrgUnit $unit, Division $division, array $indicator, array $observation): Indicator
    {
        $model = Indicator::factory()->create(['org_unit_id' => $unit->id, ...$indicator]);

        PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $model->id,
            'org_unit_id' => $unit->id,
            'division_id' => $division->id,
            'period' => '2026-08-31',
            'pencapaian' => null,
            ...$observation,
        ]);

        return $model;
    }

    /** @return array{0: Division, 1: OrgUnit, 2: Dataset} */
    private function tjbtFixture(): array
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Pengusahaan');

        return [
            $division,
            OrgUnit::factory()->create(['name' => 'TJBT']),
            Dataset::factory()->create(['division_id' => $division->id]),
        ];
    }

    /**
     * Skala skor: rasio 1,0143 -> 101,43; persen 110,0 tetap 110 (#410);
     * batas rasio 1,1 -> 110; di atas 1,1 (termasuk rentang (1,1; 1,5]) ->
     * dibaca persen apa adanya; negatif -> 0; > 110 -> 110.
     */
    public function test_r6_structured_normalisasi_skala_skor_rasio_dan_persen(): void
    {
        $cases = [
            'ratio' => [1.0143, 101.4],
            'percent #410' => [110.0, 110.0],
            'batas rasio' => [1.1, 110.0],
            'di atas batas rasio' => [1.2, 1.2],
            'batas mutasi 1,5' => [1.5, 1.5],
            'nol' => [0.0, 0.0],
            'persen 87,5' => [87.5, 87.5],
            'negatif' => [-0.3, 0.0],
            'persen di atas cap' => [150.0, 110.0],
        ];

        [$division, , $dataset] = $this->tjbtFixture();

        foreach ($cases as $label => [$stored, $expected]) {
            // Satu unit per kasus supaya rata-rata tiap kasus terisolasi.
            $unit = OrgUnit::factory()->create(['name' => $label]);
            $this->leaf($dataset, $unit, $division, [], [
                'target_value' => null, 'target_type' => PerformanceObservation::TYPE_TEXT,
                'realisasi_value' => null, 'realisasi_type' => PerformanceObservation::TYPE_TEXT,
                'pencapaian' => $stored,
            ]);
        }

        $byUnit = collect($this->reader('kinerjaRealisasi', [$this->management()])['rows'])->keyBy('unit');

        foreach ($cases as $label => [, $expected]) {
            $this->assertSame($expected, $byUnit[$label]['avg'], $label);
        }
    }

    /**
     * Kontrak 2026-10-25 memakai skor Excel (pencapaian): baris daun dengan
     * target dan realisasi numerik tetapi pencapaian NULL dilewati (tidak
     * dibuatkan skor pengganti dari rasio maupun polaritas).
     */
    public function test_r6_structured_baris_numerik_tanpa_skor_excel_dilewati(): void
    {
        [$division, $unit, $dataset] = $this->tjbtFixture();

        $numeric = ['target_type' => PerformanceObservation::TYPE_NUMERIC, 'realisasi_type' => PerformanceObservation::TYPE_NUMERIC];

        $this->leaf($dataset, $unit, $division, ['polarity' => 'higher_is_better'], ['target_value' => 100.0, 'realisasi_value' => 90.0, ...$numeric]);
        $this->leaf($dataset, $unit, $division, ['polarity' => 'lower_is_better'], ['target_value' => 100.0, 'realisasi_value' => 90.0, ...$numeric]);
        $this->leaf($dataset, $unit, $division, [], ['target_value' => 0.0, 'realisasi_value' => 5.0, ...$numeric]);
        $this->leaf($dataset, $unit, $division, [], ['target_value' => 100.0, 'realisasi_value' => null, ...$numeric]);

        $empty = $this->reader('kinerjaRealisasi', [$this->management()]);
        $this->assertSame([], $empty['rows']);
        $this->assertFalse($empty['has_data']);

        // Hanya baris yang memiliki skor Excel yang ikut dihitung.
        $this->leaf($dataset, $unit, $division, ['polarity' => 'higher_is_better'], [
            'target_value' => 100.0,
            'realisasi_value' => 10.0,
            'pencapaian' => 0.8,
            ...$numeric,
        ]);

        $row = $this->reader('kinerjaRealisasi', [$this->management()])['rows'][0];

        $this->assertSame(1, $row['total']);
        $this->assertSame(80.0, $row['avg']);
    }

    /**
     * Skor Excel dibatasi ke rentang 0..110% (negatif -> 0, di atas 110 -> 110).
     */
    public function test_r6_structured_skor_excel_dibatasi_0_sampai_110(): void
    {
        [$division, $unit, $dataset] = $this->tjbtFixture();

        $numeric = ['target_type' => PerformanceObservation::TYPE_NUMERIC, 'realisasi_type' => PerformanceObservation::TYPE_NUMERIC];

        $this->leaf($dataset, $unit, $division, [], ['target_value' => 100.0, 'realisasi_value' => 300.0, 'pencapaian' => -0.5, ...$numeric]);
        $this->leaf($dataset, $unit, $division, [], ['target_value' => 100.0, 'realisasi_value' => 150.0, 'pencapaian' => 150.0, ...$numeric]);
        $this->leaf($dataset, $unit, $division, [], ['target_value' => 100.0, 'realisasi_value' => 10.0, 'pencapaian' => 1.0, ...$numeric]);

        $row = $this->reader('kinerjaRealisasi', [$this->management()])['rows'][0];

        $this->assertSame(3, $row['total']);
        // (0 + 110 + 100) / 3 = 70.0
        $this->assertSame(70.0, $row['avg']);
    }

    /**
     * Baris induk (is_group = 1) tidak dihitung walau punya skor; baris daun
     * berskor tanpa target numerik dihitung; rata-rata sederhana tanpa bobot.
     */
    public function test_r6_structured_induk_dikecualikan_daun_berskor_dihitung_rata_rata_sederhana(): void
    {
        [$division, $unit, $dataset] = $this->tjbtFixture();

        $group = $this->leaf($dataset, $unit, $division, ['is_group' => true, 'bobot_value' => 50], ['pencapaian' => 1.1]);

        $textual = ['target_value' => null, 'target_type' => PerformanceObservation::TYPE_TEXT, 'realisasi_value' => null, 'realisasi_type' => PerformanceObservation::TYPE_TEXT];

        // Daun berskor tanpa target numerik, bobot besar: bobot diabaikan.
        $this->leaf($dataset, $unit, $division, ['parent_id' => $group->id, 'bobot_value' => 90], [...$textual, 'pencapaian' => 1.0]);
        $this->leaf($dataset, $unit, $division, ['parent_id' => $group->id, 'bobot_value' => 10], [...$textual, 'pencapaian' => 0.8]);

        $row = $this->reader('kinerjaRealisasi', [$this->management()])['rows'][0];

        // Induk (110) tidak ikut: (100 + 80) / 2 = 90, bukan 96.7 maupun berbobot 98.
        $this->assertSame(2, $row['total']);
        $this->assertSame(90.0, $row['avg']);
        $this->assertSame(1, $row['achieved']);
        $this->assertSame('Perlu Peningkatan', $row['status']);
    }

    /**
     * ATTB: realisasi 181.979,53 vs target 28.800 (rasio > 6x) diterima apa
     * adanya, dan skor Excel dibatasi 110% (baik rasio 1,1 maupun persen > 110).
     */
    public function test_r6_structured_attb_rasio_di_atas_6x_dibatasi_110(): void
    {
        [$division, $unit, $dataset] = $this->tjbtFixture();

        $numeric = ['target_type' => PerformanceObservation::TYPE_NUMERIC, 'realisasi_type' => PerformanceObservation::TYPE_NUMERIC];

        $this->leaf($dataset, $unit, $division, ['polarity' => 'higher_is_better'], ['target_value' => 28800.0, 'realisasi_value' => 181979.53, 'pencapaian' => 631.98, ...$numeric]);

        $row = $this->reader('kinerjaRealisasi', [$this->management()])['rows'][0];
        $this->assertSame(110.0, $row['avg']);

        // Dengan skor Excel cached 1,1 hasilnya sama.
        $this->leaf($dataset, $unit, $division, ['polarity' => 'higher_is_better'], ['target_value' => 28800.0, 'realisasi_value' => 181979.53, 'pencapaian' => 1.1, ...$numeric]);
        $row = $this->reader('kinerjaRealisasi', [$this->management()])['rows'][0];
        $this->assertSame(110.0, $row['avg']);
    }

    /** R9 mengikuti R6: nilai TJBT dengan baris induk, daun berskor, persen < 110, dan ATTB. */
    public function test_r9_structured_regresi_nilai_tjbt_kontrak_2026_10_25(): void
    {
        [$division, $unit, $dataset] = $this->tjbtFixture();

        $numeric = ['target_type' => PerformanceObservation::TYPE_NUMERIC, 'realisasi_type' => PerformanceObservation::TYPE_NUMERIC];
        $textual = ['target_value' => null, 'target_type' => PerformanceObservation::TYPE_TEXT, 'realisasi_value' => null, 'realisasi_type' => PerformanceObservation::TYPE_TEXT];

        $this->leaf($dataset, $unit, $division, ['is_group' => true], ['pencapaian' => 0.5]);
        $this->leaf($dataset, $unit, $division, ['polarity' => 'higher_is_better'], ['target_value' => 28800.0, 'realisasi_value' => 181979.53, 'pencapaian' => 631.98, ...$numeric]);
        $this->leaf($dataset, $unit, $division, [], [...$textual, 'pencapaian' => 1.0485]);
        $this->leaf($dataset, $unit, $division, ['polarity' => 'higher_is_better'], [...$textual, 'pencapaian' => 110.0]);
        $this->leaf($dataset, $unit, $division, ['polarity' => 'lower_is_better'], [...$textual, 'pencapaian' => 1.0143]);
        $this->leaf($dataset, $unit, $division, ['polarity' => 'higher_is_better'], [...$textual, 'pencapaian' => 87.5]);

        $result = $this->reader('pencapaianNko', [$this->management()]);

        // (110 + 104.85 + 110 + 101.43 + 87.5) / 5 = 102.756 -> 102.8
        $this->assertSame(['TJBT'], $result['labels']);
        $this->assertSame([102.8], $result['data']);
    }

    /**
     * R9 pencapaianNko tidak punya cabang sendiri: ia mendelegasi ke R6, jadi
     * begitu R6 structured, R9 ikut structured.
     */
    public function test_r9_pencapaian_nko_ikut_structured_lewat_r6(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Pengusahaan');
        $unit = OrgUnit::factory()->create(['name' => 'TJBT']);
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->legacyMetric($dataset, $division, 'TJBT | I | target', 1.0, '2026-08-31');
        $this->legacyMetric($dataset, $division, 'TJBT | I | realisasi', 1.0, '2026-08-31');

        $this->observation($dataset, $unit, $division, '2026-08-31')
            ->update(['target_value' => 100.0, 'realisasi_value' => 105.0, 'pencapaian' => 1.05]);

        $result = $this->reader('pencapaianNko', [$this->management()]);

        $this->assertSame('2026-08-31', $result['period']);
        $this->assertSame(['TJBT'], $result['labels']);
        $this->assertSame([105.0], $result['data']);
    }

    /**
     * Konsekuensi baru yang harus disadari: menyalakan 'structured' dengan data
     * legacy SAJA membuat R3/R10 kosong, karena keduanya sekarang benar-benar
     * membaca tabel terstruktur. Ini bukan bug — itu memang arti flag.
     */
    public function test_nko_reader_structured_kosong_tanpa_data_terstruktur(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);
        $this->legacyMetric($dataset, $division, 'TJBT | NKO', 105.5, '2026-08-31');

        $user = $this->management();

        $this->assertSame([], $this->reader('nkoRanking', [$user])->all());

        $donut = $this->reader('nkoDonut', [$user]);
        $this->assertNull($donut['period']);
        $this->assertSame([], $donut['data']);
    }

    /**
     * R5 datasetStatus: latest_rows tetap menghitung baris performance_metrics.
     * latest_period juga tetap legacy supaya widget tidak jadi campuran
     * (periode structured + hitungan legacy).
     */
    public function test_r5_dataset_status_tetap_legacy_saat_flag_structured(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->legacyMetric($dataset, $division, 'TJBT | NKO', 105.5, '2026-08-31');
        $this->observation($dataset, OrgUnit::factory()->create(['name' => 'TJBT']), $division, '2026-08-01');

        $status = $this->reader('datasetStatus', [$this->management()]);

        $this->assertSame('2026-08-31', $status['latest_period']);
        $this->assertSame(1, $status['latest_rows']);
    }
}
