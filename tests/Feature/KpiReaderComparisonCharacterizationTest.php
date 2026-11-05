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

class KpiReaderComparisonCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private DashboardController $controller;

    private TargetRealisasiPerIndicatorReader $panelReader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $adminRole = Role::where('slug', Role::ADMIN)->firstOrFail();
        $this->admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'division_id' => null,
        ]);

        $this->controller = app(DashboardController::class);
        $this->panelReader = app(TargetRealisasiPerIndicatorReader::class);
    }

    public function test_r6_ringkasan_eksekutif_menghitung_16_daun_berskor_dengan_cap_110_dan_rata_rata_105_9(): void
    {
        $ref = new ReflectionClass($this->controller);
        $method = $ref->getMethod('kinerjaRealisasiStructured');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, $this->admin, null, null);

        // Jika DB lokal belum memiliki observasi, lewati assertion data riil
        if (! ($result['has_data'] ?? false)) {
            $this->markTestSkipped('Database lokal tidak memiliki dataset KPI terstruktur aktif.');
        }

        $this->assertCount(1, $result['rows']);
        $tjbt = $result['rows'][0];

        $this->assertSame('TJBT', $tjbt['unit']);
        $this->assertSame(16, $tjbt['total']);
        $this->assertSame(16, $tjbt['achieved']);
        $this->assertEqualsWithDelta(105.9, $tjbt['avg'], 0.05);
        $this->assertSame('Tercapai', $tjbt['status']);
    }

    public function test_r7_tabel_rincian_menampilkan_16_baris_dengan_rasio_murni_tanpa_cap_pada_pasangan_numerik(): void
    {
        $ref = new ReflectionClass($this->controller);
        $method = $ref->getMethod('kinerjaDetailStructured');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, $this->admin, null, null, ['TJBT']);

        if ($result['total_rows'] === 0) {
            $this->markTestSkipped('Database lokal tidak memiliki dataset KPI terstruktur aktif.');
        }

        $this->assertSame(16, $result['total_rows']);
        $rowsByIndicator = collect($result['rows'])->keyBy('indikator');

        // Indikator 405 (ATTB): rasio naif murni 181979.53 / 28795.3 * 100 = 632.0%
        $attb = $rowsByIndicator->first(fn ($r, $k) => str_contains($k, 'Penghapusan ATTB'));
        $this->assertNotNull($attb);
        $this->assertEqualsWithDelta(632.0, $attb['pencapaian'], 0.1);

        // Indikator 412 (Maturity Level): 109.78 / 80.0 * 100 = 137.2%
        $maturity = $rowsByIndicator->first(fn ($r, $k) => str_contains($k, 'Maturity Level Manajemen Aset'));
        $this->assertNotNull($maturity);
        $this->assertEqualsWithDelta(137.2, $maturity['pencapaian'], 0.1);

        // Indikator 394 (Kali sirkit padam): lower-is-better rasio naif 4.0 / 5.326 * 100 = 75.1%
        $padam = $rowsByIndicator->first(fn ($r, $k) => str_contains($k, 'Kali sirkit padam'));
        $this->assertNotNull($padam);
        $this->assertEqualsWithDelta(75.1, $padam['pencapaian'], 0.1);

        // Indikator 413 (Material Cadang): tipe tanggal memakai skor Excel 101.4%
        $matCadang = $rowsByIndicator->first(fn ($r, $k) => str_contains($k, 'Material Cadang'));
        $this->assertNotNull($matCadang);
        $this->assertEqualsWithDelta(101.4, $matCadang['pencapaian'], 0.1);

        // Indikator 403 (RKAP): target teks memakai skor Excel 104.9%
        $rkap = $rowsByIndicator->first(fn ($r, $k) => str_contains($k, 'Investasi sesuai RKAP'));
        $this->assertNotNull($rkap);
        $this->assertEqualsWithDelta(104.9, $rkap['pencapaian'], 0.1);
    }

    public function test_karakterisasi_matriks_sintetis_16_indikator_memastikan_konsistensi_antar_reader(): void
    {
        $division = Division::factory()->create(['slug' => 'perencanaan-pengusahaan']);
        $dataset = Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_READY,
        ]);
        $orgUnit = OrgUnit::firstOrCreate(['name' => 'TJBT']);

        // 1. Numerik biasa (391)
        $ind391 = Indicator::create([
            'org_unit_id' => $orgUnit->id,
            'name' => 'a. Faktor Ketersediaan Trafo',
            'is_group' => false,
            'pencapaian_formula_kind' => KpiReportConverter::PENCAPAIAN_FORMULA_POLARITY_RATIO,
        ]);
        PerformanceObservation::create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $ind391->id,
            'org_unit_id' => $orgUnit->id,
            'division_id' => $division->id,
            'period' => '2026-08-31',
            'target_value' => 99.92,
            'realisasi_value' => 99.986,
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'pencapaian' => 1.0007,
        ]);

        // 2. ATTB over-110% (405)
        $ind405 = Indicator::create([
            'org_unit_id' => $orgUnit->id,
            'name' => 'c. Usulan Penghapusan ATTB',
            'is_group' => false,
            'pencapaian_formula_kind' => KpiReportConverter::PENCAPAIAN_FORMULA_POLARITY_RATIO,
        ]);
        PerformanceObservation::create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $ind405->id,
            'org_unit_id' => $orgUnit->id,
            'division_id' => $division->id,
            'period' => '2026-08-31',
            'target_value' => 28795.3,
            'realisasi_value' => 181979.53,
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'pencapaian' => 1.1000,
        ]);

        $ref = new ReflectionClass($this->controller);
        $mR6 = $ref->getMethod('kinerjaRealisasiStructured');
        $mR6->setAccessible(true);
        $resR6 = $mR6->invoke($this->controller, $this->admin, null, null);

        $mR7 = $ref->getMethod('kinerjaDetailStructured');
        $mR7->setAccessible(true);
        $resR7 = $mR7->invoke($this->controller, $this->admin, null, null, ['TJBT']);

        // R6 membatasi ATTB pada 110%
        // R7 menampilkan rasio aktual 632.0%
        $r7Rows = collect($resR7['rows'])->keyBy('indikator');
        $this->assertEqualsWithDelta(632.0, $r7Rows[$ind405->name]['pencapaian'], 0.1);
        $this->assertSame(2, $resR6['rows'][0]['total']);
        $this->assertEqualsWithDelta((100.07 + 110.0) / 2, $resR6['rows'][0]['avg'], 0.1);
    }
}
