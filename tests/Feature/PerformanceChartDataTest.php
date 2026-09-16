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

    private function datasetWithNko(
        Division $division,
        string $status,
        string $period,
        float $nko,
        string $unit,
        ?Dataset $dataset = null,
    ): Dataset {
        $dataset ??= Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => $status,
        ]);
        PerformanceMetric::factory()->create([
            'metric_name' => $unit.KpiReportConverter::NKO_SUFFIX,
            'dataset_id' => $dataset->id,
            'division_id' => $division->id,
            'period' => $period,
            'value' => $nko,
        ]);

        return $dataset;
    }

    public function test_kontrak_json_berisi_labels_series_kpi(): void
    {
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-01-31', 100, 'RST');
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $response = $this->actingAs($admin)->getJson(route('performance.data'))->assertOk();

        $response->assertJsonStructure([
            'labels',
            'series' => [['name', 'data']],
            'kpi' => ['average_nko', 'ready_datasets', 'metric_count'],
        ]);
        $this->assertSame(['2026-01-31'], $response->json('labels'));
        $this->assertSame('RST', $response->json('series.0.name'));
        $this->assertEquals(100.0, (float) $response->json('series.0.data.0'));
        $this->assertEquals(100.0, (float) $response->json('kpi.average_nko'));
        $this->assertSame(1, $response->json('kpi.ready_datasets'));
        $this->assertSame(1, $response->json('kpi.metric_count'));
    }

    public function test_series_per_divisi_dengan_padding_nol(): void
    {
        // RST punya NKO di Jan & Feb; TEK hanya di Feb dan berbagi owner.
        // Series RST harus [100, 200], series TEK harus [0, 999].
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-01-31', 100, 'RST');
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-02-28', 200, 'RST');
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-02-28', 999, 'TEK');

        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $response = $this->actingAs($admin)->getJson(route('performance.data'))->assertOk();

        $this->assertSame(['2026-01-31', '2026-02-28'], $response->json('labels'));

        $series = collect($response->json('series'))->keyBy('name');
        $this->assertEquals([100.0, 200.0], array_map('floatval', $series['RST']['data']));
        $this->assertEquals([0.0, 999.0], array_map('floatval', $series['TEK']['data']));
    }

    public function test_upload_ganda_unit_dan_periode_yang_sama_memakai_nilai_maksimum(): void
    {
        $dataset = $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-01-31', 100, 'RST');
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-01-31', 120, 'RST', $dataset);

        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $response = $this->actingAs($admin)->getJson(route('performance.data'))->assertOk();

        $this->assertSame(['2026-01-31'], $response->json('labels'));
        $this->assertEquals([120.0], array_map('floatval', $response->json('series.0.data')));
        $this->assertEquals(120.0, (float) $response->json('kpi.average_nko'));
    }

    public function test_rata_rata_nko_diambil_dari_periode_terbaru(): void
    {
        // Kondisi terkini: Jan (100 & 90) tidak boleh mencemari angka periode
        // terbaru Feb (110 & 100) -> rata-rata 105.
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-01-31', 100, 'RST');
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-01-31', 90, 'TEK');
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-02-28', 110, 'RST');
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-02-28', 100, 'TEK');

        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $response = $this->actingAs($admin)->getJson(route('performance.data'))->assertOk();

        $this->assertEquals(105.0, (float) $response->json('kpi.average_nko'));
    }

    public function test_baris_target_dan_realisasi_tidak_ikut_diagregasi(): void
    {
        // Inti perbaikan: nilai target/realisasi bersatuan lain (Rp Juta,
        // Dokumen) tidak boleh masuk rata-rata NKO.
        $dataset = $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-01-31', 100, 'RST');

        PerformanceMetric::factory()->create([
            'metric_name' => 'RST | c. Usulan Penghapusan ATTB | realisasi',
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
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-01-31', 100, 'RST');
        $archived = $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-02-28', 500, 'RST');
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
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-01-31', 100, 'RST');
        $this->datasetWithNko($this->divB, Dataset::STATUS_READY, '2026-01-31', 999, 'TJBT');

        $staffA = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $this->divA->id]);
        $response = $this->actingAs($staffA)->getJson(route('performance.data'))->assertOk();

        $seriesNames = collect($response->json('series'))->pluck('name');
        $this->assertContains('RST', $seriesNames);
        $this->assertNotContains('TJBT', $seriesNames);
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

    public function test_y_axis_min_dihitung_dinamis_dari_nko_terendah(): void
    {
        // NKO terendah 95 -> margin 5 -> (95-5)/5=18 -> floor*5=90.
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-01-31', 95, 'RST');
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-01-31', 105.5, 'TEK');

        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $response = $this->actingAs($admin)->getJson(route('performance.data'))->assertOk();

        $this->assertEquals(90.0, (float) $response->json('y_axis_min'));
    }

    public function test_y_axis_min_tidak_terseret_oleh_padding_nol_periode_kosong(): void
    {
        // Divisi B hanya punya NKO di Feb; series-nya di-pad 0 di Jan (lihat
        // test_series_per_divisi_dengan_padding_nol). y_axis_min HARUS dihitung
        // dari nilai NKO mentah (min 100), bukan dari padding 0 di series.
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-01-31', 100, 'RST');
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-02-28', 105, 'RST');
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-02-28', 999, 'TEK');

        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $response = $this->actingAs($admin)->getJson(route('performance.data'))->assertOk();

        // min NKO mentah = 100 -> (100-5)/5=19 -> floor*5=95.
        $this->assertEquals(95.0, (float) $response->json('y_axis_min'));
    }

    public function test_y_axis_min_nol_saat_tidak_ada_data(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $response = $this->actingAs($admin)->getJson(route('performance.data'))->assertOk();

        $this->assertEquals(0.0, (float) $response->json('y_axis_min'));
    }
}
