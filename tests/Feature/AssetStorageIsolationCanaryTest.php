<?php

namespace Tests\Feature;

use App\Http\Controllers\DashboardController;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\PerformanceMetric;
use App\Models\Role;
use App\Models\User;
use App\Services\AssetStockReport;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitDivisionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Canary test untuk membuktikan isolasi penyimpanan metrik level UPT dan UIT.
 *
 * Menguji bahwa injeksi metrik ASET | UIT | ... ke dalam dataset yang sama
 * menghasilkan 0 DELTA terhadap:
 * 1. AssetStockReport::totalsByUpt()
 * 2. DashboardController::asetUptChart() summary totals, UPT labels, table rows, dan series.
 */
class AssetStorageIsolationCanaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_injeksi_metrik_uit_menghasilkan_nol_delta_pada_agregasi_upt(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(UnitDivisionSeeder::class);

        // Dynamic division resolution via slug 'managemen-aset' (dilarang hardcode ID)
        $divisionId = Division::where('slug', 'managemen-aset')->value('id');
        $this->assertNotNull($divisionId, 'Divisi managemen-aset harus berhasil di-resolve lewat slug.');

        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        // Buat SATU dataset READY untuk periode Oktober 2026
        $dataset = Dataset::factory()->create([
            'division_id' => $divisionId,
            'status' => Dataset::STATUS_READY,
        ]);

        $period = '2026-10-31';

        // 1. Masukkan metrik UPT kanonis ke dataset
        $uptSampleData = [
            'BANDUNG' => ['tower' => 800.0, 'transmisi' => 500.0, 'trafo' => 2500.0],
            'BEKASI' => ['tower' => 800.0, 'transmisi' => 500.0, 'trafo' => 2500.0],
            'BOGOR' => ['tower' => 800.0, 'transmisi' => 500.0, 'trafo' => 2500.0],
            'CIREBON' => ['tower' => 800.0, 'transmisi' => 500.0, 'trafo' => 2500.0],
            'KARAWANG' => ['tower' => 800.0, 'transmisi' => 500.0, 'trafo' => 2500.0],
            'PURWOKERTO' => ['tower' => 336.0, 'transmisi' => 213.5, 'trafo' => 1000.0],
            'SALATIGA' => ['tower' => 350.0, 'transmisi' => 250.0, 'trafo' => 1000.0],
            'SEMARANG' => ['tower' => 350.0, 'transmisi' => 250.0, 'trafo' => 1000.0],
        ];

        foreach ($uptSampleData as $upt => $vals) {
            PerformanceMetric::factory()->create([
                'dataset_id' => $dataset->id,
                'division_id' => $divisionId,
                'period' => $period,
                'metric_name' => "ASET | {$upt} | {$upt} | Tower 500 KV | SU",
                'value' => $vals['tower'],
            ]);
            PerformanceMetric::factory()->create([
                'dataset_id' => $dataset->id,
                'division_id' => $divisionId,
                'period' => $period,
                'metric_name' => "ASET | {$upt} | {$upt} | Transmisi 500 KV | SU (kms)",
                'value' => $vals['transmisi'],
            ]);
            PerformanceMetric::factory()->create([
                'dataset_id' => $dataset->id,
                'division_id' => $divisionId,
                'period' => $period,
                'metric_name' => "ASET | {$upt} | {$upt} | Trafo 500/150 KV | MVA",
                'value' => $vals['trafo'],
            ]);
        }

        // Siapkan pemanggilan asetUptChart pada DashboardController
        $controller = app(DashboardController::class);
        $chartMethod = new ReflectionMethod(DashboardController::class, 'asetUptChart');
        $chartMethod->setAccessible(true);

        // Rekam baseline SEBELUM injeksi UIT
        $reportBaseline = app(AssetStockReport::class)->totalsByUpt();
        $chartBaseline = $chartMethod->invoke($controller, $admin);

        // Verifikasi awal baseline
        $expectedTowerTotal = 800.0 * 5 + 336.0 + 350.0 * 2; // 4736.0
        $expectedTransmisiTotal = 500.0 * 5 + 213.5 + 250.0 * 2; // 3213.5
        $expectedTrafoTotal = 2500.0 * 5 + 1000.0 * 3; // 15500.0

        $this->assertEquals($expectedTowerTotal, $chartBaseline['summary']['total_tower']);
        $this->assertEquals($expectedTransmisiTotal, $chartBaseline['summary']['total_transmisi']);
        $this->assertEquals($expectedTrafoTotal, $chartBaseline['summary']['total_trafo']);
        $this->assertCount(8, $chartBaseline['labels']);
        $this->assertNotContains('UIT', $chartBaseline['labels']);

        // 2. INJEKSI metrik level UIT (ASET | UIT | ...) ke dalam dataset YANG SAMA
        $uitMetrics = [
            'ASET | UIT | Tower 500 KV | SU' => 6336.0,
            'ASET | UIT | Transmisi 500 KV | SU (kms)' => 4213.5,
            'ASET | UIT | Trafo 500/150 KV | MVA' => 20500.0,
            'ASET | UIT | Trafo 150/70 KV | MVA' => 5000.0,
        ];

        foreach ($uitMetrics as $mName => $mVal) {
            PerformanceMetric::factory()->create([
                'dataset_id' => $dataset->id,
                'division_id' => $divisionId,
                'period' => $period,
                'metric_name' => $mName,
                'value' => $mVal,
            ]);
        }

        // Rekam hasil SESUDAH injeksi UIT
        $reportAfter = app(AssetStockReport::class)->totalsByUpt();
        $chartAfter = $chartMethod->invoke($controller, $admin);

        // 3. CANARY ASSERTIONS: 0 DELTA MUTLAK

        // A. Totals by UPT report tidak berubah sama sekali
        $this->assertSame($reportBaseline['period'], $reportAfter['period']);
        $this->assertCount($reportBaseline['totals']->count(), $reportAfter['totals']);

        $baselineTotalsArray = $reportBaseline['totals']->map(
            fn ($t) => ['upt' => $t->upt, 'attribute' => $t->attribute, 'total' => $t->total]
        )->all();

        $afterTotalsArray = $reportAfter['totals']->map(
            fn ($t) => ['upt' => $t->upt, 'attribute' => $t->attribute, 'total' => $t->total]
        )->all();

        $this->assertSame($baselineTotalsArray, $afterTotalsArray, '0 delta pada AssetStockReport::totalsByUpt()');

        // B. Summary chart totals: agregasi UPT bernilai identik (0 delta), dan summary scorecard mengambil UIT setelah injeksi
        $uptSummaryBaseline = $chartBaseline['summary_upt'] ?? $chartBaseline['summary'];
        $uptSummaryAfter = $chartAfter['summary_upt'] ?? $chartAfter['summary'];

        $this->assertSame(
            $uptSummaryBaseline['total_tower'],
            $uptSummaryAfter['total_tower'],
            '0 delta pada summary total_tower'
        );
        $this->assertSame(
            $uptSummaryBaseline['total_transmisi'],
            $uptSummaryAfter['total_transmisi'],
            '0 delta pada summary total_transmisi'
        );
        $this->assertSame(
            $uptSummaryBaseline['total_trafo'],
            $uptSummaryAfter['total_trafo'],
            '0 delta pada summary total_trafo'
        );

        $this->assertSame(6336.0, $chartAfter['summary']['total_tower'], 'Summary total_tower beralih ke level UIT');
        $this->assertSame(4213.5, $chartAfter['summary']['total_transmisi'], 'Summary total_transmisi beralih ke level UIT');
        $this->assertSame(25500.0, $chartAfter['summary']['total_trafo'], 'Summary total_trafo beralih ke level UIT');

        // C. Labels UPT tidak ketambahan 'UIT' dan tetap 8 UPT kanonis
        $this->assertSame($chartBaseline['labels'], $chartAfter['labels'], 'Labels UPT tetap identik');
        $this->assertNotContains('UIT', $chartAfter['labels'], "'UIT' tidak boleh muncul sebagai label UPT.");

        // D. Table rows dan Series tetap identik
        $this->assertSame($chartBaseline['table_rows'], $chartAfter['table_rows'], 'Table rows tetap identik');
        $this->assertSame($chartBaseline['series'], $chartAfter['series'], 'Chart series tetap identik');

        // 4. Verifikasi bahwa totalsByUit() berhasil membaca metrik UIT yang diinjeksikan
        $uitReport = app(AssetStockReport::class)->totalsByUit();
        $this->assertSame($period, $uitReport['period']);
        $this->assertCount(4, $uitReport['totals']);

        $uitByName = $uitReport['totals']->keyBy('metric_name');
        $this->assertSame(6336.0, $uitByName->get('ASET | UIT | Tower 500 KV | SU')->total);
        $this->assertSame(4213.5, $uitByName->get('ASET | UIT | Transmisi 500 KV | SU (kms)')->total);
        $this->assertSame(20500.0, $uitByName->get('ASET | UIT | Trafo 500/150 KV | MVA')->total);
        $this->assertSame(5000.0, $uitByName->get('ASET | UIT | Trafo 150/70 KV | MVA')->total);
    }

    public function test_latest_wins_berlaku_atomis_untuk_kedua_level_dalam_satu_dataset(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(UnitDivisionSeeder::class);

        $divisionId = Division::where('slug', 'managemen-aset')->value('id');
        $period = '2026-10-31';

        // Dataset versi 1 (lama)
        $oldDataset = Dataset::factory()->create([
            'division_id' => $divisionId,
            'status' => Dataset::STATUS_READY,
            'created_at' => '2026-11-01 08:00:00',
        ]);
        PerformanceMetric::factory()->create([
            'dataset_id' => $oldDataset->id,
            'division_id' => $divisionId,
            'period' => $period,
            'metric_name' => 'ASET | BANDUNG | BANDUNG | Tower 500 KV | SU',
            'value' => 100.0,
        ]);
        PerformanceMetric::factory()->create([
            'dataset_id' => $oldDataset->id,
            'division_id' => $divisionId,
            'period' => $period,
            'metric_name' => 'ASET | UIT | Tower 500 KV | SU',
            'value' => 500.0,
        ]);

        // Dataset versi 2 (baru)
        $newDataset = Dataset::factory()->create([
            'division_id' => $divisionId,
            'status' => Dataset::STATUS_READY,
            'created_at' => '2026-11-02 08:00:00',
        ]);
        PerformanceMetric::factory()->create([
            'dataset_id' => $newDataset->id,
            'division_id' => $divisionId,
            'period' => $period,
            'metric_name' => 'ASET | BANDUNG | BANDUNG | Tower 500 KV | SU',
            'value' => 120.0,
        ]);
        PerformanceMetric::factory()->create([
            'dataset_id' => $newDataset->id,
            'division_id' => $divisionId,
            'period' => $period,
            'metric_name' => 'ASET | UIT | Tower 500 KV | SU',
            'value' => 520.0,
        ]);

        $report = app(AssetStockReport::class);

        // UPT level harus memakai dataset baru (120.0, bukan 100 + 120 = 220)
        $uptTotals = $report->totalsByUpt($period);
        $this->assertCount(1, $uptTotals['totals']);
        $this->assertSame(120.0, $uptTotals['totals'][0]->total);

        // UIT level harus memakai dataset baru (520.0, bukan 500 + 520 = 1020)
        $uitTotals = $report->totalsByUit($period);
        $this->assertCount(1, $uitTotals['totals']);
        $this->assertSame(520.0, $uitTotals['totals'][0]->total);
    }
}
