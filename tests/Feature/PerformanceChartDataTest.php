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

        // Test kontrak chart (null-gap, MAX, rata-rata periode terbaru, y_axis)
        // memakai nama unit sebagai stand-in. Batas unit TAMPIL (keputusan
        // 2026-10-12, `kpi.visible_units`) dimatikan supaya stand-in non-TJBT
        // tetap valid; batas itu diuji di `KpiUnitScopeTest`.
        config(['kpi.visible_units' => null]);

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

    public function test_series_per_divisi_dengan_periode_kosong_null(): void
    {
        // RST punya NKO di Jan & Feb; TEK hanya di Feb dan berbagi owner.
        // Series RST harus [100, 200], series TEK harus [null, 999] —
        // periode tanpa laporan = null, BUKAN 0 (NKO indeks 0-110, jadi 0
        // akan terbaca "nilai nol/gagal", bukan "belum lapor").
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-01-31', 100, 'RST');
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-02-28', 200, 'RST');
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-02-28', 999, 'TEK');

        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $response = $this->actingAs($admin)->getJson(route('performance.data'))->assertOk();

        $this->assertSame(['2026-01-31', '2026-02-28'], $response->json('labels'));

        $series = collect($response->json('series'))->keyBy('name');
        $this->assertEquals([100.0, 200.0], array_map('floatval', $series['RST']['data']));
        $this->assertNull($series['TEK']['data'][0]);
        $this->assertEquals(999.0, (float) $series['TEK']['data'][1]);
    }

    public function test_periode_belum_dilapor_bernilai_null_bukan_nol(): void
    {
        // Unit TEK hanya lapor periode Jan; Feb belum. Nilai Feb harus null
        // agar garis tren terputus di frontend (bukan jatuh ke 0).
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-01-31', 105, 'TEK');
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-01-31', 100, 'RST');
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-02-28', 110, 'RST');

        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $response = $this->actingAs($admin)->getJson(route('performance.data'))->assertOk();

        $series = collect($response->json('series'))->keyBy('name');

        // JSON harus memuat null literal, bukan 0.
        $this->assertNull($series['TEK']['data'][1]);
        $this->assertEquals(105.0, (float) $series['TEK']['data'][0]);

        // Series TEK masih dikirim (punya data di satu periode), RST dua periode.
        $this->assertCount(2, $series['TEK']['data']);
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

    public function test_filter_rentang_tanggal_dan_unit_membatasi_series_dan_kpi(): void
    {
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-01-31', 100, 'RST');
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-02-28', 110, 'RST');
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-01-31', 90, 'TEK');
        $this->datasetWithNko($this->divA, Dataset::STATUS_READY, '2026-02-28', 100, 'TEK');

        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $response = $this->actingAs($admin)->getJson(route('performance.data', [
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-28',
            'unit' => ['RST', 'TEK'],
        ]))->assertOk();

        $this->assertSame(['2026-02-28'], $response->json('labels'));
        $series = collect($response->json('series'))->keyBy('name');
        $this->assertSame(['RST', 'TEK'], $series->keys()->all());
        $this->assertEquals([110.0], array_map('floatval', $series['RST']['data']));
        $this->assertEquals([100.0], array_map('floatval', $series['TEK']['data']));
        $this->assertEquals(105.0, (float) $response->json('kpi.average_nko'));
    }

    public function test_filter_tanggal_dengan_rentang_terbalik_ditolak(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $this->actingAs($admin)->getJson(route('performance.data', [
            'start_date' => '2026-03-01',
            'end_date' => '2026-02-28',
        ]))->assertUnprocessable();
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

    public function test_widget_tren_punya_subjudul_dinamis(): void
    {
        // Subjudul mengikuti jumlah periode yang nyata (snapshot vs tren),
        // supaya "grafik tren" tidak mengklaim tren saat data cuma 1 periode.
        $user = User::factory()->withRole(Role::MANAGEMENT)->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('chartSubtitle()', false)
            ->assertSee('chartPeriodCount', false);
    }

    public function test_widget_tren_selalu_menampilkan_chart(): void
    {
        // C1 dibatalkan 2026-10-01: chart selalu tampil. Bentuknya menyesuaikan
        // jumlah periode (bar untuk snapshot, garis untuk tren), bukan disembunyikan.
        $user = User::factory()->withRole(Role::MANAGEMENT)->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Tren akan tampil setelah ada 2 periode')
            ->assertSee('id="performanceChart"', false)
            ->assertSee('chartSubtitle()', false);
    }

    /**
     * Inline script dashboard WAJIB utuh — tidak boleh rusak sebagian.
     *
     * assertSee tidak mem-parse JS, sehingga blok yang rusak bisa lolos dari
     * seluruh suite. Pernah terjadi 2026-10-01: potongan renderChart terpotong
     * dan tergabung acak, Alpine gagal init, semua x-show/x-text jadi kosong.
     * Guard ini mengecek fragmen kunci tetap utuh dan tidak tergabung.
     */
    public function test_inline_script_dashboard_utuh(): void
    {
        $user = User::factory()->withRole(Role::MANAGEMENT)->create();
        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        preg_match_all('#<script>(.*?)</script>#s', $html, $matches);

        $scripts = array_values(array_filter(
            $matches[1] ?? [],
            fn (string $script): bool => str_contains($script, 'performanceDashboard'),
        ));

        $this->assertNotEmpty($scripts, 'Blok script performanceDashboard tidak ditemukan di dashboard.');

        $script = $scripts[0];

        // Fragmen yang harus utuh di dalam renderChart().
        $this->assertStringContainsString('type: \'line\',', $script);
        $this->assertStringContainsString('smooth: true,', $script);
        $this->assertStringContainsString('data: s.data,', $script);

        // Pola rusak yang pernah lolos: dua properti tergabung dalam satu baris.
        $this->assertStringNotContainsString('s.da ', $script);
        $this->assertStringNotContainsString('smooth: ta,', $script);
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

    public function test_y_axis_min_tidak_terseret_oleh_periode_kosong(): void
    {
        // Divisi A: TEK hanya punya NKO di Feb, jadi Jan untuk TEK = null
        // (lihat test_series_per_divisi_dengan_periode_kosong_null).
        // y_axis_min HARUS dihitung dari nilai NKO mentah (min 100), bukan
        // dari periode kosong di series.
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
