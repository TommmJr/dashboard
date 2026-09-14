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
 * Test kontrak endpoint GET /api/performance-data.
 *
 * Agregasi berbasis NKO (bukan SUM seluruh value): kolom value memuat satuan
 * berbeda (Rp Juta, Dokumen, Kali, %, indeks), jadi menjumlahkannya tak
 * bermakna. Test memakai nama metric berakhiran NKO_SUFFIX seperti yang
 * dihasilkan KpiReportConverter.
 */
class PerformanceChartDataTest extends TestCase
{
    use RefreshDatabase;

    private Division $divA;

    private Division $divB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->divA = Division::factory()->create(['name' => 'Divisi A']);
        $this->divB = Division::factory()->create(['name' => 'Divisi B']);
    }

    private function datasetWithNko(Division $division, string $status, string $period, float $nko): Dataset
    {
        $dataset = Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => $status,
        ]);
        PerformanceMetric::factory()->create([
            'metric_name' => $division->name.KpiReportConverter::NKO_SUFFIX,
            'dataset_id' => $dataset->id,
            'division_id' => $division->id,
            'period' => $period,
            'value' => $nko,
        ]);

        return $dataset;
    }

    public function test_kontrak_json_berisi_labels_series_kpi(): void
    {
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-01-31', 100);
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $response = $this->actingAs($admin)->getJson(route('performance.data'))->assertOk();

        $response->assertJsonStructure([
            'labels',
            'series' => [['name', 'data']],
            'kpi' => ['average_nko', 'ready_datasets', 'metric_count'],
        ]);
        $this->assertSame(['2026-01-31'], $response->json('labels'));
        $this->assertSame('Divisi A', $response->json('series.0.name'));
        $this->assertEquals(100.0, (float) $response->json('series.0.data.0'));
        $this->assertEquals(100.0, (float) $response->json('kpi.average_nko'));
        $this->assertSame(1, $response->json('kpi.ready_datasets'));
        $this->assertSame(1, $response->json('kpi.metric_count'));
    }

    public function test_series_per_divisi_dengan_padding_nol(): void
    {
        // Divisi A punya NKO di Jan & Feb; Divisi B hanya di Feb.
        // Series A harus [100, 200], series B harus [0, 999] (padding nol).
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-01-31', 100);
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-02-28', 200);
        $this->datasetWithNko($this->divB, Dataset::STATUS_READY, '2026-02-28', 999);

        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $response = $this->actingAs($admin)->getJson(route('performance.data'))->assertOk();

        $this->assertSame(['2026-01-31', '2026-02-28'], $response->json('labels'));

        $series = collect($response->json('series'))->keyBy('name');
        $this->assertEquals([100.0, 200.0], array_map('floatval', $series['Divisi A']['data']));
        $this->assertEquals([0.0, 999.0], array_map('floatval', $series['Divisi B']['data']));
    }

    public function test_rata_rata_nko_diambil_dari_periode_terbaru(): void
    {
        // Kondisi terkini: Jan (100 & 90) tidak boleh mencemari angka periode
        // terbaru Feb (110 & 100) -> rata-rata 105.
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-01-31', 100);
        $this->datasetWithNko($this->divB, Dataset::STATUS_READY, '2026-01-31', 90);
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-02-28', 110);
        $this->datasetWithNko($this->divB, Dataset::STATUS_READY, '2026-02-28', 100);

        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $response = $this->actingAs($admin)->getJson(route('performance.data'))->assertOk();

        $this->assertEquals(105.0, (float) $response->json('kpi.average_nko'));
    }

    public function test_baris_target_dan_realisasi_tidak_ikut_diagregasi(): void
    {
        // Inti perbaikan: nilai target/realisasi bersatuan lain (Rp Juta,
        // Dokumen) tidak boleh masuk rata-rata NKO.
        $dataset = $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-01-31', 100);

        PerformanceMetric::factory()->create([
            'metric_name' => 'Divisi A | c. Usulan Penghapusan ATTB | realisasi',
            'dataset_id' => $dataset->id,
            'division_id' => $this->divA->id,
            'period' => '2026-01-31',
            'value' => 181979.53,
        ]);

        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $response = $this->actingAs($admin)->getJson(route('performance.data'))->assertOk();

        $this->assertEquals(100.0, (float) $response->json('kpi.average_nko'));
        $this->assertEquals(100.0, (float) $response->json('series.0.data.0'));

        // Baris tetap dihitung sebagai baris data aktif.
        $this->assertSame(2, $response->json('kpi.metric_count'));
    }

    public function test_dataset_archived_dikeluarkan_dari_series_dan_kpi(): void
    {
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-01-31', 100);
        $archived = $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-02-28', 500);
        $archived->update(['status' => Dataset::STATUS_ARCHIVED]);

        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $response = $this->actingAs($admin)->getJson(route('performance.data'))->assertOk();

        $this->assertSame(['2026-01-31'], $response->json('labels'));
        $this->assertEquals(100.0, (float) $response->json('kpi.average_nko'));
        $this->assertSame(1, $response->json('kpi.ready_datasets'));
        $this->assertSame(1, $response->json('kpi.metric_count'));
    }

    public function test_staf_hanya_melihat_series_divisinya(): void
    {
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-01-31', 100);
        $this->datasetWithNko($this->divB, Dataset::STATUS_READY, '2026-01-31', 999);

        $staffA = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $this->divA->id]);
        $response = $this->actingAs($staffA)->getJson(route('performance.data'))->assertOk();

        $seriesNames = collect($response->json('series'))->pluck('name');
        $this->assertContains('Divisi A', $seriesNames);
        $this->assertNotContains('Divisi B', $seriesNames);
        $this->assertEquals(100.0, (float) $response->json('kpi.average_nko'));
    }

    public function test_dashboard_view_memuat_elemen_chart(): void
    {
        $user = User::factory()->withRole(Role::MANAGEMENT)->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="performanceChart"', false)
            ->assertSee(route('performance.data'), false);
    }
}
