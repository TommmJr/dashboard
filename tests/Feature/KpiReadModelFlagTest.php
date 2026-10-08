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
            ->update(['target_value' => 100.0, 'realisasi_value' => 110.0]);

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
     * Target bertipe tanggal/teks/kosong tidak punya target_value, jadi tidak
     * ada pasangan target/realisasi yang bisa dihitung: unit TIDAK masuk tabel
     * (bukan baris dengan pencapaian NULL). Jalur legacy meng-cast baris target
     * apa pun ke float, jadi perilakunya berbeda.
     */
    public function test_r6_structured_target_non_numerik_tidak_dihitung_pencapaian(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Pengusahaan');
        $unit = OrgUnit::factory()->create(['name' => 'TJBT']);
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->observation($dataset, $unit, $division, '2026-08-31')
            ->update([
                'target_value' => null,
                'target_date' => '2025-08-31',
                'target_type' => PerformanceObservation::TYPE_DATE,
                'realisasi_value' => 90.0,
            ]);

        $result = $this->reader('kinerjaRealisasi', [$this->management()]);

        // Tidak ada pencapaian yang bisa dibandingkan -> unit tidak masuk tabel.
        $this->assertSame('2026-08-31', $result['period']);
        $this->assertSame([], $result['rows']);
        $this->assertFalse($result['has_data']);
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
            ->update(['target_value' => 100.0, 'realisasi_value' => 10.0]);
        $this->observation($newer, $unit, $division, '2026-08-31')
            ->update(['target_value' => 100.0, 'realisasi_value' => 80.0]);

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
            ->update(['target_value' => 100.0, 'realisasi_value' => 100.0]);
        $this->observation($otherDataset, OrgUnit::factory()->create(['name' => 'OTHER']), $other, '2026-08-31')
            ->update(['target_value' => 100.0, 'realisasi_value' => 100.0]);

        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $own->id]);

        $result = $this->reader('kinerjaRealisasi', [$staff]);

        $this->assertSame(['OWN'], array_column($result['rows'], 'unit'));
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
            ->update(['target_value' => 100.0, 'realisasi_value' => 105.0]);

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
