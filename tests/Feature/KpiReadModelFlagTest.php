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

    private function legacyMetric(Dataset $dataset, ?Division $division, string $name, float $value, string $period): void
    {
        PerformanceMetric::factory()->create([
            'dataset_id' => $dataset->id,
            'division_id' => $division?->id,
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
    private function leaf(Dataset $dataset, OrgUnit $unit, ?Division $division, array $indicator, array $observation): Indicator
    {
        $model = Indicator::factory()->create(['org_unit_id' => $unit->id, ...$indicator]);

        PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $model->id,
            'org_unit_id' => $unit->id,
            'division_id' => $division?->id,
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
     * T1b (opsi 6b): jalur legacy R6 (kinerjaRealisasi) dan R9 (pencapaianNko)
     * identik dengan jalur structured ketika kedua sumber di-seed pada dataset
     * READY, divisi, dan periode yang sama.
     *
     * Mencakup: pair lengkap berskor; lower-is-better dengan rasio rendah tapi
     * skor 1,1; satu sisi bergaya #403 (realisasi saja) dan #410 (target saja,
     * skor persen 110,0); text/text berskor tanpa baris metric; baris induk
     * (is_group = 1); serta daun numerik berskor NULL.
     */
    public function test_r6_dan_r9_legacy_identik_dengan_structured_pada_kontrak_skor_excel(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);
        $period = '2026-08-31';

        $tjbt = OrgUnit::factory()->create(['name' => 'TJBT']);
        $uPair = OrgUnit::factory()->create(['name' => 'IND_PAIR_LENGKAP']);
        $uLower = OrgUnit::factory()->create(['name' => 'IND_LOWER_110']);
        $u403 = OrgUnit::factory()->create(['name' => 'IND_GAYA_403']);
        $u410 = OrgUnit::factory()->create(['name' => 'IND_GAYA_410']);
        $uTeks = OrgUnit::factory()->create(['name' => 'IND_TEKS_BERSKOR']);
        $uInduk = OrgUnit::factory()->create(['name' => 'IND_INDUK']);
        $uNull = OrgUnit::factory()->create(['name' => 'IND_DAUN_NULL']);

        $seedScenario = function (OrgUnit $unit, string $name, ?float $pmTarget, ?float $pmRealisasi, array $indAttrs, array $obsAttrs) use ($dataset, $division, $period): void {
            if ($pmTarget !== null) {
                $this->legacyMetric($dataset, $division, "{$unit->name} | {$name} | target", $pmTarget, $period);
            }
            if ($pmRealisasi !== null) {
                $this->legacyMetric($dataset, $division, "{$unit->name} | {$name} | realisasi", $pmRealisasi, $period);
            }

            $this->leaf($dataset, $unit, $division, ['name' => $name, ...$indAttrs], ['period' => $period, ...$obsAttrs]);
        };

        $numeric = ['target_type' => PerformanceObservation::TYPE_NUMERIC, 'realisasi_type' => PerformanceObservation::TYPE_NUMERIC];
        $textual = ['target_value' => null, 'target_type' => PerformanceObservation::TYPE_TEXT, 'realisasi_value' => null, 'realisasi_type' => PerformanceObservation::TYPE_TEXT];

        $scenarios = [
            [$uPair, 'a. Faktor Ketersediaan Trafo (TRAF)', 100.0, 100.0, ['polarity' => 'higher_is_better'], ['target_value' => 100.0, 'realisasi_value' => 100.0, 'pencapaian' => 1.05, ...$numeric]],
            [$uLower, 'a. Kali sirkit padam karena gangguan', 5.33, 4.0, ['polarity' => 'lower_is_better'], ['target_value' => 5.326, 'realisasi_value' => 4.0, 'pencapaian' => 1.1, ...$numeric]],
            [$u403, 'a. Pengendalian Penggunaan Anggaran Investasi sesuai RKAP', null, 99.85, [], ['target_value' => null, 'target_type' => PerformanceObservation::TYPE_TEXT, 'target_text' => '95% - 105%', 'realisasi_value' => 99.85, 'realisasi_type' => PerformanceObservation::TYPE_NUMERIC, 'pencapaian' => 1.0485]],
            [$u410, 'Penyelesaian Dokumen Legal Aset Tanah PLN', 261.0, null, ['polarity' => 'higher_is_better'], ['target_value' => 261.0, 'target_type' => PerformanceObservation::TYPE_NUMERIC, 'realisasi_value' => null, 'realisasi_type' => PerformanceObservation::TYPE_TEXT, 'realisasi_text' => 'Sesuai Target : 283', 'pencapaian' => 110.0]],
            [$uTeks, 'Pencapaian Program Green Enabling Super Grid', null, null, [], [...$textual, 'target_text' => '100%', 'realisasi_text' => '100%', 'pencapaian' => 0.96]],
            [$uInduk, 'Keandalan Line Transmisi dan Trafo Transmisi', 100.0, 80.0, ['is_group' => true], ['target_value' => 100.0, 'realisasi_value' => 80.0, 'pencapaian' => 1.0667, ...$numeric]],
            [$uNull, 'a. Penyelesaian Kajian Kelayakan Proyek (KKP)', 100.0, 90.0, [], ['target_value' => 100.0, 'realisasi_value' => 90.0, 'pencapaian' => null, ...$numeric]],
        ];

        foreach ($scenarios as [$isolatedUnit, $name, $pmTarget, $pmRealisasi, $indAttrs, $obsAttrs]) {
            $seedScenario($tjbt, $name, $pmTarget, $pmRealisasi, $indAttrs, $obsAttrs);
            $seedScenario($isolatedUnit, $name, $pmTarget, $pmRealisasi, $indAttrs, $obsAttrs);
        }

        $user = $this->management();

        config(['kpi.read_model' => 'structured']);
        $structuredR6 = $this->reader('kinerjaRealisasi', [$user]);
        $structuredR9 = $this->reader('pencapaianNko', [$user]);

        config(['kpi.read_model' => 'legacy']);
        $legacyR6 = $this->reader('kinerjaRealisasi', [$user]);
        $legacyR9 = $this->reader('pencapaianNko', [$user]);

        $this->assertSame($structuredR6, $legacyR6);
        $this->assertSame($structuredR9, $legacyR9);

        $byUnit = collect($legacyR6['rows'])->keyBy('unit');

        // Indikator yang masuk hitungan harus identik; induk & skor NULL terbuang.
        $this->assertSame(
            ['IND_GAYA_403', 'IND_GAYA_410', 'IND_LOWER_110', 'IND_PAIR_LENGKAP', 'IND_TEKS_BERSKOR', 'TJBT'],
            $byUnit->keys()->sort()->values()->all(),
        );
        $this->assertFalse($byUnit->has('IND_INDUK'));
        $this->assertFalse($byUnit->has('IND_DAUN_NULL'));

        // Agregat TJBT: (105 + 110 + 104.85 + 110 + 96) / 5 = 525.85 / 5 = 105.17 -> 105.2.
        $this->assertSame(5, $byUnit['TJBT']['total']);
        $this->assertSame(4, $byUnit['TJBT']['achieved']);
        $this->assertSame(105.2, $byUnit['TJBT']['avg']);
        $this->assertSame('Tercapai', $byUnit['TJBT']['status']);
    }

    /**
     * Fallback rasio dasar dipertahankan untuk dataset tanpa observations, dan
     * kunci (dataset_id, period, unit, indikator) yang cocok ke > 1 observation
     * dikeluarkan dari hitungan tanpa jatuh ke fallback rasio (termasuk saat
     * salah satu kandidat merupakan group atau memiliki pencapaian NULL).
     */
    public function test_r6_legacy_tanpa_observations_memakai_rasio_dasar_dan_pencocokan_ambigu_dilewati(): void
    {
        config(['kpi.read_model' => 'legacy']);

        $division = $this->division('Pengusahaan');
        $period = '2026-08-31';

        // 1. Dataset tanpa observations: tetap memakai rasio dasar + clamp 0..110.
        $legacyOnlyDataset = Dataset::factory()->create(['division_id' => $division->id]);
        $this->legacyMetric($legacyOnlyDataset, $division, 'UNIT_LEGACY | Indikator Rasio | target', 100.0, $period);
        $this->legacyMetric($legacyOnlyDataset, $division, 'UNIT_LEGACY | Indikator Rasio | realisasi', 85.0, $period);

        // 2. Dataset dengan observations termasuk kunci ambigu (> 1 observation).
        $dualDataset = Dataset::factory()->create(['division_id' => $division->id]);
        $tjbt = OrgUnit::factory()->create(['name' => 'TJBT']);
        $otherUnit = OrgUnit::factory()->create(['name' => 'OTHER_OWNER']);

        // Satu indikator valid sebagai pembanding.
        $this->legacyMetric($dualDataset, $division, 'TJBT | Indikator Valid | target', 100.0, $period);
        $this->legacyMetric($dualDataset, $division, 'TJBT | Indikator Valid | realisasi', 100.0, $period);
        $this->leaf($dualDataset, $tjbt, $division, ['name' => 'Indikator Valid'], [
            'period' => $period,
            'target_value' => 100.0,
            'realisasi_value' => 100.0,
            'pencapaian' => 1.04,
        ]);

        // Kunci ambigu 1 (injeksi tanpa pair legacy): dua indicator bernama sama
        // menunjuk org_unit observation TJBT pada dataset & periode yang sama.
        $indAmbiguInjeksiA = Indicator::factory()->create(['org_unit_id' => $tjbt->id, 'name' => 'Indikator Ambigu Injeksi']);
        $indAmbiguInjeksiB = Indicator::factory()->create(['org_unit_id' => $otherUnit->id, 'name' => 'Indikator Ambigu Injeksi']);
        foreach ([[$indAmbiguInjeksiA, 1.0], [$indAmbiguInjeksiB, 0.5]] as [$ind, $score]) {
            PerformanceObservation::factory()->create([
                'dataset_id' => $dualDataset->id,
                'indicator_id' => $ind->id,
                'org_unit_id' => $tjbt->id,
                'division_id' => $division->id,
                'period' => $period,
                'target_value' => null,
                'target_type' => PerformanceObservation::TYPE_TEXT,
                'realisasi_value' => null,
                'realisasi_type' => PerformanceObservation::TYPE_TEXT,
                'pencapaian' => $score,
            ]);
        }

        // Kunci ambigu 2 (pair lengkap target + realisasi, dua daun berskor):
        // bila bocor ke fallback rasio akan menambah 80,0% ke TJBT.
        $this->legacyMetric($dualDataset, $division, 'TJBT | Indikator Ambigu Pair Daun | target', 100.0, $period);
        $this->legacyMetric($dualDataset, $division, 'TJBT | Indikator Ambigu Pair Daun | realisasi', 80.0, $period);
        $indAmbiguPairA = Indicator::factory()->create(['org_unit_id' => $tjbt->id, 'name' => 'Indikator Ambigu Pair Daun']);
        $indAmbiguPairB = Indicator::factory()->create(['org_unit_id' => $otherUnit->id, 'name' => 'Indikator Ambigu Pair Daun']);
        foreach ([[$indAmbiguPairA, 1.1], [$indAmbiguPairB, 0.6]] as [$ind, $score]) {
            PerformanceObservation::factory()->create([
                'dataset_id' => $dualDataset->id,
                'indicator_id' => $ind->id,
                'org_unit_id' => $tjbt->id,
                'division_id' => $division->id,
                'period' => $period,
                'target_value' => 100.0,
                'realisasi_value' => 80.0,
                'pencapaian' => $score,
            ]);
        }

        // Kunci ambigu 3 (pair lengkap target + realisasi, salah satu kandidat is_group = true):
        // bila bocor ke fallback rasio akan menambah 70,0% ke TJBT.
        $this->legacyMetric($dualDataset, $division, 'TJBT | Indikator Ambigu Group | target', 100.0, $period);
        $this->legacyMetric($dualDataset, $division, 'TJBT | Indikator Ambigu Group | realisasi', 70.0, $period);
        $indAmbiguGroupA = Indicator::factory()->create(['org_unit_id' => $tjbt->id, 'name' => 'Indikator Ambigu Group', 'is_group' => true]);
        $indAmbiguGroupB = Indicator::factory()->create(['org_unit_id' => $otherUnit->id, 'name' => 'Indikator Ambigu Group', 'is_group' => false]);
        foreach ([[$indAmbiguGroupA, 1.1], [$indAmbiguGroupB, 1.0]] as [$ind, $score]) {
            PerformanceObservation::factory()->create([
                'dataset_id' => $dualDataset->id,
                'indicator_id' => $ind->id,
                'org_unit_id' => $tjbt->id,
                'division_id' => $division->id,
                'period' => $period,
                'target_value' => 100.0,
                'realisasi_value' => 70.0,
                'pencapaian' => $score,
            ]);
        }

        // Kunci ambigu 4 (pair lengkap target + realisasi, salah satu kandidat pencapaian NULL):
        // bila bocor ke fallback rasio akan menambah 60,0% ke TJBT.
        $this->legacyMetric($dualDataset, $division, 'TJBT | Indikator Ambigu Null | target', 100.0, $period);
        $this->legacyMetric($dualDataset, $division, 'TJBT | Indikator Ambigu Null | realisasi', 60.0, $period);
        $indAmbiguNullA = Indicator::factory()->create(['org_unit_id' => $tjbt->id, 'name' => 'Indikator Ambigu Null']);
        $indAmbiguNullB = Indicator::factory()->create(['org_unit_id' => $otherUnit->id, 'name' => 'Indikator Ambigu Null']);
        foreach ([[$indAmbiguNullA, null], [$indAmbiguNullB, 0.9]] as [$ind, $score]) {
            PerformanceObservation::factory()->create([
                'dataset_id' => $dualDataset->id,
                'indicator_id' => $ind->id,
                'org_unit_id' => $tjbt->id,
                'division_id' => $division->id,
                'period' => $period,
                'target_value' => 100.0,
                'realisasi_value' => 60.0,
                'pencapaian' => $score,
            ]);
        }

        $controller = app(DashboardController::class);
        $reflection = new ReflectionMethod($controller, 'kinerjaRealisasi');
        $reflection->setAccessible(true);
        $result = $reflection->invoke($controller, $this->management());

        $ambiguousProp = new \ReflectionProperty($controller, 'kinerjaRealisasiAmbiguousCount');
        $ambiguousProp->setAccessible(true);
        $this->assertSame(4, $ambiguousProp->getValue($controller));

        $byUnit = collect($result['rows'])->keyBy('unit');

        $this->assertSame(1, $byUnit['UNIT_LEGACY']['total']);
        $this->assertSame(0, $byUnit['UNIT_LEGACY']['achieved']);
        $this->assertSame(85.0, $byUnit['UNIT_LEGACY']['avg']);

        $this->assertSame(1, $byUnit['TJBT']['total']);
        $this->assertSame(1, $byUnit['TJBT']['achieved']);
        $this->assertSame(104.0, $byUnit['TJBT']['avg']);
    }

    /**
     * M2: dua dataset READY pada periode dan divisi yang sama tidak boleh
     * menggabungkan target/realisasi lintas dataset ataupun melakukan overlay
     * observation ketika sumber dataset pasangan tidak unik; pasangan dari
     * satu dataset konsisten tanpa observation tetap mendapat fallback rasio.
     */
    public function test_r6_legacy_tidak_menggabungkan_pasangan_lintas_dataset_atau_overlay_sumber_ambigu(): void
    {
        config(['kpi.read_model' => 'legacy']);

        $division = $this->division('Pengusahaan');
        $period = '2026-08-31';

        $datasetA = Dataset::factory()->create(['division_id' => $division->id]);
        $datasetB = Dataset::factory()->create(['division_id' => $division->id]);

        // 1. Target di dataset A, realisasi di dataset B (tanpa observation):
        // tidak boleh digabung menjadi rasio 90%.
        $this->legacyMetric($datasetA, $division, 'UNIT_SPLIT | Indikator Split | target', 100.0, $period);
        $this->legacyMetric($datasetB, $division, 'UNIT_SPLIT | Indikator Split | realisasi', 90.0, $period);

        // 2. Target + observation (105%) di dataset A, realisasi di dataset B:
        // tidak boleh memakai target ?? realisasi untuk meng-overlay 105% atau jatuh ke rasio 80%.
        $unitSplitObs = OrgUnit::factory()->create(['name' => 'UNIT_SPLIT_OBS']);
        $this->legacyMetric($datasetA, $division, 'UNIT_SPLIT_OBS | Indikator Split Obs | target', 100.0, $period);
        $this->legacyMetric($datasetB, $division, 'UNIT_SPLIT_OBS | Indikator Split Obs | realisasi', 80.0, $period);
        $this->leaf($datasetA, $unitSplitObs, $division, ['name' => 'Indikator Split Obs'], [
            'period' => $period,
            'target_value' => 100.0,
            'realisasi_value' => 100.0,
            'pencapaian' => 1.05,
        ]);

        // 3. Dua dataset READY sama-sama memiliki pasangan lengkap + observation
        // untuk (unit, indikator) yang sama: sumber tidak unik -> dikeluarkan.
        // Pasangan lain yang hanya berasal dari satu dataset konsisten tetap dihitung.
        $unitDuaDataset = OrgUnit::factory()->create(['name' => 'UNIT_DUA_DATASET']);
        $this->legacyMetric($datasetA, $division, 'UNIT_DUA_DATASET | Indikator Konflik | target', 100.0, $period);
        $this->legacyMetric($datasetA, $division, 'UNIT_DUA_DATASET | Indikator Konflik | realisasi', 80.0, $period);
        $indKonflik = $this->leaf($datasetA, $unitDuaDataset, $division, ['name' => 'Indikator Konflik'], [
            'period' => $period,
            'target_value' => 100.0,
            'realisasi_value' => 80.0,
            'pencapaian' => 0.85,
        ]);

        $this->legacyMetric($datasetB, $division, 'UNIT_DUA_DATASET | Indikator Konflik | target', 100.0, $period);
        $this->legacyMetric($datasetB, $division, 'UNIT_DUA_DATASET | Indikator Konflik | realisasi', 95.0, $period);
        PerformanceObservation::factory()->create([
            'dataset_id' => $datasetB->id,
            'indicator_id' => $indKonflik->id,
            'org_unit_id' => $unitDuaDataset->id,
            'division_id' => $division->id,
            'period' => $period,
            'target_value' => 100.0,
            'realisasi_value' => 95.0,
            'pencapaian' => 0.95,
        ]);

        // Pasangan konsisten dari satu sumber dataset (dataset A) tanpa observation -> rasio 92%.
        $this->legacyMetric($datasetA, $division, 'UNIT_DUA_DATASET | Indikator Konsisten | target', 100.0, $period);
        $this->legacyMetric($datasetA, $division, 'UNIT_DUA_DATASET | Indikator Konsisten | realisasi', 92.0, $period);

        $controller = app(DashboardController::class);
        $reflection = new ReflectionMethod($controller, 'kinerjaRealisasi');
        $reflection->setAccessible(true);
        $result = $reflection->invoke($controller, $this->management());

        $ambiguousProp = new \ReflectionProperty($controller, 'kinerjaRealisasiAmbiguousCount');
        $ambiguousProp->setAccessible(true);
        $this->assertSame(3, $ambiguousProp->getValue($controller));

        $byUnit = collect($result['rows'])->keyBy('unit');

        $this->assertFalse($byUnit->has('UNIT_SPLIT'));
        $this->assertFalse($byUnit->has('UNIT_SPLIT_OBS'));
        $this->assertTrue($byUnit->has('UNIT_DUA_DATASET'));
        $this->assertSame(1, $byUnit['UNIT_DUA_DATASET']['total']);
        $this->assertSame(0, $byUnit['UNIT_DUA_DATASET']['achieved']);
        $this->assertSame(92.0, $byUnit['UNIT_DUA_DATASET']['avg']);
    }

    /**
     * L2: daun berskor tanpa baris performance_metrics tetap diinjeksi bila
     * berasal dari satu kandidat dataset READY dalam scope (periode, divisi,
     * unit), tetapi dilewati tanpa menebak bila terdapat > 1 kandidat dataset.
     */
    public function test_r6_legacy_injeksi_daun_berskor_tanpa_metric_dan_menolak_multi_dataset_kandidat(): void
    {
        config(['kpi.read_model' => 'legacy']);

        $division = $this->division('Pengusahaan');
        $otherDivision = $this->division('Divisi Lain');
        $period = '2026-08-31';

        $unitTanpaMetric = OrgUnit::factory()->create(['name' => 'UNIT_TANPA_METRIC']);
        $datasetTanpaMetric = Dataset::factory()->create(['division_id' => $division->id]);

        $textual = [
            'period' => $period,
            'target_value' => null,
            'target_type' => PerformanceObservation::TYPE_TEXT,
            'realisasi_value' => null,
            'realisasi_type' => PerformanceObservation::TYPE_TEXT,
        ];

        $this->leaf($datasetTanpaMetric, $unitTanpaMetric, $division, ['name' => 'Daun Berskor Murni'], [
            ...$textual,
            'pencapaian' => 0.96,
        ]);

        // 1. Tanpa baris performance_metrics sama sekali di DB: tetap terinjeksi.
        $soloResult = $this->reader('kinerjaRealisasi', [$this->management()]);
        $this->assertSame($period, $soloResult['period']);
        $this->assertCount(1, $soloResult['rows']);
        $this->assertSame('UNIT_TANPA_METRIC', $soloResult['rows'][0]['unit']);
        $this->assertSame(96.0, $soloResult['rows'][0]['avg']);

        // 2. Saat dataset READY lain di periode sama punya performance_metrics,
        // datasetTanpaMetric tidak boleh tereliminasi oleh penyempitan metricDatasetIds.
        // Dataset ARCHIVED atau milik divisi lain di luar scope staf juga tidak mengganggu.
        $datasetMetricLain = Dataset::factory()->create(['division_id' => $division->id]);
        $this->legacyMetric($datasetMetricLain, $division, 'UNIT_METRIC | Indikator A | target', 100.0, $period);
        $this->legacyMetric($datasetMetricLain, $division, 'UNIT_METRIC | Indikator A | realisasi', 100.0, $period);

        $archivedDataset = Dataset::factory()->archived()->create(['division_id' => $division->id]);
        $this->leaf($archivedDataset, $unitTanpaMetric, $division, ['name' => 'Daun Archived'], [
            ...$textual,
            'pencapaian' => 0.50,
        ]);

        $otherDivDataset = Dataset::factory()->create(['division_id' => $otherDivision->id]);
        $this->leaf($otherDivDataset, $unitTanpaMetric, $otherDivision, ['name' => 'Daun Divisi Lain'], [
            ...$textual,
            'pencapaian' => 0.40,
        ]);

        // 3. Unit dengan > 1 kandidat dataset READY dalam scope yang sama:
        // tidak ada injeksi yang menebak sumber.
        $unitMultiKandidat = OrgUnit::factory()->create(['name' => 'UNIT_MULTI_KANDIDAT']);
        $kandidat1 = Dataset::factory()->create(['division_id' => $division->id]);
        $kandidat2 = Dataset::factory()->create(['division_id' => $division->id]);
        $this->leaf($kandidat1, $unitMultiKandidat, $division, ['name' => 'Daun Kandidat 1'], [
            ...$textual,
            'pencapaian' => 1.05,
        ]);
        $this->leaf($kandidat2, $unitMultiKandidat, $division, ['name' => 'Daun Kandidat 2'], [
            ...$textual,
            'pencapaian' => 0.80,
        ]);

        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);

        $controller = app(DashboardController::class);
        $reflection = new ReflectionMethod($controller, 'kinerjaRealisasi');
        $reflection->setAccessible(true);
        $result = $reflection->invoke($controller, $staff);

        $ambiguousProp = new \ReflectionProperty($controller, 'kinerjaRealisasiAmbiguousCount');
        $ambiguousProp->setAccessible(true);
        $this->assertSame(1, $ambiguousProp->getValue($controller));

        $byUnit = collect($result['rows'])->keyBy('unit');

        $this->assertSame(['UNIT_METRIC', 'UNIT_TANPA_METRIC'], $byUnit->keys()->sort()->values()->all());
        $this->assertSame(96.0, $byUnit['UNIT_TANPA_METRIC']['avg']);
        $this->assertSame(100.0, $byUnit['UNIT_METRIC']['avg']);
        $this->assertFalse($byUnit->has('UNIT_MULTI_KANDIDAT'));
    }

    /**
     * L3: pencocokan nama indikator antara metric_name legacy dan indicators.name
     * menggunakan kesamaan string persis (tanpa normalisasi huruf besar-kecil
     * atau trimming yang dapat mengubah identitas indikator secara diam-diam).
     */
    public function test_r6_legacy_pencocokan_nama_indikator_persis_tanpa_normalisasi(): void
    {
        config(['kpi.read_model' => 'legacy']);

        $division = $this->division('Pengusahaan');
        $period = '2026-08-31';
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);
        $tjbt = OrgUnit::factory()->create(['name' => 'TJBT']);

        $this->legacyMetric($dataset, $division, 'TJBT | Indikator Presisi | target', 100.0, $period);
        $this->legacyMetric($dataset, $division, 'TJBT | Indikator Presisi | realisasi', 80.0, $period);

        // Observation dengan perbedaan huruf besar-kecil tidak dianggap cocok ke
        // 'Indikator Presisi' (dan karena is_group = true tidak diinjeksi).
        $this->leaf($dataset, $tjbt, $division, ['name' => 'indikator presisi', 'is_group' => true], [
            'period' => $period,
            'target_value' => 100.0,
            'realisasi_value' => 80.0,
            'pencapaian' => 1.1,
        ]);

        $beforeExact = $this->reader('kinerjaRealisasi', [$this->management()]);
        $this->assertSame(80.0, $beforeExact['rows'][0]['avg']);

        // Observation dengan nama persis sama baru meng-overlay skor Excel.
        $this->leaf($dataset, $tjbt, $division, ['name' => 'Indikator Presisi'], [
            'period' => $period,
            'target_value' => 100.0,
            'realisasi_value' => 80.0,
            'pencapaian' => 1.05,
        ]);

        $afterExact = $this->reader('kinerjaRealisasi', [$this->management()]);
        $this->assertSame(105.0, $afterExact['rows'][0]['avg']);
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
     * R5 datasetStatus structured: periode observasi dinormalisasi ke akhir
     * bulan (kontrak legacy), latest_rows menghitung observasi, bukan
     * performance_metrics.
     */
    public function test_r5_dataset_status_structured_normalisasi_akhir_bulan_dan_parity(): void
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

    public function test_r5_structured_parity_dengan_legacy_pada_fixture_sama(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);
        $this->legacyMetric($dataset, $division, 'TJBT | NKO', 105.5, '2026-08-31');
        $this->observation($dataset, OrgUnit::factory()->create(['name' => 'TJBT']), $division, '2026-08-15');
        $user = $this->management();

        $legacy = $this->reader('datasetStatus', [$user]);
        config(['kpi.read_model' => 'structured']);
        $structured = $this->reader('datasetStatus', [$user]);

        $this->assertSame(['latest_period', 'ready', 'archived', 'failed', 'latest_rows', 'fresh'], array_keys($structured));
        $this->assertSame($legacy, $structured);
    }

    public function test_r5_structured_latest_rows_nol_tanpa_unit_tampil_dan_rollback_null(): void
    {
        config(['kpi.read_model' => 'structured']);
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);
        $this->observation($dataset, OrgUnit::factory()->create(['name' => 'MES']), $division, '2026-08-01');
        config(['kpi.visible_units' => ['TJBT']]);
        $user = $this->management();

        $status = $this->reader('datasetStatus', [$user]);
        $this->assertSame('2026-08-31', $status['latest_period']);
        $this->assertSame(0, $status['latest_rows']);

        config(['kpi.visible_units' => null]);
        $this->assertSame(1, $this->reader('datasetStatus', [$user])['latest_rows']);
    }

    public function test_r5_structured_divisi_non_kpi_mendapat_periode_dari_metrics(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Manajemen Aset');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);
        $this->legacyMetric($dataset, $division, 'Total Aset', 1000.0, '2026-08-15');

        $user = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);

        $status = $this->reader('datasetStatus', [$user]);

        $this->assertSame('2026-08-31', $status['latest_period']);
        $this->assertSame(0, $status['latest_rows']);
        $this->assertSame(1, $status['ready']);
        $this->assertSame(0, $status['archived']);
        $this->assertSame(0, $status['failed']);
        $this->assertFalse($status['fresh']);
    }

    public function test_r5_structured_divisi_dengan_observasi_tidak_terpengaruh_metric_only_yang_lebih_baru(): void
    {
        config(['kpi.read_model' => 'structured']);

        $kpiDivision = $this->division('Pengusahaan');
        $kpiDataset = Dataset::factory()->create(['division_id' => $kpiDivision->id]);
        $this->observation($kpiDataset, OrgUnit::factory()->create(['name' => 'TJBT']), $kpiDivision, '2026-08-01');

        // Metric baru pada divisi yang sama
        $this->legacyMetric($kpiDataset, $kpiDivision, 'Metric Legacy Baru', 100.0, '2026-09-15');

        // Metric baru pada divisi lain
        $otherDivision = $this->division('Manajemen Aset');
        $otherDataset = Dataset::factory()->create(['division_id' => $otherDivision->id]);
        $this->legacyMetric($otherDataset, $otherDivision, 'Metric Aset', 500.0, '2026-09-20');

        $kpiStaff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $kpiDivision->id]);
        $statusStaff = $this->reader('datasetStatus', [$kpiStaff]);
        $this->assertSame('2026-08-31', $statusStaff['latest_period']);
        $this->assertSame(1, $statusStaff['latest_rows']);

        $mgmt = $this->management();
        $statusMgmt = $this->reader('datasetStatus', [$mgmt]);
        $this->assertSame('2026-08-31', $statusMgmt['latest_period']);
        $this->assertSame(1, $statusMgmt['latest_rows']);
    }

    public function test_r5_structured_divisi_tanpa_data_tetap_null(): void
    {
        config(['kpi.read_model' => 'structured']);

        $emptyDivision = $this->division('Divisi Kosong');
        $user = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $emptyDivision->id]);

        $status = $this->reader('datasetStatus', [$user]);

        $this->assertNull($status['latest_period']);
        $this->assertSame(0, $status['latest_rows']);
        $this->assertSame(0, $status['ready']);
        $this->assertFalse($status['fresh']);
    }

    public function test_r5_structured_divisi_non_kpi_rollback_visible_units_null_tetap_nol(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Manajemen Aset');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);
        $this->legacyMetric($dataset, $division, 'Total Aset', 1000.0, '2026-08-15');

        $user = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);

        config(['kpi.visible_units' => ['TJBT']]);
        $statusWithUnits = $this->reader('datasetStatus', [$user]);
        $this->assertSame('2026-08-31', $statusWithUnits['latest_period']);
        $this->assertSame(0, $statusWithUnits['latest_rows']);

        config(['kpi.visible_units' => null]);
        $statusNullUnits = $this->reader('datasetStatus', [$user]);
        $this->assertSame('2026-08-31', $statusNullUnits['latest_period']);
        $this->assertSame(0, $statusNullUnits['latest_rows']);
    }

    public function test_r5_karakterisasi_privileged_structured_mengunci_periode_observasi_saat_ada_metrics_baru_divisi_lain(): void
    {
        $kpiDivision = $this->division('Pengusahaan');
        $kpiDataset = Dataset::factory()->create(['division_id' => $kpiDivision->id]);
        $this->legacyMetric($kpiDataset, $kpiDivision, 'TJBT | NKO', 100.0, '2026-08-31');
        $this->observation($kpiDataset, OrgUnit::factory()->create(['name' => 'TJBT']), $kpiDivision, '2026-08-31');

        $otherDivision = $this->division('Manajemen Aset');
        $otherDataset = Dataset::factory()->create(['division_id' => $otherDivision->id]);
        $this->legacyMetric($otherDataset, $otherDivision, 'Total Aset', 1000.0, '2026-09-30');

        $admin = User::factory()->withRole(Role::ADMIN)->create();

        // Mode legacy: role privileged membaca periode terbaru lintas divisi dari performance_metrics (2026-09-30).
        config(['kpi.read_model' => 'legacy']);
        $legacy = $this->reader('datasetStatus', [$admin]);
        $this->assertSame('2026-09-30', $legacy['latest_period']);
        $this->assertSame(2, $legacy['ready']);
        $this->assertSame(0, $legacy['archived']);
        $this->assertSame(0, $legacy['failed']);

        // Mode structured: role privileged mengunci periode observasi (2026-08-31);
        // fallback metrics tidak pernah berjalan karena latestStructuredReadyPeriod() terisi.
        config(['kpi.read_model' => 'structured']);
        $structured = $this->reader('datasetStatus', [$admin]);
        $this->assertSame('2026-08-31', $structured['latest_period']);
        $this->assertSame(1, $structured['latest_rows']);
        $this->assertSame(2, $structured['ready']);
        $this->assertSame(0, $structured['archived']);
        $this->assertSame(0, $structured['failed']);
        $this->assertFalse($structured['fresh']);
    }

    /**
     * Karakterisasi paritas R5: metadata status (latest_period, ready, archived,
     * failed, fresh) identik antara legacy dan structured pada fixture deterministik,
     * sedangkan latest_rows membuktikan divergensi model data yang disahkan
     * (41 baris metrics vs 30 observasi indikator).
     */
    public function test_r5_karakterisasi_paritas_dan_divergensi_model_data_baris_aktif(): void
    {
        $division = $this->division('Perencanaan Pengusahaan');
        $dataset = Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_READY,
        ]);
        $unit = OrgUnit::factory()->create(['name' => 'TJBT']);
        $otherUnit = OrgUnit::factory()->create(['name' => 'MES']);

        // 1. Buat 41 baris performance_metrics untuk TJBT (model legacy):
        // - 16 pasangan target & realisasi (32 baris)
        // - 7 baris grup induk
        // - 1 baris NKO
        // - 1 baris standalone
        for ($i = 1; $i <= 16; $i++) {
            $this->legacyMetric($dataset, $division, "TJBT | Indikator {$i} | target", 100.0, '2026-08-31');
            $this->legacyMetric($dataset, $division, "TJBT | Indikator {$i} | realisasi", 100.0, '2026-08-31');
        }
        for ($g = 1; $g <= 7; $g++) {
            $this->legacyMetric($dataset, $division, "TJBT | Group {$g}", 100.0, '2026-08-31');
        }
        $this->legacyMetric($dataset, $division, 'TJBT | Standalone Indikator', 100.0, '2026-08-31');
        $this->legacyMetric($dataset, $division, 'TJBT | NKO', 105.5, '2026-08-31');

        // Tambah 5 baris untuk unit lain (MES) untuk memvalidasi scoping unit tampil
        for ($m = 1; $m <= 5; $m++) {
            $this->legacyMetric($dataset, $division, "MES | Indikator {$m} | target", 100.0, '2026-08-31');
        }

        // 2. Buat 30 baris performance_observations untuk TJBT (model terstruktur):
        // - 16 observasi rincian (target + realisasi dalam 1 baris)
        // - 7 observasi grup induk
        // - 7 observasi lain dalam sheet
        for ($i = 1; $i <= 16; $i++) {
            $ind = Indicator::factory()->create(['org_unit_id' => $unit->id, 'name' => "Indikator {$i}", 'is_group' => false]);
            PerformanceObservation::factory()->create([
                'dataset_id' => $dataset->id,
                'indicator_id' => $ind->id,
                'org_unit_id' => $unit->id,
                'division_id' => $division->id,
                'period' => '2026-08-01',
                'target_value' => 100.0,
                'realisasi_value' => 100.0,
                'pencapaian' => 1.0,
            ]);
        }
        for ($g = 1; $g <= 7; $g++) {
            $grp = Indicator::factory()->create(['org_unit_id' => $unit->id, 'name' => "Group {$g}", 'is_group' => true]);
            PerformanceObservation::factory()->create([
                'dataset_id' => $dataset->id,
                'indicator_id' => $grp->id,
                'org_unit_id' => $unit->id,
                'division_id' => $division->id,
                'period' => '2026-08-01',
                'target_value' => null,
                'realisasi_value' => null,
                'pencapaian' => 1.0,
            ]);
        }
        for ($o = 1; $o <= 7; $o++) {
            $oth = Indicator::factory()->create(['org_unit_id' => $unit->id, 'name' => "Other {$o}", 'is_group' => false]);
            PerformanceObservation::factory()->create([
                'dataset_id' => $dataset->id,
                'indicator_id' => $oth->id,
                'org_unit_id' => $unit->id,
                'division_id' => $division->id,
                'period' => '2026-08-01',
                'target_value' => null,
                'realisasi_value' => null,
                'pencapaian' => null,
            ]);
        }

        // Tambah 5 observasi untuk unit lain (MES)
        for ($m = 1; $m <= 5; $m++) {
            $mesInd = Indicator::factory()->create(['org_unit_id' => $otherUnit->id, 'name' => "MES Indikator {$m}", 'is_group' => false]);
            PerformanceObservation::factory()->create([
                'dataset_id' => $dataset->id,
                'indicator_id' => $mesInd->id,
                'org_unit_id' => $otherUnit->id,
                'division_id' => $division->id,
                'period' => '2026-08-01',
                'target_value' => 100.0,
                'realisasi_value' => 100.0,
                'pencapaian' => 1.0,
            ]);
        }

        config(['kpi.visible_units' => ['TJBT']]);
        $user = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);

        // 1. Eksekusi Mode Legacy:
        config(['kpi.read_model' => 'legacy']);
        $legacy = $this->reader('datasetStatus', [$user]);

        $this->assertSame('2026-08-31', $legacy['latest_period']);
        $this->assertSame(1, $legacy['ready']);
        $this->assertSame(0, $legacy['archived']);
        $this->assertSame(0, $legacy['failed']);
        $this->assertSame(41, $legacy['latest_rows']);
        $this->assertSame(now()->subDays(45)->toDateString() <= '2026-08-31', $legacy['fresh']);

        // 2. Eksekusi Mode Structured:
        config(['kpi.read_model' => 'structured']);
        $structured = $this->reader('datasetStatus', [$user]);

        $this->assertSame('2026-08-31', $structured['latest_period']);
        $this->assertSame(1, $structured['ready']);
        $this->assertSame(0, $structured['archived']);
        $this->assertSame(0, $structured['failed']);
        $this->assertSame(30, $structured['latest_rows']);
        $this->assertSame(now()->subDays(45)->toDateString() <= '2026-08-31', $structured['fresh']);

        // 3. Buktikan kesetaraan paritas field non-rows:
        $this->assertSame($legacy['latest_period'], $structured['latest_period']);
        $this->assertSame($legacy['ready'], $structured['ready']);
        $this->assertSame($legacy['archived'], $structured['archived']);
        $this->assertSame($legacy['failed'], $structured['failed']);
        $this->assertSame($legacy['fresh'], $structured['fresh']);

        // 4. Buktikan divergensi sah model data pada latest_rows:
        $this->assertNotSame($legacy['latest_rows'], $structured['latest_rows']);
        $this->assertSame(41, $legacy['latest_rows']);
        $this->assertSame(30, $structured['latest_rows']);

        // 5. Buktikan rollback null units memperhitungkan unit lain (MES) di kedua mode:
        config(['kpi.visible_units' => null]);
        config(['kpi.read_model' => 'legacy']);
        $this->assertSame(46, $this->reader('datasetStatus', [$user])['latest_rows']);

        config(['kpi.read_model' => 'structured']);
        $this->assertSame(35, $this->reader('datasetStatus', [$user])['latest_rows']);
    }

    /**
     * Siapkan fixture deterministik KPI untuk pengujian HTTP R5 pada divisi
     * Perencanaan Pengusahaan:
     * - 1 dataset READY, 1 ARCHIVED, 1 FAILED
     * - 41 baris performance_metrics untuk TJBT + 5 baris untuk MES
     * - 30 baris performance_observations untuk TJBT + 5 observasi untuk MES
     *
     * @return array{0: Division, 1: User}
     */
    private function seedR5HttpKpiFixture(): array
    {
        $division = Division::factory()->create([
            'name' => 'Perencanaan Pengusahaan',
            'slug' => 'perencanaan-pengusahaan',
        ]);

        $dataset = Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_READY,
        ]);
        Dataset::factory()->archived()->create([
            'division_id' => $division->id,
        ]);
        Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_FAILED,
        ]);

        $unit = OrgUnit::factory()->create(['name' => 'TJBT']);
        $otherUnit = OrgUnit::factory()->create(['name' => 'MES']);

        for ($i = 1; $i <= 16; $i++) {
            $this->legacyMetric($dataset, $division, "TJBT | Indikator {$i} | target", 100.0, '2026-08-31');
            $this->legacyMetric($dataset, $division, "TJBT | Indikator {$i} | realisasi", 100.0, '2026-08-31');
        }
        for ($g = 1; $g <= 7; $g++) {
            $this->legacyMetric($dataset, $division, "TJBT | Group {$g}", 100.0, '2026-08-31');
        }
        $this->legacyMetric($dataset, $division, 'TJBT | Standalone Indikator', 100.0, '2026-08-31');
        $this->legacyMetric($dataset, $division, 'TJBT | NKO', 105.5, '2026-08-31');

        for ($m = 1; $m <= 5; $m++) {
            $this->legacyMetric($dataset, $division, "MES | Indikator {$m} | target", 100.0, '2026-08-31');
        }

        for ($i = 1; $i <= 16; $i++) {
            $ind = Indicator::factory()->create(['org_unit_id' => $unit->id, 'name' => "Indikator {$i}", 'is_group' => false]);
            PerformanceObservation::factory()->create([
                'dataset_id' => $dataset->id,
                'indicator_id' => $ind->id,
                'org_unit_id' => $unit->id,
                'division_id' => $division->id,
                'period' => '2026-08-01',
                'target_value' => 100.0,
                'realisasi_value' => 100.0,
                'pencapaian' => 1.0,
            ]);
        }
        for ($g = 1; $g <= 7; $g++) {
            $grp = Indicator::factory()->create(['org_unit_id' => $unit->id, 'name' => "Group {$g}", 'is_group' => true]);
            PerformanceObservation::factory()->create([
                'dataset_id' => $dataset->id,
                'indicator_id' => $grp->id,
                'org_unit_id' => $unit->id,
                'division_id' => $division->id,
                'period' => '2026-08-01',
                'target_value' => null,
                'realisasi_value' => null,
                'pencapaian' => 1.0,
            ]);
        }
        for ($o = 1; $o <= 7; $o++) {
            $oth = Indicator::factory()->create(['org_unit_id' => $unit->id, 'name' => "Other {$o}", 'is_group' => false]);
            PerformanceObservation::factory()->create([
                'dataset_id' => $dataset->id,
                'indicator_id' => $oth->id,
                'org_unit_id' => $unit->id,
                'division_id' => $division->id,
                'period' => '2026-08-01',
                'target_value' => null,
                'realisasi_value' => null,
                'pencapaian' => null,
            ]);
        }

        for ($m = 1; $m <= 5; $m++) {
            $mesInd = Indicator::factory()->create(['org_unit_id' => $otherUnit->id, 'name' => "MES Indikator {$m}", 'is_group' => false]);
            PerformanceObservation::factory()->create([
                'dataset_id' => $dataset->id,
                'indicator_id' => $mesInd->id,
                'org_unit_id' => $otherUnit->id,
                'division_id' => $division->id,
                'period' => '2026-08-01',
                'target_value' => 100.0,
                'realisasi_value' => 100.0,
                'pencapaian' => 1.0,
            ]);
        }

        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $division->id,
        ]);

        return [$division, $staff];
    }

    public function test_r5_http_kasus_a_staff_divisi_kpi_legacy_merender_status_dataset_dan_baris_legacy(): void
    {
        [, $staff] = $this->seedR5HttpKpiFixture();

        config([
            'kpi.read_model' => 'legacy',
            'kpi.visible_units' => ['TJBT'],
        ]);

        $response = $this->actingAs($staff)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('Status Dataset')
            ->assertSee('Dataset READY (Divisi)')
            ->assertSee('Dataset ARCHIVED')
            ->assertSee('Dataset GAGAL')
            ->assertSee('Baris Data Aktif (TJBT)')
            ->assertSee('Periode data terbaru: 2026-08-31')
            ->assertSee('Sudah Lama')
            ->assertDontSee('Belum ada data KPI aktif.')
            ->assertSeeInOrder([
                'Status Dataset',
                'Dataset READY (Divisi)',
                '1',
                'Dataset ARCHIVED',
                '1',
                'Dataset GAGAL',
                '1',
                'Baris Data Aktif (TJBT)',
                '41',
                'Periode data terbaru: 2026-08-31',
            ]);

        $widgets = collect($response->viewData('widgets'))->keyBy('key');
        $this->assertTrue($widgets->has('dataset-status'));

        $data = $widgets->get('dataset-status')['data'];
        $this->assertSame('2026-08-31', $data['latest_period']);
        $this->assertSame(1, $data['ready']);
        $this->assertSame(1, $data['archived']);
        $this->assertSame(1, $data['failed']);
        $this->assertSame(41, $data['latest_rows']);
        $this->assertFalse($data['fresh']);
    }

    public function test_r5_http_kasus_b_staff_divisi_kpi_structured_paritas_metadata_dan_baris_observasi(): void
    {
        [, $staff] = $this->seedR5HttpKpiFixture();

        config([
            'kpi.read_model' => 'legacy',
            'kpi.visible_units' => ['TJBT'],
        ]);
        $legacyResponse = $this->actingAs($staff)->get(route('dashboard'))->assertOk();
        $legacyData = collect($legacyResponse->viewData('widgets'))->keyBy('key')->get('dataset-status')['data'];

        config([
            'kpi.read_model' => 'structured',
            'kpi.visible_units' => ['TJBT'],
        ]);
        $structuredResponse = $this->actingAs($staff)->get(route('dashboard'));

        $structuredResponse->assertOk()
            ->assertSee('Status Dataset')
            ->assertSee('Dataset READY (Divisi)')
            ->assertSee('Dataset ARCHIVED')
            ->assertSee('Dataset GAGAL')
            ->assertSee('Baris Data Aktif (TJBT)')
            ->assertSee('Periode data terbaru: 2026-08-31')
            ->assertSee('Sudah Lama')
            ->assertDontSee('Belum ada data KPI aktif.')
            ->assertSeeInOrder([
                'Status Dataset',
                'Dataset READY (Divisi)',
                '1',
                'Dataset ARCHIVED',
                '1',
                'Dataset GAGAL',
                '1',
                'Baris Data Aktif (TJBT)',
                '30',
                'Periode data terbaru: 2026-08-31',
            ]);

        $structuredData = collect($structuredResponse->viewData('widgets'))->keyBy('key')->get('dataset-status')['data'];

        $this->assertSame($legacyData['latest_period'], $structuredData['latest_period']);
        $this->assertSame($legacyData['ready'], $structuredData['ready']);
        $this->assertSame($legacyData['archived'], $structuredData['archived']);
        $this->assertSame($legacyData['failed'], $structuredData['failed']);
        $this->assertSame($legacyData['fresh'], $structuredData['fresh']);

        $this->assertSame(41, $legacyData['latest_rows']);
        $this->assertSame(30, $structuredData['latest_rows']);
    }

    public function test_r5_http_kasus_c_staff_divisi_non_kpi_structured_fallback_periode_legacy(): void
    {
        $division = Division::factory()->create([
            'name' => 'Manajemen Aset',
            'slug' => 'manajemen-aset',
        ]);
        $dataset = Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_READY,
        ]);
        Dataset::factory()->archived()->create([
            'division_id' => $division->id,
        ]);

        $this->legacyMetric($dataset, $division, 'Total Aset Trafo', 437.0, '2026-08-15');

        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $division->id,
        ]);

        config([
            'kpi.read_model' => 'structured',
            'kpi.visible_units' => ['TJBT'],
        ]);

        $response = $this->actingAs($staff)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('Status Dataset')
            ->assertSee('Dataset READY (Divisi)')
            ->assertSee('Dataset ARCHIVED')
            ->assertSee('Dataset GAGAL')
            ->assertSee('Baris Data Aktif (TJBT)')
            ->assertSee('Periode data terbaru: 2026-08-31')
            ->assertSee('Sudah Lama')
            ->assertDontSee('Belum ada data KPI aktif.')
            ->assertSeeInOrder([
                'Status Dataset',
                'Dataset READY (Divisi)',
                '1',
                'Dataset ARCHIVED',
                '1',
                'Dataset GAGAL',
                '0',
                'Baris Data Aktif (TJBT)',
                '0',
                'Periode data terbaru: 2026-08-31',
            ]);

        $widgets = collect($response->viewData('widgets'));
        $this->assertSame(['dataset-status'], $widgets->pluck('key')->all());

        $data = $widgets->first()['data'];
        $this->assertSame('2026-08-31', $data['latest_period']);
        $this->assertSame(1, $data['ready']);
        $this->assertSame(1, $data['archived']);
        $this->assertSame(0, $data['failed']);
        $this->assertSame(0, $data['latest_rows']);
        $this->assertFalse($data['fresh']);
    }

    public function test_r5_http_kasus_d_staff_tanpa_divisi_structured_fail_closed(): void
    {
        $division = Division::factory()->create([
            'name' => 'Perencanaan Pengusahaan',
            'slug' => 'perencanaan-pengusahaan',
        ]);
        $dataset = Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_READY,
        ]);
        $unit = OrgUnit::factory()->create(['name' => 'TJBT']);

        $this->leaf($dataset, $unit, $division, ['name' => 'Indikator Divisi', 'is_group' => false], [
            'period' => '2026-08-31',
            'target_value' => 100.0,
            'realisasi_value' => 100.0,
            'pencapaian' => 1.0,
        ]);
        $this->legacyMetric($dataset, $division, 'TJBT | Indikator Divisi | target', 100.0, '2026-08-31');

        // Data yatim (division_id = null) pada observasi dan metrik tidak boleh bocor
        $this->leaf($dataset, $unit, null, ['name' => 'Indikator Yatim', 'is_group' => false], [
            'period' => '2026-08-31',
            'target_value' => 75.0,
            'realisasi_value' => 75.0,
            'pencapaian' => 1.0,
        ]);
        $this->legacyMetric($dataset, null, 'TJBT | Indikator Yatim | target', 75.0, '2026-08-31');

        $staffNoDiv = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => null,
        ]);

        config([
            'kpi.read_model' => 'structured',
            'kpi.visible_units' => ['TJBT'],
        ]);

        $response = $this->actingAs($staffNoDiv)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('Status Dataset')
            ->assertSee('Belum ada data KPI aktif.')
            ->assertDontSee('Dataset READY (Divisi)')
            ->assertDontSee('Periode data terbaru:')
            ->assertDontSee('2026-08-31');

        $widgets = collect($response->viewData('widgets'));
        $this->assertSame(['dataset-status'], $widgets->pluck('key')->all());

        $data = $widgets->first()['data'];
        $this->assertNull($data['latest_period']);
        $this->assertSame(0, $data['ready']);
        $this->assertSame(0, $data['archived']);
        $this->assertSame(0, $data['failed']);
        $this->assertSame(0, $data['latest_rows']);
        $this->assertFalse($data['fresh']);
    }

    // ---------------------------------------------------------------- R7 ----

    public function test_r7_structured_kosong_tanpa_data_terstruktur_dan_mengabaikan_performance_metrics(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);
        $this->legacyMetric($dataset, $division, 'TJBT | Indikator A | target', 200.0, '2026-08-31');
        $this->legacyMetric($dataset, $division, 'TJBT | Indikator A | realisasi', 100.0, '2026-08-31');

        $controller = app(DashboardController::class);
        $reflection = new ReflectionMethod($controller, 'kinerjaDetail');
        $reflection->setAccessible(true);
        $result = $reflection->invoke($controller, $this->management());

        $unitsProp = new \ReflectionProperty($controller, 'kinerjaDetailUnits');
        $unitsProp->setAccessible(true);

        $this->assertSame(['period' => null, 'rows' => [], 'truncated' => false, 'total_rows' => 0], $result);
        $this->assertSame([], $unitsProp->getValue($controller));
    }

    public function test_r7_structured_membaca_dataset_ready_terbaru_dan_periode_terstruktur(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Pengusahaan');
        $unit = OrgUnit::factory()->create(['name' => 'TJBT']);

        $older = Dataset::factory()->create([
            'division_id' => $division->id,
            'created_at' => now()->subDays(2),
        ]);
        $newer = Dataset::factory()->create([
            'division_id' => $division->id,
            'created_at' => now()->subDay(),
        ]);
        $archived = Dataset::factory()->archived()->create([
            'division_id' => $division->id,
            'created_at' => now(),
        ]);

        $this->legacyMetric($newer, $division, 'TJBT | Indikator Legacy | target', 999.0, '2026-08-01');
        $this->legacyMetric($newer, $division, 'TJBT | Indikator Legacy | realisasi', 999.0, '2026-08-01');

        $indA = Indicator::factory()->create(['org_unit_id' => $unit->id, 'name' => 'Indikator A', 'is_group' => false]);
        PerformanceObservation::factory()->create([
            'dataset_id' => $older->id,
            'indicator_id' => $indA->id,
            'org_unit_id' => $unit->id,
            'division_id' => $division->id,
            'period' => '2026-08-01',
            'target_value' => 200.0,
            'realisasi_value' => 50.0,
        ]);
        PerformanceObservation::factory()->create([
            'dataset_id' => $newer->id,
            'indicator_id' => $indA->id,
            'org_unit_id' => $unit->id,
            'division_id' => $division->id,
            'period' => '2026-08-01',
            'target_value' => 200.0,
            'realisasi_value' => 150.0,
        ]);
        $this->leaf($archived, $unit, $division, ['name' => 'Indikator Archived'], [
            'period' => '2026-09-01',
            'target_value' => 100.0,
            'realisasi_value' => 100.0,
        ]);

        $result = $this->reader('kinerjaDetail', [$this->management()]);

        $this->assertSame('2026-08-01', $result['period']);
        $this->assertSame(1, $result['total_rows']);
        $this->assertSame('Indikator A', $result['rows'][0]['indikator']);
        $this->assertSame(200.0, $result['rows'][0]['target']);
        $this->assertSame(150.0, $result['rows'][0]['realisasi']);
        $this->assertSame(75.0, $result['rows'][0]['pencapaian']);
    }

    private function observeNko(Dataset $dataset, ?Division $division, string $unitName, float $value, string $period = '2026-08-31'): PerformanceObservation
    {
        $orgUnit = OrgUnit::firstOrCreate(['name' => $unitName]);
        $indicator = Indicator::firstOrCreate(
            ['org_unit_id' => $orgUnit->id, 'name' => 'NKO'],
            ['category' => 'nko', 'bobot' => null],
        );

        return PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indicator->id,
            'org_unit_id' => $orgUnit->id,
            'division_id' => $division?->id,
            'period' => $period,
            'nilai' => $value,
        ]);
    }

    public function test_r3_structured_fail_closed_untuk_guest_dan_user_non_privileged_tanpa_divisi(): void
    {
        $divA = $this->division('Divisi A');
        $divB = $this->division('Divisi B');
        $dsA = Dataset::factory()->create(['division_id' => $divA->id]);
        $dsB = Dataset::factory()->create(['division_id' => $divB->id]);

        $this->observeNko($dsA, $divA, 'TJBT', 105.5);
        $this->observeNko($dsB, $divB, 'RST', 88.0);

        $this->legacyMetric($dsA, $divA, 'TJBT | NKO', 105.5, '2026-08-31');
        $this->legacyMetric($dsB, $divB, 'RST | NKO', 88.0, '2026-08-31');

        $staffNoDiv = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => null]);
        $management = $this->management();
        $staffDivA = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $divA->id]);

        // Verifikasi cabang legacy: guest dan user non-privileged tanpa divisi gagal tertutup (kosong)
        config(['kpi.read_model' => 'legacy']);
        $this->assertSame([], $this->reader('nkoRanking', [null])->all());
        $this->assertSame([], $this->reader('nkoRanking', [$staffNoDiv])->all());
        $this->assertCount(2, $this->reader('nkoRanking', [$management]));
        $this->assertSame(['TJBT'], $this->reader('nkoRanking', [$staffDivA])->pluck('unit')->all());

        // Verifikasi cabang structured: wajib identik gagal tertutup (kosong)
        config(['kpi.read_model' => 'structured']);
        $this->assertSame([], $this->reader('nkoRanking', [null])->all());
        $this->assertSame([], $this->reader('nkoRanking', [$staffNoDiv])->all());
        $this->assertCount(2, $this->reader('nkoRanking', [$management]));
        $this->assertSame(['TJBT'], $this->reader('nkoRanking', [$staffDivA])->pluck('unit')->all());
    }

    public function test_r10_structured_fail_closed_untuk_guest_dan_user_non_privileged_tanpa_divisi(): void
    {
        $divA = $this->division('Divisi A');
        $divB = $this->division('Divisi B');
        $dsA = Dataset::factory()->create(['division_id' => $divA->id]);
        $dsB = Dataset::factory()->create(['division_id' => $divB->id]);

        $this->observeNko($dsA, $divA, 'TJBT', 105.5);
        $this->observeNko($dsB, $divB, 'RST', 88.0);

        $this->legacyMetric($dsA, $divA, 'TJBT | NKO', 105.5, '2026-08-31');
        $this->legacyMetric($dsB, $divB, 'RST | NKO', 88.0, '2026-08-31');

        $staffNoDiv = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => null]);
        $management = $this->management();
        $staffDivA = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $divA->id]);

        // Verifikasi cabang legacy
        config(['kpi.read_model' => 'legacy']);
        $this->assertSame(['period' => null, 'data' => []], $this->reader('nkoDonut', [null]));
        $this->assertSame(['period' => null, 'data' => []], $this->reader('nkoDonut', [$staffNoDiv]));
        $legacyMgmt = $this->reader('nkoDonut', [$management]);
        $this->assertSame('2026-08-31', $legacyMgmt['period']);
        $this->assertSame(2, array_sum(array_column($legacyMgmt['data'], 'value')));

        // Verifikasi cabang structured
        config(['kpi.read_model' => 'structured']);
        $this->assertSame(['period' => null, 'data' => []], $this->reader('nkoDonut', [null]));
        $this->assertSame(['period' => null, 'data' => []], $this->reader('nkoDonut', [$staffNoDiv]));
        $structMgmt = $this->reader('nkoDonut', [$management]);
        $this->assertSame('2026-08-31', $structMgmt['period']);
        $this->assertSame(2, array_sum(array_column($structMgmt['data'], 'value')));
        $structStaffA = $this->reader('nkoDonut', [$staffDivA]);
        $this->assertSame('2026-08-31', $structStaffA['period']);
        $this->assertSame([['name' => 'Tercapai', 'value' => 1]], $structStaffA['data']);
    }

    public function test_structured_readers_lainnya_fail_closed_untuk_user_non_privileged_tanpa_divisi(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);
        $unit = OrgUnit::factory()->create(['name' => 'TJBT']);

        // Data berdivisi (Pengusahaan)
        $this->leaf($dataset, $unit, $division, ['name' => 'Indikator A', 'is_group' => false], [
            'period' => '2026-08-31',
            'target_value' => 100.0,
            'realisasi_value' => 100.0,
            'pencapaian' => 1.0,
        ]);
        $this->legacyMetric($dataset, $division, 'TJBT | Indikator A | target', 100.0, '2026-08-31');
        $this->legacyMetric($dataset, $division, 'TJBT | Indikator A | realisasi', 100.0, '2026-08-31');

        // Data yatim (division_id = null)
        $this->leaf($dataset, $unit, null, ['name' => 'Indikator Yatim', 'is_group' => false], [
            'period' => '2026-08-31',
            'target_value' => 75.0,
            'realisasi_value' => 75.0,
            'pencapaian' => 1.0,
        ]);
        $this->observeNko($dataset, null, 'TJBT', 88.0, '2026-08-31');
        $this->legacyMetric($dataset, null, 'TJBT | Indikator Yatim | target', 75.0, '2026-08-31');
        $this->legacyMetric($dataset, null, 'TJBT | Indikator Yatim | realisasi', 75.0, '2026-08-31');
        $this->legacyMetric($dataset, null, 'TJBT | NKO', 88.0, '2026-08-31');

        $staffNoDiv = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => null]);

        config(['kpi.read_model' => 'structured']);

        // R4 (latestReadyPeriod & latestStructuredReadyPeriod)
        $this->assertNull($this->reader('latestReadyPeriod', [null]));
        $this->assertNull($this->reader('latestReadyPeriod', [$staffNoDiv]));
        $this->assertNull($this->reader('latestStructuredReadyPeriod', [$staffNoDiv]));

        // R2 (availableUnits)
        $this->assertSame([], $this->reader('availableUnits', [null]));
        $this->assertSame([], $this->reader('availableUnits', [$staffNoDiv]));

        // Panel period & reader
        $this->assertNull($this->reader('latestStructuredPanelPeriod', [null]));
        $this->assertNull($this->reader('latestStructuredPanelPeriod', [$staffNoDiv]));
        $this->assertSame(['period' => null, 'units' => [], 'sections' => []], $this->reader('targetRealisasiPerIndikator', [$staffNoDiv]));

        // R6 (kinerjaRealisasi)
        $this->assertSame(['period' => null, 'rows' => [], 'has_data' => false], $this->reader('kinerjaRealisasi', [null]));
        $this->assertSame(['period' => null, 'rows' => [], 'has_data' => false], $this->reader('kinerjaRealisasi', [$staffNoDiv]));

        // R7 (kinerjaDetail)
        $this->assertSame(['period' => null, 'rows' => [], 'truncated' => false, 'total_rows' => 0], $this->reader('kinerjaDetail', [null]));
        $this->assertSame(['period' => null, 'rows' => [], 'truncated' => false, 'total_rows' => 0], $this->reader('kinerjaDetail', [$staffNoDiv]));

        // R9 (pencapaianNko)
        $this->assertSame(['period' => null, 'labels' => [], 'data' => []], $this->reader('pencapaianNko', [null]));
        $this->assertSame(['period' => null, 'labels' => [], 'data' => []], $this->reader('pencapaianNko', [$staffNoDiv]));

        // R5 (datasetStatus)
        $statusNull = $this->reader('datasetStatus', [null]);
        $this->assertNull($statusNull['latest_period']);
        $this->assertSame(0, $statusNull['latest_rows']);
        $this->assertSame(0, $statusNull['ready']);

        $statusStaffNoDiv = $this->reader('datasetStatus', [$staffNoDiv]);
        $this->assertNull($statusStaffNoDiv['latest_period']);
        $this->assertSame(0, $statusStaffNoDiv['latest_rows']);
        $this->assertSame(0, $statusStaffNoDiv['ready']);
    }

    public function test_karakterisasi_legacy_membocorkan_baris_yatim_ke_user_tanpa_divisi(): void
    {
        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);
        $unit = OrgUnit::factory()->create(['name' => 'TJBT']);

        // Data berdivisi (Pengusahaan)
        $this->leaf($dataset, $unit, $division, ['name' => 'Indikator Normal', 'is_group' => false], [
            'period' => '2026-08-31',
            'target_value' => 100.0,
            'realisasi_value' => 100.0,
            'pencapaian' => 1.0,
        ]);
        $this->observeNko($dataset, $division, 'TJBT', 105.5, '2026-08-31');
        $this->legacyMetric($dataset, $division, 'TJBT | Indikator Normal | target', 100.0, '2026-08-31');
        $this->legacyMetric($dataset, $division, 'TJBT | Indikator Normal | realisasi', 100.0, '2026-08-31');
        $this->legacyMetric($dataset, $division, 'TJBT | NKO', 105.5, '2026-08-31');

        // Data yatim: dataset terdaftar pada divisi lain, tetapi observasi & metrik division_id = null
        $divOrphan = $this->division('Divisi Yatim');
        $orphanDataset = Dataset::factory()->create(['division_id' => $divOrphan->id, 'status' => Dataset::STATUS_READY]);
        $this->leaf($orphanDataset, $unit, null, ['name' => 'Indikator Yatim', 'is_group' => false], [
            'period' => '2026-08-31',
            'target_value' => 75.0,
            'realisasi_value' => 75.0,
            'pencapaian' => 1.0,
        ]);
        $this->observeNko($orphanDataset, null, 'TJBT', 88.0, '2026-08-31');
        $this->legacyMetric($orphanDataset, null, 'TJBT | Indikator Yatim | target', 75.0, '2026-08-31');
        $this->legacyMetric($orphanDataset, null, 'TJBT | Indikator Yatim | realisasi', 75.0, '2026-08-31');
        $this->legacyMetric($orphanDataset, null, 'TJBT | NKO', 88.0, '2026-08-31');

        $staffNoDiv = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => null]);

        // Karakterisasi: legacy memakai WHERE division_id IS NULL sehingga membocorkan data yatim ke user tanpa divisi
        config(['kpi.read_model' => 'legacy']);

        $this->assertSame(['TJBT'], $this->reader('availableUnits', [$staffNoDiv]));
        $this->assertSame('2026-08-31', $this->reader('latestReadyPeriod', [$staffNoDiv]));

        $nkoRank = $this->reader('nkoRanking', [$staffNoDiv]);
        $this->assertCount(1, $nkoRank);
        $this->assertSame(88.0, (float) $nkoRank[0]->nko);

        $donut = $this->reader('nkoDonut', [$staffNoDiv]);
        $this->assertSame('2026-08-31', $donut['period']);
        $this->assertSame([['name' => 'Perlu Peningkatan', 'value' => 1]], $donut['data']);

        $realisasi = $this->reader('kinerjaRealisasi', [$staffNoDiv]);
        $this->assertSame('2026-08-31', $realisasi['period']);
        $this->assertTrue($realisasi['has_data']);

        $detail = $this->reader('kinerjaDetail', [$staffNoDiv]);
        $this->assertSame('2026-08-31', $detail['period']);
        $this->assertSame(1, $detail['total_rows']);
        $this->assertSame('Indikator Yatim', $detail['rows'][0]['indikator']);

        $status = $this->reader('datasetStatus', [$staffNoDiv]);
        $this->assertSame('2026-08-31', $status['latest_period']);
        $this->assertSame(3, $status['latest_rows']);
    }

    public function test_target_realisasi_per_indikator_fail_closed_untuk_user_tanpa_divisi(): void
    {
        $division = $this->division('Pengusahaan');
        $dsA = Dataset::factory()->create(['division_id' => $division->id, 'status' => Dataset::STATUS_READY]);
        $unit = OrgUnit::factory()->create(['name' => 'TJBT']);

        // Data berdivisi
        $this->leaf($dsA, $unit, $division, ['name' => 'Indikator Divisi', 'is_group' => false], [
            'period' => '2026-08-31',
            'target_value' => 100.0,
            'realisasi_value' => 100.0,
            'pencapaian' => 1.0,
        ]);

        // Data yatim pada dataset terpisah
        $divOrphan = $this->division('Divisi Yatim');
        $dsOrphan = Dataset::factory()->create(['division_id' => $divOrphan->id, 'status' => Dataset::STATUS_READY]);
        $this->leaf($dsOrphan, $unit, null, ['name' => 'Indikator Yatim', 'is_group' => false], [
            'period' => '2026-08-31',
            'target_value' => 50.0,
            'realisasi_value' => 50.0,
            'pencapaian' => 1.0,
        ]);

        $staffNoDiv = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => null]);
        $staffDivA = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);
        $management = $this->management();

        // 1. User non-privileged tanpa divisi mendapat panel kosong
        $panelNoDiv = $this->reader('targetRealisasiPerIndikator', [$staffNoDiv]);
        $this->assertSame(['period' => null, 'units' => [], 'sections' => []], $panelNoDiv);

        // 2. Staf Divisi A mendapat panel divisinya dan tidak bocor data yatim
        $panelDivA = $this->reader('targetRealisasiPerIndikator', [$staffDivA]);
        $this->assertSame('2026-08-31', $panelDivA['period']);
        $this->assertSame(['TJBT'], $panelDivA['units']);
        $indicatorNames = [];
        foreach ($panelDivA['sections'] as $sec) {
            foreach ($sec['panels'] as $p) {
                $indicatorNames[] = $p['name'];
            }
        }
        $this->assertContains('Indikator Divisi', $indicatorNames);
        $this->assertNotContains('Indikator Yatim', $indicatorNames);

        // 3. Privileged membaca data lintas divisi
        $panelMgmt = $this->reader('targetRealisasiPerIndikator', [$management]);
        $this->assertSame('2026-08-31', $panelMgmt['period']);
        $this->assertNotEmpty($panelMgmt['sections']);
    }

    // ----------------------------------- Fase 4.3 HTTP & Parity Harness ----

    /**
     * Pengujian HTTP structured untuk R2 (availableUnits):
     * - Staf divisi KPI dan role privileged (Management) me-render dropdown
     *   filter unit dengan daftar unit dari org_units sesuai visible_units.
     * - Tanpa data structured (atau saat fail-closed), dropdown menampilkan
     *   pesan "Belum ada data unit.".
     */
    public function test_r2_http_structured_merender_dropdown_available_units_dan_empty_state(): void
    {
        [, $staff] = $this->seedR5HttpKpiFixture();
        $management = $this->management();

        config([
            'kpi.read_model' => 'legacy',
            'kpi.visible_units' => ['TJBT'],
        ]);
        $legacyStaffResponse = $this->actingAs($staff)->get(route('dashboard'))->assertOk();
        $legacyMgmtResponse = $this->actingAs($management)->get(route('dashboard'))->assertOk();

        config([
            'kpi.read_model' => 'structured',
            'kpi.visible_units' => ['TJBT'],
        ]);
        $structuredStaffResponse = $this->actingAs($staff)->get(route('dashboard'));
        $structuredMgmtResponse = $this->actingAs($management)->get(route('dashboard'));

        $structuredStaffResponse->assertOk()
            ->assertViewHas('availableUnits', ['TJBT'])
            ->assertSee('name="unit[]" value="TJBT"', false)
            ->assertDontSee('name="unit[]" value="MES"', false)
            ->assertDontSee('Belum ada unit.');

        $structuredMgmtResponse->assertOk()
            ->assertViewHas('availableUnits', ['TJBT'])
            ->assertSee('name="unit[]" value="TJBT"', false)
            ->assertDontSee('name="unit[]" value="MES"', false)
            ->assertDontSee('Belum ada unit.');

        $this->assertSame(
            $legacyStaffResponse->viewData('availableUnits'),
            $structuredStaffResponse->viewData('availableUnits'),
        );
        $this->assertSame(
            $legacyMgmtResponse->viewData('availableUnits'),
            $structuredMgmtResponse->viewData('availableUnits'),
        );

        // Rollback visible_units = null pada HTTP structured mengembalikan semua unit terstruktur
        config(['kpi.visible_units' => null]);
        $unscopedResponse = $this->actingAs($management)->get(route('dashboard'))->assertOk();
        $this->assertSame(['MES', 'TJBT'], $unscopedResponse->viewData('availableUnits'));

        // Empty state R2 structured saat tabel performance_observations kosong
        PerformanceObservation::query()->delete();
        config(['kpi.visible_units' => ['TJBT']]);
        $emptyResponse = $this->actingAs($staff)->get(route('dashboard'));
        $emptyResponse->assertOk()
            ->assertViewHas('availableUnits', [])
            ->assertSee('Belum ada unit.');
    }

    /**
     * Pengujian HTTP structured untuk R6 (kinerjaRealisasi) dan R9 (pencapaianNko)
     * pada layout staf divisi Perencanaan Pengusahaan, mencakup:
     * - Paritas penuh payload dan HTML antara mode legacy dan structured.
     * - Penanganan empty state ketika data structured tidak tersedia.
     */
    public function test_r6_dan_r9_http_structured_paritas_render_dan_empty_state(): void
    {
        [, $staff] = $this->seedR5HttpKpiFixture();

        config([
            'kpi.read_model' => 'legacy',
            'kpi.visible_units' => ['TJBT'],
        ]);
        $legacyResponse = $this->actingAs($staff)->get(route('dashboard'))->assertOk();
        $legacyWidgets = collect($legacyResponse->viewData('widgets'))->keyBy('key');
        $legacyR6 = $legacyWidgets->get('kinerja-realisasi')['data'];
        $legacyR9 = $legacyWidgets->get('pencapaian-nko')['data'];

        config([
            'kpi.read_model' => 'structured',
            'kpi.visible_units' => ['TJBT'],
        ]);
        $structuredResponse = $this->actingAs($staff)->get(route('dashboard'));

        $structuredResponse->assertOk()
            ->assertSee('Realisasi vs Target')
            ->assertSee('16 dari 16')
            ->assertSee('100,0%')
            ->assertSee('Tercapai')
            ->assertDontSee('Belum ada data realisasi vs target pada periode ini.')
            ->assertSee('Pencapaian % per Unit')
            ->assertSee('pencapaianNko-pencapaian-nko-', false)
            ->assertDontSee('Belum ada data pencapaian pada periode ini.');

        $structuredWidgets = collect($structuredResponse->viewData('widgets'))->keyBy('key');
        $this->assertTrue($structuredWidgets->has('kinerja-realisasi'));
        $this->assertTrue($structuredWidgets->has('pencapaian-nko'));

        $structuredR6 = $structuredWidgets->get('kinerja-realisasi')['data'];
        $structuredR9 = $structuredWidgets->get('pencapaian-nko')['data'];

        // Periode di fixture R5: legacy metrics = 2026-08-31, structured observations = 2026-08-01.
        // Baris dan nilai rata-rata R6 & R9 wajib identik 100%.
        $this->assertSame($legacyR6['rows'], $structuredR6['rows']);
        $this->assertSame($legacyR6['has_data'], $structuredR6['has_data']);
        $this->assertSame($legacyR9['labels'], $structuredR9['labels']);
        $this->assertSame($legacyR9['data'], $structuredR9['data']);

        // Empty state HTTP structured saat performance_observations kosong (tanpa menghapus legacy metrics)
        PerformanceObservation::query()->delete();

        $emptyResponse = $this->actingAs($staff)->get(route('dashboard'));
        $emptyResponse->assertOk()
            ->assertSee('Belum ada data realisasi vs target pada periode ini.')
            ->assertSee('Belum ada data pencapaian pada periode ini.');

        $emptyWidgets = collect($emptyResponse->viewData('widgets'))->keyBy('key');
        $this->assertFalse($emptyWidgets->get('kinerja-realisasi')['data']['has_data']);
        $this->assertSame([], $emptyWidgets->get('kinerja-realisasi')['data']['rows']);
        $this->assertSame([], $emptyWidgets->get('pencapaian-nko')['data']['labels']);
        $this->assertSame([], $emptyWidgets->get('pencapaian-nko')['data']['data']);
    }

    /**
     * Pengujian HTTP structured untuk R5 pada role selain staf divisi KPI utama:
     * 1. Staf divisi generik (slug divisi tidak dipetakan khusus di config)
     *    jatuh ke layout `staff_divisi` yang hanya me-mount `dataset-status`.
     * 2. Role privileged (Admin, Management, Editor Dashboard) terbukti TIDAK
     *    me-mount `dataset-status` pada layout bawaan produksi, tetapi apabila
     *    widget `dataset-status` di-mount pada layout mereka, jalur HTTP
     *    structured mengeksekusi scoping lintas divisi dengan paritas penuh.
     */
    public function test_r5_http_structured_jalur_role_staf_generik_dan_privileged(): void
    {
        [$divKpi] = $this->seedR5HttpKpiFixture();

        $divOther = Division::factory()->create([
            'name' => 'Operasi Sistem',
            'slug' => 'operasi-sistem',
        ]);
        $dsOther = Dataset::factory()->create([
            'division_id' => $divOther->id,
            'status' => Dataset::STATUS_READY,
        ]);
        $this->legacyMetric($dsOther, $divOther, 'Operasi Metric', 50.0, '2026-07-15');

        $staffGeneric = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $divOther->id,
        ]);
        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $management = $this->management();
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();

        config([
            'kpi.read_model' => 'structured',
            'kpi.visible_units' => ['TJBT'],
        ]);

        // 1. Staf divisi generik me-mount tepat ['dataset-status'] dan terisolasi ke divisinya
        $genericResponse = $this->actingAs($staffGeneric)->get(route('dashboard'))->assertOk();
        $genericWidgets = collect($genericResponse->viewData('widgets'))->keyBy('key');
        $this->assertSame(['dataset-status'], $genericWidgets->keys()->all());
        $this->assertSame('2026-07-31', $genericWidgets->get('dataset-status')['data']['latest_period']);
        $this->assertSame(1, $genericWidgets->get('dataset-status')['data']['ready']);
        $this->assertSame(0, $genericWidgets->get('dataset-status')['data']['archived']);
        $this->assertSame(0, $genericWidgets->get('dataset-status')['data']['failed']);
        $this->assertSame(0, $genericWidgets->get('dataset-status')['data']['latest_rows']);

        // 2. Layout bawaan role privileged tidak me-mount dataset-status
        foreach ([$admin, $management, $editor] as $privilegedUser) {
            $defaultResp = $this->actingAs($privilegedUser)->get(route('dashboard'))->assertOk();
            $mountedKeys = collect($defaultResp->viewData('widgets'))->pluck('key')->all();
            $this->assertNotContains('dataset-status', $mountedKeys);
        }

        // 3. Saat dataset-status di-mount pada layout management untuk verifikasi HTTP terkendali,
        //    role privileged mengagregasi status lintas divisi (divKpi + divOther).
        config([
            'dashboard_layouts.layouts.management' => ['kpi-summary', 'trend-chart', 'nko-ranking', 'dataset-status'],
        ]);

        config(['kpi.read_model' => 'legacy']);
        $mgmtLegacyResp = $this->actingAs($management)->get(route('dashboard'))->assertOk();
        $mgmtLegacyR5 = collect($mgmtLegacyResp->viewData('widgets'))->keyBy('key')->get('dataset-status')['data'];

        config(['kpi.read_model' => 'structured']);
        $mgmtStructResp = $this->actingAs($management)->get(route('dashboard'))->assertOk();
        $mgmtStructR5 = collect($mgmtStructResp->viewData('widgets'))->keyBy('key')->get('dataset-status')['data'];

        $this->assertSame('2026-08-31', $mgmtStructR5['latest_period']);
        $this->assertSame(2, $mgmtStructR5['ready']);
        $this->assertSame(1, $mgmtStructR5['archived']);
        $this->assertSame(1, $mgmtStructR5['failed']);
        $this->assertSame(30, $mgmtStructR5['latest_rows']);
        $this->assertSame(41, $mgmtLegacyR5['latest_rows']);
        $this->assertSame($mgmtLegacyR5['latest_period'], $mgmtStructR5['latest_period']);
        $this->assertSame($mgmtLegacyR5['ready'], $mgmtStructR5['ready']);
        $this->assertSame($mgmtLegacyR5['archived'], $mgmtStructR5['archived']);
        $this->assertSame($mgmtLegacyR5['failed'], $mgmtStructR5['failed']);
        $this->assertSame($mgmtLegacyR5['fresh'], $mgmtStructR5['fresh']);
    }

    /**
     * Harness pembanding terpadu legacy-versus-structured untuk seluruh reader
     * aktif (R1, R2, R3, R5, R6, R7, R9, R10) pada satu dataset READY yang
     * konsisten (mengikuti kontrak dual-write produksi).
     *
     * Memverifikasi dalam satu pengujian terarah:
     * - Paritas identik pada R1, R2, R3, R6, R7, R9, R10.
     * - Paritas metadata R5 + divergensi model data sah pada latest_rows.
     * - Konsistensi di jalur HTTP (/dashboard & /api/performance-data) untuk
     *   staf divisi KPI dan role privileged.
     */
    public function test_harness_paritas_terpadu_seluruh_reader_aktif_r1_r2_r3_r5_r6_r7_r9_r10(): void
    {
        $division = Division::factory()->create([
            'name' => 'Perencanaan Pengusahaan',
            'slug' => 'perencanaan-pengusahaan',
        ]);
        $dataset = Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_READY,
        ]);
        Dataset::factory()->archived()->create([
            'division_id' => $division->id,
        ]);

        $period = '2026-08-31';
        $tjbt = OrgUnit::factory()->create(['name' => 'TJBT']);
        $mes = OrgUnit::factory()->create(['name' => 'MES']);

        // Seed 2 indikator daun numerik + 1 grup induk + 1 baris NKO untuk TJBT
        $this->legacyMetric($dataset, $division, 'TJBT | Indikator 1 | target', 100.0, $period);
        $this->legacyMetric($dataset, $division, 'TJBT | Indikator 1 | realisasi', 105.0, $period);
        $this->legacyMetric($dataset, $division, 'TJBT | Indikator 2 | target', 200.0, $period);
        $this->legacyMetric($dataset, $division, 'TJBT | Indikator 2 | realisasi', 190.0, $period);
        $this->legacyMetric($dataset, $division, 'TJBT | Grup Induk', 100.0, $period);
        $this->legacyMetric($dataset, $division, 'TJBT | NKO', 102.5, $period);

        // Seed unit non-visible (MES) untuk menguji filter visible_units = ['TJBT']
        $this->legacyMetric($dataset, $division, 'MES | Indikator MES | target', 100.0, $period);
        $this->legacyMetric($dataset, $division, 'MES | Indikator MES | realisasi', 90.0, $period);
        $this->legacyMetric($dataset, $division, 'MES | NKO', 94.0, $period);

        $ind1 = Indicator::factory()->create([
            'org_unit_id' => $tjbt->id,
            'name' => 'Indikator 1',
            'satuan' => '%',
            'polarity' => 'higher_is_better',
            'bobot' => '10',
            'is_group' => false,
            'category' => null,
        ]);
        PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $ind1->id,
            'org_unit_id' => $tjbt->id,
            'division_id' => $division->id,
            'period' => $period,
            'target_value' => 100.0,
            'realisasi_value' => 105.0,
            'pencapaian' => 1.05,
        ]);

        $ind2 = Indicator::factory()->create([
            'org_unit_id' => $tjbt->id,
            'name' => 'Indikator 2',
            'satuan' => '%',
            'polarity' => 'higher_is_better',
            'bobot' => '15',
            'is_group' => false,
            'category' => null,
        ]);
        PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $ind2->id,
            'org_unit_id' => $tjbt->id,
            'division_id' => $division->id,
            'period' => $period,
            'target_value' => 200.0,
            'realisasi_value' => 190.0,
            'pencapaian' => 0.95,
        ]);

        $grp = Indicator::factory()->create([
            'org_unit_id' => $tjbt->id,
            'name' => 'Grup Induk',
            'is_group' => true,
            'category' => null,
        ]);
        PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $grp->id,
            'org_unit_id' => $tjbt->id,
            'division_id' => $division->id,
            'period' => $period,
            'target_value' => null,
            'realisasi_value' => null,
            'pencapaian' => 1.0,
        ]);

        $nkoTjbt = Indicator::factory()->create([
            'org_unit_id' => $tjbt->id,
            'name' => 'NKO',
            'is_group' => false,
            'category' => 'nko',
        ]);
        PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $nkoTjbt->id,
            'org_unit_id' => $tjbt->id,
            'division_id' => $division->id,
            'period' => $period,
            'nilai' => 102.5,
        ]);

        $indMes = Indicator::factory()->create([
            'org_unit_id' => $mes->id,
            'name' => 'Indikator MES',
            'satuan' => '%',
            'is_group' => false,
            'category' => null,
        ]);
        PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indMes->id,
            'org_unit_id' => $mes->id,
            'division_id' => $division->id,
            'period' => $period,
            'target_value' => 100.0,
            'realisasi_value' => 90.0,
            'pencapaian' => 0.90,
        ]);

        $nkoMes = Indicator::factory()->create([
            'org_unit_id' => $mes->id,
            'name' => 'NKO',
            'is_group' => false,
            'category' => 'nko',
        ]);
        PerformanceObservation::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $nkoMes->id,
            'org_unit_id' => $mes->id,
            'division_id' => $division->id,
            'period' => $period,
            'nilai' => 94.0,
        ]);

        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $division->id,
        ]);
        $management = $this->management();

        config(['kpi.visible_units' => ['TJBT']]);

        foreach ([$staff, $management] as $actor) {
            // --- Jalankan seluruh reader pada mode LEGACY ---
            config(['kpi.read_model' => 'legacy']);
            $r1Legacy = $this->actingAs($actor)->getJson('/api/performance-data')->assertOk()->json();
            $dashLegacy = $this->actingAs($actor)->get(route('dashboard'))->assertOk();
            $r2Legacy = $dashLegacy->viewData('availableUnits');
            $r3Legacy = $this->reader('nkoRanking', [$actor])->map(fn ($o) => (array) $o)->all();
            $r5Legacy = $this->reader('datasetStatus', [$actor]);
            $r6Legacy = $this->reader('kinerjaRealisasi', [$actor]);
            $r7Legacy = $this->reader('kinerjaDetail', [$actor]);
            $r9Legacy = $this->reader('pencapaianNko', [$actor]);
            $r10Legacy = $this->reader('nkoDonut', [$actor]);

            // --- Jalankan seluruh reader pada mode STRUCTURED ---
            config(['kpi.read_model' => 'structured']);
            $r1Structured = $this->actingAs($actor)->getJson('/api/performance-data')->assertOk()->json();
            $dashStructured = $this->actingAs($actor)->get(route('dashboard'))->assertOk();
            $r2Structured = $dashStructured->viewData('availableUnits');
            $r3Structured = $this->reader('nkoRanking', [$actor])->map(fn ($o) => (array) $o)->all();
            $r5Structured = $this->reader('datasetStatus', [$actor]);
            $r6Structured = $this->reader('kinerjaRealisasi', [$actor]);
            $r7Structured = $this->reader('kinerjaDetail', [$actor]);
            $r9Structured = $this->reader('pencapaianNko', [$actor]);
            $r10Structured = $this->reader('nkoDonut', [$actor]);

            // 1. Paritas R1 (/api/performance-data)
            $this->assertSame($r1Legacy, $r1Structured);

            // 2. Paritas R2 (availableUnits)
            $this->assertSame(['TJBT'], $r2Structured);
            $this->assertSame($r2Legacy, $r2Structured);

            // 3. Paritas R3 (nkoRanking)
            $this->assertSame($r3Legacy, $r3Structured);

            // 4. Paritas metadata R5 + expected divergence latest_rows (6 baris metrics vs 4 baris observations TJBT)
            $this->assertSame($r5Legacy['latest_period'], $r5Structured['latest_period']);
            $this->assertSame($r5Legacy['ready'], $r5Structured['ready']);
            $this->assertSame($r5Legacy['archived'], $r5Structured['archived']);
            $this->assertSame($r5Legacy['failed'], $r5Structured['failed']);
            $this->assertSame($r5Legacy['fresh'], $r5Structured['fresh']);
            $this->assertSame(6, $r5Legacy['latest_rows']);
            $this->assertSame(4, $r5Structured['latest_rows']);

            // 5. Paritas R6 (kinerjaRealisasi) & R9 (pencapaianNko)
            $this->assertSame($r6Legacy, $r6Structured);
            $this->assertSame($r9Legacy, $r9Structured);

            // 6. Paritas R7 (kinerjaDetail)
            $this->assertSame($r7Legacy, $r7Structured);

            // 7. Paritas R10 (nkoDonut)
            $this->assertSame($r10Legacy, $r10Structured);
        }
    }
}
