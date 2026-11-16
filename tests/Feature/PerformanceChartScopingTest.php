<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Division;
use App\Models\PerformanceMetric;
use App\Models\Role;
use App\Models\User;
use App\Services\KpiReportConverter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Memverifikasi scoping data performance per divisi (audit #3).
 *
 * - Admin, management & editor: boleh lihat agregat semua divisi.
 * - Staf divisi: hanya divisinya sendiri.
 *
 * Basis agregasi NKO (bukan SUM seluruh value) — lihat PerformanceChartDataTest.
 */
class PerformanceChartScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Default produksi kini 'structured' (Fase 4.3 Tahap B). Test ini
        // mengunci jalur legacy, jadi mode legacy ditetapkan eksplisit.
        config(['kpi.read_model' => 'legacy']);

        $this->seed(RolePermissionSeeder::class);

        // Test ini menguji scoping DIVISI (bukan batas unit TAMPIL). Batas unit
        // (keputusan 2026-10-12, `kpi.visible_units`) dimatikan supaya nama unit
        // stand-in lintas divisi tetap valid; batas itu diuji di `KpiUnitScopeTest`.
        config(['kpi.visible_units' => null]);
    }

    private function seedDivisionsAndMetrics(): array
    {
        $divA = Division::create(['name' => 'Perencanaan Pengusahaan', 'slug' => 'perencanaan-pengusahaan']);
        $divB = Division::create(['name' => 'Divisi B', 'slug' => 'div-b']);

        $nko = function (Division $division, Dataset $dataset, string $unit, string $period, float $value): void {
            PerformanceMetric::create([
                'metric_name' => $unit.KpiReportConverter::NKO_SUFFIX,
                'value' => $value,
                'period' => $period,
                'division_id' => $division->id,
                'dataset_id' => $dataset->id,
            ]);
        };

        $datasetA = Dataset::factory()->create(['division_id' => $divA->id]);
        $datasetB = Dataset::factory()->create(['division_id' => $divB->id]);

        $nko($divA, $datasetA, 'RST', '2026-01-15', 100);
        $nko($divA, $datasetA, 'TEK', '2026-01-15', 98);
        $nko($divA, $datasetA, 'RST', '2026-02-15', 105);
        $nko($divA, $datasetA, 'TEK', '2026-02-15', 103);

        $nko($divB, $datasetB, 'TJBT', '2026-01-15', 95);
        $nko($divB, $datasetB, 'TRANS', '2026-01-15', 94);
        $nko($divB, $datasetB, 'TJBT', '2026-02-15', 95);
        $nko($divB, $datasetB, 'TRANS', '2026-02-15', 94);

        return [$divA, $divB];
    }

    public function test_admin_melihat_agregat_semua_divisi(): void
    {
        $this->seedDivisionsAndMetrics();
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $response = $this->actingAs($admin)
            ->getJson(route('performance.data'))
            ->assertOk();

        // Admin melihat kedua divisi: rata-rata NKO periode terbaru
        // (105 + 103 + 95 + 94) / 4 = 99.25.
        $this->assertEquals(99.25, (float) $response->json('kpi.average_nko'));

        $seriesNames = collect($response->json('series'))->pluck('name');
        $this->assertContains('RST', $seriesNames);
        $this->assertContains('TEK', $seriesNames);
        $this->assertContains('TJBT', $seriesNames);
        $this->assertContains('TRANS', $seriesNames);
    }

    public function test_management_melihat_agregat_semua_divisi(): void
    {
        $this->seedDivisionsAndMetrics();
        $mgmt = User::factory()->withRole(Role::MANAGEMENT)->create();

        $response = $this->actingAs($mgmt)
            ->getJson(route('performance.data'))
            ->assertOk();

        $this->assertEquals(99.25, (float) $response->json('kpi.average_nko'));
    }

    public function test_staf_divisi_hanya_lihat_datanya_sendiri(): void
    {
        [$divA, $divB] = $this->seedDivisionsAndMetrics();

        $staffA = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $divA->id,
        ]);

        $response = $this->actingAs($staffA)
            ->getJson(route('performance.data'))
            ->assertOk();

        // Staf A hanya boleh melihat unit di divisi A: (105 + 103) / 2 = 104.
        // TIDAK boleh terpengaruh unit dari divisi B.
        $this->assertEquals(104.0, (float) $response->json('kpi.average_nko'));

        $seriesNames = collect($response->json('series'))->pluck('name');
        $this->assertContains('RST', $seriesNames);
        $this->assertContains('TEK', $seriesNames);
        $this->assertNotContains('TJBT', $seriesNames);
        $this->assertNotContains('TRANS', $seriesNames);
    }

    public function test_staf_divisi_tidak_boleh_lihat_data_divisi_lain(): void
    {
        // Eksplisit memverifikasi bahwa unit divisi B TIDAK bocor ke staf A.
        [$divA, $divB] = $this->seedDivisionsAndMetrics();

        $staffA = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $divA->id,
        ]);

        $response = $this->actingAs($staffA)
            ->getJson(route('performance.data'))
            ->assertOk();

        $this->assertStringNotContainsString('TJBT', json_encode($response->json()));
        $this->assertStringNotContainsString('TRANS', json_encode($response->json()));
    }

    public function test_editor_tanpa_divisi_melihat_agregat_semua_divisi(): void
    {
        // Editor Dashboard lintas divisi meski division_id null (lihat
        // DatabaseSeeder: editor/management/admin tanpa divisi tetap).
        // Regresi yang diperbaiki: filter division_id = null membuat chart kosong.
        $this->seedDivisionsAndMetrics();
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create([
            'division_id' => null,
        ]);

        $response = $this->actingAs($editor)
            ->getJson(route('performance.data'))
            ->assertOk();

        // Sama seperti admin: (105 + 103 + 95 + 94) / 4 = 99.25, bukan 0/kosong.
        $this->assertEquals(99.25, (float) $response->json('kpi.average_nko'));

        $seriesNames = collect($response->json('series'))->pluck('name');
        $this->assertContains('RST', $seriesNames);
        $this->assertContains('TEK', $seriesNames);
        $this->assertContains('TJBT', $seriesNames);
        $this->assertContains('TRANS', $seriesNames);

        // Kartu KPI hanya menghitung divisi perencanaan-pengusahaan (1 dataset READY, 4 baris metrik).
        $this->assertSame(1, $response->json('kpi.ready_datasets'));
        $this->assertSame(4, $response->json('kpi.metric_count'));
    }

    public function test_editor_dengan_divisi_tetap_melihat_agregat_semua_divisi(): void
    {
        // Editor yang kebetulan punya division_id tetap lintas divisi,
        // tidak tersekat ke divisinya seperti staf.
        [$divA, $divB] = $this->seedDivisionsAndMetrics();
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create([
            'division_id' => $divA->id,
        ]);

        $response = $this->actingAs($editor)
            ->getJson(route('performance.data'))
            ->assertOk();

        $this->assertEquals(99.25, (float) $response->json('kpi.average_nko'));

        $seriesNames = collect($response->json('series'))->pluck('name');
        $this->assertContains('RST', $seriesNames);
        $this->assertContains('TJBT', $seriesNames);
        $this->assertStringContainsString('TJBT', json_encode($response->json()));
    }
}
