<?php

namespace Tests\Feature;

use App\Http\Controllers\DashboardController;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\Indicator;
use App\Models\OrgUnit;
use App\Models\PerformanceObservation;
use App\Models\Role;
use App\Models\User;
use App\Services\KpiReportConverter;
use App\Services\TargetRealisasiPerIndicatorReader;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class KpiCalculationContractRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Division $division;

    private OrgUnit $orgUnit;

    private Dataset $dataset;

    private DashboardController $controller;

    private TargetRealisasiPerIndicatorReader $panelReader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $adminRole = Role::where('slug', Role::ADMIN)->firstOrFail();
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'division_id' => null]);
        $this->division = Division::factory()->create(['slug' => 'perencanaan-pengusahaan']);
        $this->orgUnit = OrgUnit::firstOrCreate(['name' => 'TJBT']);
        $this->dataset = Dataset::factory()->create([
            'division_id' => $this->division->id,
            'status' => Dataset::STATUS_READY,
        ]);

        $this->controller = app(DashboardController::class);
        $this->panelReader = app(TargetRealisasiPerIndicatorReader::class);
    }

    private function invokeR6(): array
    {
        $ref = new ReflectionClass($this->controller);
        $m = $ref->getMethod('kinerjaRealisasiStructured');
        $m->setAccessible(true);

        return $m->invoke($this->controller, $this->admin, null, null);
    }

    private function invokeR7(): array
    {
        $ref = new ReflectionClass($this->controller);
        $m = $ref->getMethod('kinerjaDetailStructured');
        $m->setAccessible(true);

        return $m->invoke($this->controller, $this->admin, null, null, ['TJBT']);
    }

    public function test_skenario_1_numerik_polaritas_terbalik_r6_pakai_skor_excel_r7_rasio_naif(): void
    {
        $ind = Indicator::create([
            'org_unit_id' => $this->orgUnit->id,
            'name' => 'Kali sirkit padam',
            'is_group' => false,
            'pencapaian_formula_kind' => KpiReportConverter::PENCAPAIAN_FORMULA_POLARITY_RATIO,
        ]);
        PerformanceObservation::create([
            'dataset_id' => $this->dataset->id,
            'indicator_id' => $ind->id,
            'org_unit_id' => $this->orgUnit->id,
            'division_id' => $this->division->id,
            'period' => '2026-08-31',
            'target_value' => 5.326,
            'realisasi_value' => 4.0,
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'pencapaian' => 1.1000,
        ]);

        $r6 = $this->invokeR6();
        $r7 = $this->invokeR7();
        $panels = $this->panelReader->panels(null, '2026-08-31', ['TJBT']);

        // R6 menggunakan skor Excel (110.0%)
        $this->assertEqualsWithDelta(110.0, $r6['rows'][0]['avg'], 0.01);
        // R7 sengaja rasio naif un-clamped tanpa polaritas (4.0 / 5.326 * 100 = 75.1%)
        $this->assertEqualsWithDelta(75.1, $r7['rows'][0]['pencapaian'], 0.05);
        // Panel menggunakan skor Excel (110.0%)
        $panel = $panels['sections'][0]['panels'][0];
        $this->assertEqualsWithDelta(110.0, $panel['score']['percent'], 0.01);
    }

    public function test_skenario_2_attb_over_target_r6_clamped_110_r7_unclamped_632(): void
    {
        $ind = Indicator::create([
            'org_unit_id' => $this->orgUnit->id,
            'name' => 'Penghapusan ATTB',
            'is_group' => false,
            'pencapaian_formula_kind' => KpiReportConverter::PENCAPAIAN_FORMULA_POLARITY_RATIO,
        ]);
        PerformanceObservation::create([
            'dataset_id' => $this->dataset->id,
            'indicator_id' => $ind->id,
            'org_unit_id' => $this->orgUnit->id,
            'division_id' => $this->division->id,
            'period' => '2026-08-31',
            'target_value' => 28795.3,
            'realisasi_value' => 181979.53,
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'pencapaian' => 1.1000,
        ]);

        $r6 = $this->invokeR6();
        $r7 = $this->invokeR7();
        $panels = $this->panelReader->panels(null, '2026-08-31', ['TJBT']);

        $this->assertEqualsWithDelta(110.0, $r6['rows'][0]['avg'], 0.01);
        $this->assertEqualsWithDelta(632.0, $r7['rows'][0]['pencapaian'], 0.05);
        $panel = $panels['sections'][0]['panels'][0];
        $this->assertEqualsWithDelta(110.0, $panel['score']['percent'], 0.01);
    }

    public function test_skenario_3_skor_murni_403_terkonversi_persen_di_seluruh_reader(): void
    {
        $ind = Indicator::create([
            'org_unit_id' => $this->orgUnit->id,
            'name' => 'Anggaran Investasi sesuai RKAP',
            'is_group' => false,
            'pencapaian_formula_kind' => null,
        ]);
        PerformanceObservation::create([
            'dataset_id' => $this->dataset->id,
            'indicator_id' => $ind->id,
            'org_unit_id' => $this->orgUnit->id,
            'division_id' => $this->division->id,
            'period' => '2026-08-31',
            'target_value' => null,
            'realisasi_value' => 99.85,
            'target_type' => PerformanceObservation::TYPE_TEXT,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'pencapaian' => 1.0485,
        ]);

        $r6 = $this->invokeR6();
        $r7 = $this->invokeR7();
        $panels = $this->panelReader->panels(null, '2026-08-31', ['TJBT']);

        $this->assertEqualsWithDelta(104.9, $r6['rows'][0]['avg'], 0.01);
        $this->assertEqualsWithDelta(104.9, $r7['rows'][0]['pencapaian'], 0.05);
        $panel = $panels['sections'][0]['panels'][0];
        $this->assertSame('ratio', $panel['score']['scale']);
        $this->assertNotNull($panel['score']['percent']);
        $this->assertEqualsWithDelta(104.85, $panel['score']['percent'], 0.01);
    }

    public function test_skenario_4_skor_persen_410_terbaca_skala_persen_lintas_reader(): void
    {
        $ind = Indicator::create([
            'org_unit_id' => $this->orgUnit->id,
            'name' => 'Penyelesaian Dokumen Legal Aset Tanah',
            'is_group' => false,
            'pencapaian_formula_kind' => null,
        ]);
        PerformanceObservation::create([
            'dataset_id' => $this->dataset->id,
            'indicator_id' => $ind->id,
            'org_unit_id' => $this->orgUnit->id,
            'division_id' => $this->division->id,
            'period' => '2026-08-31',
            'target_value' => 261.0,
            'realisasi_value' => null,
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_TEXT,
            'realisasi_text' => 'Sesuai Target : 283',
            'pencapaian' => 110.0000,
        ]);

        $r6 = $this->invokeR6();
        $r7 = $this->invokeR7();
        $panels = $this->panelReader->panels(null, '2026-08-31', ['TJBT']);

        $this->assertEqualsWithDelta(110.0, $r6['rows'][0]['avg'], 0.01);
        $this->assertEqualsWithDelta(110.0, $r7['rows'][0]['pencapaian'], 0.05);
        $panel = $panels['sections'][0]['panels'][0];
        $this->assertSame('percent', $panel['score']['scale']);
        $this->assertEqualsWithDelta(110.0, $panel['score']['percent'], 0.01);
    }

    public function test_skenario_5_baris_tanggal_berskor_413_konsisten_lintas_reader(): void
    {
        $ind = Indicator::create([
            'org_unit_id' => $this->orgUnit->id,
            'name' => 'Kualitas Data Material Cadang',
            'is_group' => false,
            'pencapaian_formula_kind' => null,
        ]);
        PerformanceObservation::create([
            'dataset_id' => $this->dataset->id,
            'indicator_id' => $ind->id,
            'org_unit_id' => $this->orgUnit->id,
            'division_id' => $this->division->id,
            'period' => '2026-08-31',
            'target_date' => '2026-08-31',
            'realisasi_date' => '2026-08-21',
            'target_type' => PerformanceObservation::TYPE_DATE,
            'realisasi_type' => PerformanceObservation::TYPE_DATE,
            'pencapaian' => 1.0143,
        ]);

        $r6 = $this->invokeR6();
        $r7 = $this->invokeR7();
        $panels = $this->panelReader->panels(null, '2026-08-31', ['TJBT']);

        // R6 menormalisasi dan membulatkan avg ke 1 desimal (101.4%)
        $this->assertEqualsWithDelta(101.4, $r6['rows'][0]['avg'], 0.01);
        // R7 menampilkan 101.4% dengan display tanggal
        $this->assertEqualsWithDelta(101.4, $r7['rows'][0]['pencapaian'], 0.05);
        $this->assertSame('31/08/2026', $r7['rows'][0]['target_display']);
        $this->assertSame('21/08/2026', $r7['rows'][0]['realisasi_display']);
        // Panel mengenali sebagai mode date dengan delta -10 hari dan skor 101.43%
        $panel = $panels['sections'][0]['panels'][0];
        $this->assertSame('date', $panel['mode']);
        $this->assertSame('2026-08-31', $panel['date']['target']);
        $this->assertSame('2026-08-21', $panel['date']['realisasi']);
        $this->assertSame(-10, $panel['date']['delta_days']);
        $this->assertSame('ratio', $panel['score']['scale']);
        $this->assertEqualsWithDelta(101.43, $panel['score']['percent'], 0.01);
    }

    public function test_skenario_6_tanggal_tanpa_skor_mengembalikan_null_bukan_serial_angka(): void
    {
        $ind = Indicator::create([
            'org_unit_id' => $this->orgUnit->id,
            'name' => 'Indikator Tanggal Tanpa Skor',
            'is_group' => false,
        ]);
        PerformanceObservation::create([
            'dataset_id' => $this->dataset->id,
            'indicator_id' => $ind->id,
            'org_unit_id' => $this->orgUnit->id,
            'division_id' => $this->division->id,
            'period' => '2026-08-31',
            'target_date' => '2026-08-31',
            'realisasi_date' => '2026-08-31',
            'target_type' => PerformanceObservation::TYPE_DATE,
            'realisasi_type' => PerformanceObservation::TYPE_DATE,
            'pencapaian' => null,
        ]);

        $r6 = $this->invokeR6();
        $r7 = $this->invokeR7();
        $panels = $this->panelReader->panels(null, '2026-08-31', ['TJBT']);

        // R6 melewati baris tanpa skor
        $this->assertFalse($r6['has_data']);
        // R7 menampilkan baris tetapi nilai pencapaian null
        $this->assertSame(1, $r7['total_rows']);
        $this->assertNull($r7['rows'][0]['pencapaian']);
        // Panel menampilkan mode date tanpa skor
        $panel = $panels['sections'][0]['panels'][0];
        $this->assertSame('date', $panel['mode']);
        $this->assertNull($panel['score']);
    }

    public function test_skenario_7_target_nol_tidak_memicu_division_by_zero(): void
    {
        $ind = Indicator::create([
            'org_unit_id' => $this->orgUnit->id,
            'name' => 'Target Nol',
            'is_group' => false,
        ]);
        PerformanceObservation::create([
            'dataset_id' => $this->dataset->id,
            'indicator_id' => $ind->id,
            'org_unit_id' => $this->orgUnit->id,
            'division_id' => $this->division->id,
            'period' => '2026-08-31',
            'target_value' => 0.0,
            'realisasi_value' => 50.0,
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'pencapaian' => null,
        ]);

        $r7 = $this->invokeR7();
        $panels = $this->panelReader->panels(null, '2026-08-31', ['TJBT']);

        $this->assertSame(1, $r7['total_rows']);
        $this->assertNull($r7['rows'][0]['pencapaian']);
        $panel = $panels['sections'][0]['panels'][0];
        $this->assertSame('numeric', $panel['mode']);
        $this->assertNull($panel['score']);
    }

    public function test_skenario_8_isolasi_baris_induk_diabaikan_dari_perhitungan(): void
    {
        // 1. Baris Induk (is_group = true)
        $parent = Indicator::create([
            'org_unit_id' => $this->orgUnit->id,
            'name' => '1. KINERJA OPERASIONAL (Grup Induk)',
            'is_group' => true,
        ]);
        PerformanceObservation::create([
            'dataset_id' => $this->dataset->id,
            'indicator_id' => $parent->id,
            'org_unit_id' => $this->orgUnit->id,
            'division_id' => $this->division->id,
            'period' => '2026-08-31',
            'target_value' => 100.0,
            'realisasi_value' => 105.0,
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'pencapaian' => 1.0500,
        ]);

        // 2. Baris Daun (is_group = false)
        $child = Indicator::create([
            'org_unit_id' => $this->orgUnit->id,
            'parent_id' => $parent->id,
            'name' => 'a. Kesiapan Pembangkit',
            'is_group' => false,
            'pencapaian_formula_kind' => KpiReportConverter::PENCAPAIAN_FORMULA_POLARITY_RATIO,
        ]);
        PerformanceObservation::create([
            'dataset_id' => $this->dataset->id,
            'indicator_id' => $child->id,
            'org_unit_id' => $this->orgUnit->id,
            'division_id' => $this->division->id,
            'period' => '2026-08-31',
            'target_value' => 100.0,
            'realisasi_value' => 102.0,
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'pencapaian' => 1.0200,
        ]);

        $r6 = $this->invokeR6();
        $r7 = $this->invokeR7();
        $panels = $this->panelReader->panels(null, '2026-08-31', ['TJBT']);

        // R6: hanya menghitung baris daun ($child)
        $this->assertSame(1, $r6['rows'][0]['total']);
        $this->assertEqualsWithDelta(102.0, $r6['rows'][0]['avg'], 0.01);

        // R7: hanya menampilkan baris daun, baris induk dieksklusi
        $this->assertSame(1, $r7['total_rows']);
        $this->assertSame($child->name, $r7['rows'][0]['indikator']);

        // Panel: baris induk menjadi header section, panel hanya berisi baris daun
        $section = $panels['sections'][0];
        $this->assertSame(1, count($section['panels']));
        $this->assertSame($child->name, $section['panels'][0]['name']);
    }
}
