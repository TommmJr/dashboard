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
 * 3. Reader yang BELUM dimigrasi (R3, R5, R6, R7, R8, R9, R10) TIDAK ikut
 *    flag: dengan data legacy saja mereka harus tetap mengembalikan hasil
 *    legacy, bukan kosong. Ini alasan R5-R8 memanggil latestLegacyReadyPeriod().
 *
 * Catatan: 'structured' sengaja TIDAK aman untuk production (NKO belum punya
 * sumber terstruktur). Test di sini menguji mekanisme flag, bukan kesiapan
 * produksi mode structured.
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
     * Jaring pengaman utama: menyalakan 'structured' dengan data legacy saja
     * TIDAK boleh mengosongkan reader yang belum dimigrasi. Kalau test ini
     * gagal, berarti ada caller yang ikut flag padahal sumbernya masih legacy.
     */
    public function test_reader_belum_dimigrasi_tetap_legacy_saat_flag_structured(): void
    {
        config(['kpi.read_model' => 'structured']);

        $division = $this->division('Pengusahaan');
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);

        $this->legacyMetric($dataset, $division, 'TJBT | Indikator A | target', 100.0, '2026-08-31');
        $this->legacyMetric($dataset, $division, 'TJBT | Indikator A | realisasi', 110.0, '2026-08-31');
        $this->legacyMetric($dataset, $division, 'TJBT | NKO', 105.5, '2026-08-31');

        $user = $this->management();

        // R3 nkoRanking: tetap lintas divisi, tetap membaca baris NKO legacy.
        $ranking = $this->reader('nkoRanking', [$user]);
        $this->assertSame(['TJBT'], $ranking->pluck('unit')->all());
        $this->assertSame(105.5, (float) $ranking->first()->nko);

        // R6 kinerjaRealisasi: periode dan agregasi tetap legacy.
        $realisasi = $this->reader('kinerjaRealisasi', [$user]);
        $this->assertSame('2026-08-31', $realisasi['period']);
        $this->assertTrue($realisasi['has_data']);

        // R7 kinerjaDetail: tetap membaca target/realisasi + NKO legacy.
        $detail = $this->reader('kinerjaDetail', [$user]);
        $this->assertSame('2026-08-31', $detail['period']);
        $this->assertSame(1, $detail['total_rows']);
        $this->assertSame(105.5, $detail['rows'][0]['nko']);

        // R8 targetVsRealisasi: tetap legacy, tidak kosong.
        $tvr = $this->reader('targetVsRealisasi', [$user]);
        $this->assertSame('2026-08-31', $tvr['period']);
        $this->assertSame(['TJBT'], $tvr['labels']);

        // R9 pencapaianNko: delegasi ke R6, jadi ikut legacy.
        $pencapaian = $this->reader('pencapaianNko', [$user]);
        $this->assertSame('2026-08-31', $pencapaian['period']);
        $this->assertSame(['TJBT'], $pencapaian['labels']);

        // R10 nkoDonut: tetap membaca baris NKO legacy.
        $donut = $this->reader('nkoDonut', [$user]);
        $this->assertSame('2026-08-31', $donut['period']);
        $this->assertSame([['name' => 'Tercapai', 'value' => 1]], $donut['data']);
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
