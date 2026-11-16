<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Division;
use App\Models\PerformanceMetric;
use App\Services\AssetStockReport;
use App\Services\KpiReportConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Query read-only snapshot Aset: level query/unit saja, tanpa assertion
 * terhadap halaman dashboard (visual belum diputuskan mentor).
 */
class AssetStockReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_total_per_upt_menjumlah_ultg_dalam_atribut_sama(): void
    {
        // Nilai sintetis 5 + 7 (bukan angka bisnis) — ULTG di-roll-up ke UPT.
        $this->seedUptRows('2026-01-31', [
            ['BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', 5],
            ['BEKASI', 'CIKARANG', 'Trafo 500/150 KV | Bank', 7],
        ]);

        $report = app(AssetStockReport::class)->totalsByUpt();

        $this->assertSame('2026-01-31', $report['period']);
        $this->assertSame([
            ['upt' => 'BEKASI', 'attribute' => 'Trafo 500/150 KV | Bank', 'total' => 12.0],
        ], $report['totals']->map(fn ($t) => ['upt' => $t->upt, 'attribute' => $t->attribute, 'total' => $t->total])->all());
    }

    public function test_total_per_upt_memisahkan_upt_dan_atribut(): void
    {
        $this->seedUptRows('2026-01-31', [
            ['BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', 5],
            ['BOGOR', 'BOGOR', 'Trafo 500/150 KV | Bank', 3],
            ['BEKASI', 'BEKASI', 'Trafo 500/150 KV | MVA', 100],
        ]);

        $totals = app(AssetStockReport::class)->totalsByUpt()['totals'];

        $this->assertSame([
            ['upt' => 'BEKASI', 'attribute' => 'Trafo 500/150 KV | Bank', 'total' => 5.0],
            ['upt' => 'BEKASI', 'attribute' => 'Trafo 500/150 KV | MVA', 'total' => 100.0],
            ['upt' => 'BOGOR', 'attribute' => 'Trafo 500/150 KV | Bank', 'total' => 3.0],
        ], $totals->map(fn ($t) => ['upt' => $t->upt, 'attribute' => $t->attribute, 'total' => $t->total])->all());
    }

    public function test_total_per_upt_hanya_memakai_periode_terbaru_dan_mengabaikan_non_aset(): void
    {
        $this->seedUptRows('2026-01-31', [
            ['BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', 99],
        ]);
        $this->seedUptRows('2026-02-28', [
            ['BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', 5],
            ['BEKASI', 'BEKASI', 'Trafo 500/150 KV | MVA', 0],
        ]);

        $division = Division::factory()->create();
        $dataset = Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_READY,
        ]);
        PerformanceMetric::factory()->create([
            'metric_name' => 'RST'.KpiReportConverter::NKO_SUFFIX,
            'dataset_id' => $dataset->id,
            'division_id' => $division->id,
            'period' => '2026-02-28',
            'value' => 100,
        ]);

        $report = app(AssetStockReport::class)->totalsByUpt();

        // Periode lama (99) dan baris NKO tidak ikut; nol dipertahankan.
        $this->assertSame('2026-02-28', $report['period']);
        $this->assertSame([
            ['upt' => 'BEKASI', 'attribute' => 'Trafo 500/150 KV | Bank', 'total' => 5.0],
            ['upt' => 'BEKASI', 'attribute' => 'Trafo 500/150 KV | MVA', 'total' => 0.0],
        ], $report['totals']->map(fn ($t) => ['upt' => $t->upt, 'attribute' => $t->attribute, 'total' => $t->total])->all());
    }

    public function test_total_per_upt_kosong_tanpa_baris_aset(): void
    {
        $report = app(AssetStockReport::class)->totalsByUpt();

        $this->assertNull($report['period']);
        $this->assertTrue($report['totals']->isEmpty());
    }

    public function test_total_per_upt_periode_terbaru_dalam_satu_dataset_mengabaikan_periode_lama(): void
    {
        $division = Division::factory()->create();
        $dataset = $this->readyDataset($division, '2026-11-01 08:00:00');

        $this->assetRow($dataset, 'BANDUNG', 'BANDUNG BARAT', 'Trafo 500/150 KV | Bank', '2026-01-31', 2);
        $this->assetRow($dataset, 'BANDUNG', 'BANDUNG BARAT', 'Trafo 500/150 KV | MVA', '2026-01-31', 100.5);
        $this->assetRow($dataset, 'BANDUNG', 'BANDUNG SELATAN', 'Trafo 500/150 KV | Bank', '2026-01-31', 0);
        $this->assetRow($dataset, 'BANDUNG', 'BANDUNG BARAT', 'Trafo 500/150 KV | Bank', '2026-02-28', 3);

        $report = app(AssetStockReport::class)->totalsByUpt();

        $this->assertSame('2026-02-28', $report['period']);
        $this->assertCount(1, $report['totals']);
        $this->assertSame([
            ['upt' => 'BANDUNG', 'attribute' => 'Trafo 500/150 KV | Bank', 'total' => 3.0],
        ], $this->totalsAsArray($report));
    }

    public function test_total_per_upt_mengabaikan_baris_non_aset_dan_upt_lain_di_dataset_non_ready(): void
    {
        $division = Division::factory()->create();
        $ready = $this->readyDataset($division, '2026-11-01 08:00:00');
        $failed = Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_FAILED,
            'created_at' => '2026-11-01 09:00:00',
        ]);

        PerformanceMetric::factory()->create([
            'metric_name' => 'RST'.KpiReportConverter::NKO_SUFFIX,
            'dataset_id' => $ready->id,
            'division_id' => $division->id,
            'period' => '2026-01-31',
            'value' => 100,
        ]);
        $this->assetRow($failed, 'BOGOR', 'BOGOR', 'Trafo 500/150 KV | Bank', '2026-01-31', 9);
        $this->assetRow($ready, 'BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', '2026-01-31', 4);

        $report = app(AssetStockReport::class)->totalsByUpt();

        $this->assertSame('2026-01-31', $report['period']);
        $this->assertCount(1, $report['totals']);
        $this->assertSame([
            ['upt' => 'BEKASI', 'attribute' => 'Trafo 500/150 KV | Bank', 'total' => 4.0],
        ], $this->totalsAsArray($report));
    }

    public function test_total_per_upt_baris_bernilai_nol_tetap_muncul_saat_hanya_ada_baris_nol(): void
    {
        // Nol adalah data (belum ada realisasi atau cached zero), bukan null/absen.
        $division = Division::factory()->create();
        $dataset = $this->readyDataset($division, '2026-11-01 08:00:00');

        $this->assetRow($dataset, 'BANDUNG', 'BANDUNG BARAT', 'Trafo 500/150 KV | Bank', '2026-01-31', 0);

        $report = app(AssetStockReport::class)->totalsByUpt();

        $this->assertSame('2026-01-31', $report['period']);
        $this->assertCount(1, $report['totals']);
        $this->assertSame([
            ['upt' => 'BANDUNG', 'attribute' => 'Trafo 500/150 KV | Bank', 'total' => 0.0],
        ], $this->totalsAsArray($report));
    }

    public function test_total_per_upt_memakai_dataset_ready_terbaru_pada_periode_dan_divisi_sama(): void
    {
        // Satu dataset = satu versi. Dua dataset READY pada divisi + periode
        // yang sama TIDAK boleh dijumlahkan: yang terbaru menggantikan yang
        // lama (cegah double aggregation saat staf mengunggah ulang).
        // Nilai sintetis 5 (lama) dan 7 (baru), bukan angka bisnis.
        $division = Division::factory()->create();
        $old = $this->readyDataset($division, '2026-11-01 08:00:00');
        $new = $this->readyDataset($division, '2026-11-02 08:00:00');

        $this->assetRow($old, 'BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', '2026-01-31', 5);
        $this->assetRow($new, 'BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', '2026-01-31', 7);

        $report = app(AssetStockReport::class)->totalsByUpt();

        $this->assertSame('2026-01-31', $report['period']);
        $this->assertSame([
            ['upt' => 'BEKASI', 'attribute' => 'Trafo 500/150 KV | Bank', 'total' => 7.0],
        ], $this->totalsAsArray($report));
    }

    public function test_total_per_upt_urutan_pembuatan_bukan_urutan_insert_yang_menentukan_terbaru(): void
    {
        $division = Division::factory()->create();
        $new = $this->readyDataset($division, '2026-11-02 08:00:00');
        $old = $this->readyDataset($division, '2026-11-01 08:00:00');

        // Baris dataset baru di-insert lebih dulu dari dataset lama.
        $this->assetRow($new, 'BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', '2026-01-31', 7);
        $this->assetRow($old, 'BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', '2026-01-31', 5);

        $this->assertSame([
            ['upt' => 'BEKASI', 'attribute' => 'Trafo 500/150 KV | Bank', 'total' => 7.0],
        ], $this->totalsAsArray(app(AssetStockReport::class)->totalsByUpt()));
    }

    public function test_total_per_upt_created_at_sama_dipecahkan_oleh_id_terbesar(): void
    {
        $division = Division::factory()->create();
        $first = $this->readyDataset($division, '2026-11-01 08:00:00');
        $second = $this->readyDataset($division, '2026-11-01 08:00:00');

        $this->assetRow($first, 'BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', '2026-01-31', 5);
        $this->assetRow($second, 'BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', '2026-01-31', 7);

        $this->assertGreaterThan($first->id, $second->id);
        $this->assertSame([
            ['upt' => 'BEKASI', 'attribute' => 'Trafo 500/150 KV | Bank', 'total' => 7.0],
        ], $this->totalsAsArray(app(AssetStockReport::class)->totalsByUpt()));
    }

    public function test_total_per_upt_dataset_lama_tidak_ikut_meski_barisnya_berbeda_atribut(): void
    {
        // Dataset terbaru adalah versi lengkap: atribut yang hanya ada di
        // dataset lama tidak boleh "bocor" ke total.
        $division = Division::factory()->create();
        $old = $this->readyDataset($division, '2026-11-01 08:00:00');
        $new = $this->readyDataset($division, '2026-11-02 08:00:00');

        $this->assetRow($old, 'BEKASI', 'BEKASI', 'Trafo 500/150 KV | MVA', '2026-01-31', 100);
        $this->assetRow($old, 'BOGOR', 'BOGOR', 'Trafo 500/150 KV | Bank', '2026-01-31', 3);
        $this->assetRow($new, 'BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', '2026-01-31', 7);

        $this->assertSame([
            ['upt' => 'BEKASI', 'attribute' => 'Trafo 500/150 KV | Bank', 'total' => 7.0],
        ], $this->totalsAsArray(app(AssetStockReport::class)->totalsByUpt()));
    }

    public function test_total_per_upt_tetap_menjumlahkan_ultg_dalam_satu_dataset_terbaru(): void
    {
        $division = Division::factory()->create();
        $old = $this->readyDataset($division, '2026-11-01 08:00:00');
        $new = $this->readyDataset($division, '2026-11-02 08:00:00');

        $this->assetRow($old, 'BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', '2026-01-31', 99);
        $this->assetRow($new, 'BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', '2026-01-31', 5);
        $this->assetRow($new, 'BEKASI', 'CIKARANG', 'Trafo 500/150 KV | Bank', '2026-01-31', 7);

        $this->assertSame([
            ['upt' => 'BEKASI', 'attribute' => 'Trafo 500/150 KV | Bank', 'total' => 12.0],
        ], $this->totalsAsArray(app(AssetStockReport::class)->totalsByUpt()));
    }

    public function test_total_per_upt_dataset_failed_lebih_baru_tidak_menggantikan_dataset_ready(): void
    {
        $division = Division::factory()->create();
        $ready = $this->readyDataset($division, '2026-11-01 08:00:00');
        $failed = Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_FAILED,
            'created_at' => '2026-11-02 08:00:00',
        ]);

        $this->assetRow($ready, 'BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', '2026-01-31', 5);
        $this->assetRow($failed, 'BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', '2026-01-31', 9);

        $this->assertSame([
            ['upt' => 'BEKASI', 'attribute' => 'Trafo 500/150 KV | Bank', 'total' => 5.0],
        ], $this->totalsAsArray(app(AssetStockReport::class)->totalsByUpt()));
    }

    public function test_total_per_upt_dataset_lebih_baru_tanpa_baris_aset_tidak_menggantikan(): void
    {
        // Dataset terbaru milik divisi yang sama tetapi hanya berisi metrik
        // non-ASET pada periode itu bukan versi data Aset.
        $division = Division::factory()->create();
        $aset = $this->readyDataset($division, '2026-11-01 08:00:00');
        $kpi = $this->readyDataset($division, '2026-11-02 08:00:00');

        $this->assetRow($aset, 'BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', '2026-01-31', 5);
        PerformanceMetric::factory()->create([
            'metric_name' => 'RST'.KpiReportConverter::NKO_SUFFIX,
            'dataset_id' => $kpi->id,
            'division_id' => $division->id,
            'period' => '2026-01-31',
            'value' => 100,
        ]);

        $this->assertSame([
            ['upt' => 'BEKASI', 'attribute' => 'Trafo 500/150 KV | Bank', 'total' => 5.0],
        ], $this->totalsAsArray(app(AssetStockReport::class)->totalsByUpt()));
    }

    public function test_total_per_upt_lintas_divisi_memakai_dataset_terbaru_per_divisi(): void
    {
        $divisionA = Division::factory()->create();
        $divisionB = Division::factory()->create();
        $oldA = $this->readyDataset($divisionA, '2026-11-01 08:00:00');
        $newA = $this->readyDataset($divisionA, '2026-11-02 08:00:00');
        $onlyB = $this->readyDataset($divisionB, '2026-10-01 08:00:00');

        $this->assetRow($oldA, 'BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', '2026-01-31', 5);
        $this->assetRow($newA, 'BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', '2026-01-31', 7);
        // Dataset divisi B lebih tua secara waktu, tetapi tidak bersaing
        // dengan divisi A: scope "terbaru" berlaku per divisi.
        $this->assetRow($onlyB, 'BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', '2026-01-31', 10);

        $this->assertSame([
            ['upt' => 'BEKASI', 'attribute' => 'Trafo 500/150 KV | Bank', 'total' => 17.0],
        ], $this->totalsAsArray(app(AssetStockReport::class)->totalsByUpt()));

        $this->assertSame([
            ['upt' => 'BEKASI', 'attribute' => 'Trafo 500/150 KV | Bank', 'total' => 7.0],
        ], $this->totalsAsArray(app(AssetStockReport::class)->totalsByUpt(divisionId: $divisionA->id)));
    }

    public function test_total_per_upt_periode_eksplisit_memakai_dataset_terbaru_pada_periode_itu(): void
    {
        $division = Division::factory()->create();
        $old = $this->readyDataset($division, '2026-11-01 08:00:00');
        $new = $this->readyDataset($division, '2026-11-02 08:00:00');

        // Januari hanya ada di dataset lama, Februari di keduanya.
        $this->assetRow($old, 'BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', '2026-01-31', 5);
        $this->assetRow($old, 'BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', '2026-02-28', 6);
        $this->assetRow($new, 'BEKASI', 'BEKASI', 'Trafo 500/150 KV | Bank', '2026-02-28', 8);

        $report = app(AssetStockReport::class);

        $this->assertSame([
            ['upt' => 'BEKASI', 'attribute' => 'Trafo 500/150 KV | Bank', 'total' => 5.0],
        ], $this->totalsAsArray($report->totalsByUpt('2026-01-31')));
        $this->assertSame([
            ['upt' => 'BEKASI', 'attribute' => 'Trafo 500/150 KV | Bank', 'total' => 8.0],
        ], $this->totalsAsArray($report->totalsByUpt()));
    }

    public function test_total_per_upt_scope_versi_mengikuti_divisi_dataset_bukan_divisi_baris(): void
    {
        // Baris metrik boleh tanpa division_id (kolom nullable). Unit versi
        // adalah dataset, yang selalu punya divisi, jadi baris tanpa divisi
        // pada dataset lama tetap tersupersede dataset baru.
        $division = Division::factory()->create();
        $old = $this->readyDataset($division, '2026-11-01 08:00:00');
        $new = $this->readyDataset($division, '2026-11-02 08:00:00');

        foreach ([[$old, 5], [$new, 7]] as [$dataset, $value]) {
            PerformanceMetric::factory()->create([
                'metric_name' => 'ASET | BEKASI | BEKASI | Trafo 500/150 KV | Bank',
                'dataset_id' => $dataset->id,
                'division_id' => null,
                'period' => '2026-01-31',
                'value' => $value,
            ]);
        }

        $this->assertSame([
            ['upt' => 'BEKASI', 'attribute' => 'Trafo 500/150 KV | Bank', 'total' => 7.0],
        ], $this->totalsAsArray(app(AssetStockReport::class)->totalsByUpt()));
    }

    public function test_totals_by_upt_mengecualikan_baris_berprefix_uit_dan_upt_non_kanonis(): void
    {
        $division = Division::factory()->create();
        $dataset = Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_READY,
        ]);

        // Baris UPT kanonis valid
        PerformanceMetric::factory()->create([
            'metric_name' => 'ASET | BEKASI | BEKASI | Trafo 500/150 KV | Bank',
            'dataset_id' => $dataset->id,
            'division_id' => $division->id,
            'period' => '2026-10-31',
            'value' => 5,
        ]);

        // Baris level UIT (harus dikecualikan dari totalsByUpt)
        PerformanceMetric::factory()->create([
            'metric_name' => 'ASET | UIT | Trafo 500/150 KV | Bank',
            'dataset_id' => $dataset->id,
            'division_id' => $division->id,
            'period' => '2026-10-31',
            'value' => 123,
        ]);

        // Baris UPT non-kanonis (harus dikecualikan dari totalsByUpt)
        PerformanceMetric::factory()->create([
            'metric_name' => 'ASET | JAKARTA | GAMBIR | Trafo 500/150 KV | Bank',
            'dataset_id' => $dataset->id,
            'division_id' => $division->id,
            'period' => '2026-10-31',
            'value' => 99,
        ]);

        $report = app(AssetStockReport::class)->totalsByUpt();

        $this->assertSame('2026-10-31', $report['period']);
        $this->assertCount(1, $report['totals']);
        $this->assertSame('BEKASI', $report['totals'][0]->upt);
        $this->assertSame(5.0, $report['totals'][0]->total);
    }

    public function test_totals_by_uit_mengagregasi_metrik_level_uit(): void
    {
        $division = Division::factory()->create();
        $dataset = Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_READY,
        ]);

        // Baris UPT kanonis
        PerformanceMetric::factory()->create([
            'metric_name' => 'ASET | BEKASI | BEKASI | Trafo 500/150 KV | Bank',
            'dataset_id' => $dataset->id,
            'division_id' => $division->id,
            'period' => '2026-10-31',
            'value' => 5,
        ]);

        // Baris level UIT
        PerformanceMetric::factory()->create([
            'metric_name' => 'ASET | UIT | Trafo 500/150 KV | MVA',
            'dataset_id' => $dataset->id,
            'division_id' => $division->id,
            'period' => '2026-10-31',
            'value' => 20500.0,
        ]);
        PerformanceMetric::factory()->create([
            'metric_name' => 'ASET | UIT | Tower 500 KV | SU',
            'dataset_id' => $dataset->id,
            'division_id' => $division->id,
            'period' => '2026-10-31',
            'value' => 6336.0,
        ]);

        $report = app(AssetStockReport::class)->totalsByUit();

        $this->assertSame('2026-10-31', $report['period']);
        $this->assertCount(2, $report['totals']);

        $byName = $report['totals']->keyBy('metric_name');
        $this->assertTrue($byName->has('ASET | UIT | Trafo 500/150 KV | MVA'));
        $this->assertSame(20500.0, $byName->get('ASET | UIT | Trafo 500/150 KV | MVA')->total);
        $this->assertSame('Trafo 500/150 KV', $byName->get('ASET | UIT | Trafo 500/150 KV | MVA')->kelompok);
        $this->assertSame('MVA', $byName->get('ASET | UIT | Trafo 500/150 KV | MVA')->satuan);

        $this->assertTrue($byName->has('ASET | UIT | Tower 500 KV | SU'));
        $this->assertSame(6336.0, $byName->get('ASET | UIT | Tower 500 KV | SU')->total);
    }

    private function readyDataset(Division $division, string $createdAt): Dataset
    {
        return Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_READY,
            'created_at' => $createdAt,
        ]);
    }

    private function assetRow(Dataset $dataset, string $upt, string $ultg, string $attribute, string $period, float $value): void
    {
        PerformanceMetric::factory()->create([
            'metric_name' => "ASET | {$upt} | {$ultg} | {$attribute}",
            'dataset_id' => $dataset->id,
            'division_id' => $dataset->division_id,
            'period' => $period,
            'value' => $value,
        ]);
    }

    /**
     * @param  array{period: ?string, totals: Collection<int, object>}  $report
     * @return list<array{upt: string, attribute: string, total: float}>
     */
    private function totalsAsArray(array $report): array
    {
        return $report['totals']
            ->map(fn ($t) => ['upt' => $t->upt, 'attribute' => $t->attribute, 'total' => $t->total])
            ->all();
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: float}>  $rows
     */
    private function seedUptRows(string $period, array $rows): void
    {
        $division = Division::factory()->create();
        $dataset = Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_READY,
        ]);

        foreach ($rows as [$upt, $ultg, $attribute, $value]) {
            PerformanceMetric::factory()->create([
                'metric_name' => "ASET | {$upt} | {$ultg} | {$attribute}",
                'dataset_id' => $dataset->id,
                'division_id' => $division->id,
                'period' => $period,
                'value' => $value,
            ]);
        }
    }
}
