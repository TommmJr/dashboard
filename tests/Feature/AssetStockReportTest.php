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

    public function test_available_periods_mengembalikan_periode_ready_aset_terurut_menurun_dan_terfilter_divisi(): void
    {
        $report = app(AssetStockReport::class);
        $this->assertSame([], $report->availablePeriods());

        $divA = Division::factory()->create();
        $divB = Division::factory()->create();

        $readyA = $this->readyDataset($divA, '2026-11-01 08:00:00');
        $readyA2 = $this->readyDataset($divA, '2026-11-02 08:00:00');
        $failedA = Dataset::factory()->create([
            'division_id' => $divA->id,
            'status' => Dataset::STATUS_FAILED,
            'created_at' => '2026-11-03 08:00:00',
        ]);
        $readyB = $this->readyDataset($divB, '2026-11-04 08:00:00');

        $this->assetRow($readyA, 'BANDUNG', 'BANDUNG BARAT', 'Tower 500 KV | SU', '2026-01-31', 10);
        $this->assetRow($readyA, 'BANDUNG', 'BANDUNG BARAT', 'Tower 500 KV | SU', '2026-03-31', 12);
        $this->assetRow($readyA2, 'BEKASI', 'BEKASI', 'Tower 500 KV | SU', '2026-02-28', 15);
        $this->assetRow($readyA2, 'BEKASI', 'BEKASI', 'Tower 500 KV | SU', '2026-03-31', 14);
        $this->assetRow($failedA, 'BANDUNG', 'BANDUNG BARAT', 'Tower 500 KV | SU', '2026-04-30', 99);
        $this->assetRow($readyB, 'BOGOR', 'BOGOR', 'Tower 500 KV | SU', '2026-06-30', 20);

        PerformanceMetric::factory()->create([
            'metric_name' => 'RST'.KpiReportConverter::NKO_SUFFIX,
            'dataset_id' => $readyA->id,
            'division_id' => $divA->id,
            'period' => '2026-05-31',
            'value' => 100,
        ]);

        $this->assertSame(['2026-06-30', '2026-03-31', '2026-02-28', '2026-01-31'], $report->availablePeriods());
        $this->assertSame(['2026-03-31', '2026-02-28', '2026-01-31'], $report->availablePeriods($divA->id));
        $this->assertSame(['2026-06-30'], $report->availablePeriods($divB->id));
    }

    public function test_resolve_requested_period_memvalidasi_format_dan_fallback_ke_periode_terbaru(): void
    {
        $report = app(AssetStockReport::class);
        $this->assertNull($report->resolveRequestedPeriod('2026-01-31'));

        $divA = Division::factory()->create();
        $divB = Division::factory()->create();
        $dsA = $this->readyDataset($divA, '2026-11-01 08:00:00');
        $dsB = $this->readyDataset($divB, '2026-11-02 08:00:00');

        $this->assetRow($dsA, 'BANDUNG', 'BANDUNG BARAT', 'Tower 500 KV | SU', '2026-01-31', 10);
        $this->assetRow($dsA, 'BANDUNG', 'BANDUNG BARAT', 'Tower 500 KV | SU', '2026-03-31', 12);
        $this->assetRow($dsB, 'BOGOR', 'BOGOR', 'Tower 500 KV | SU', '2026-06-30', 20);

        $this->assertSame('2026-01-31', $report->resolveRequestedPeriod('2026-01-31', $divA->id));
        $this->assertSame('2026-03-31', $report->resolveRequestedPeriod(null, $divA->id));
        $this->assertSame('2026-03-31', $report->resolveRequestedPeriod('invalid-date', $divA->id));
        $this->assertSame('2026-03-31', $report->resolveRequestedPeriod('2025-12-31', $divA->id));
        $this->assertSame('2026-03-31', $report->resolveRequestedPeriod('2026-06-30', $divA->id));
        $this->assertSame('2026-06-30', $report->resolveRequestedPeriod('2026-06-30'));
    }

    public function test_totals_by_ultg_mengelompokkan_per_upt_dan_ultg_serta_membedakan_nol_dan_null(): void
    {
        $report = app(AssetStockReport::class);

        $empty = $report->totalsByUltg();
        $this->assertNull($empty['period']);
        $this->assertTrue($empty['totals']->isEmpty());
        $this->assertSame(AssetStockReport::CANONICAL_UPTS, array_keys($empty['by_upt']));
        $this->assertSame([], $empty['by_upt']['BANDUNG']);

        $division = Division::factory()->create();
        $old = $this->readyDataset($division, '2026-11-01 08:00:00');
        $new = $this->readyDataset($division, '2026-11-02 08:00:00');

        // Dataset lama harus ter-supersede.
        $this->assetRow($old, 'BANDUNG', 'BANDUNG SELATAN', 'Tower 500 KV | SU', '2026-02-28', 999);

        // Dataset baru: BANDUNG SELATAN hanya punya Tower 0.0, BANDUNG BARAT punya semua kategori.
        $this->assetRow($new, 'BANDUNG', 'BANDUNG SELATAN', 'Tower 500 KV | SU', '2026-02-28', 0.0);
        $this->assetRow($new, 'BANDUNG', 'BANDUNG BARAT', 'Tower 500 KV | SU', '2026-02-28', 12.0);
        $this->assetRow($new, 'BANDUNG', 'BANDUNG BARAT', 'Transmisi 150 KV | SU | kms', '2026-02-28', 0.0);
        $this->assetRow($new, 'BANDUNG', 'BANDUNG BARAT', 'Trafo 500/150 KV | MVA', '2026-02-28', 1000.0);
        $this->assetRow($new, 'BANDUNG', 'BANDUNG BARAT', 'Trafo 500/150 KV | Bank', '2026-02-28', 6.0);
        $this->assetRow($new, 'BANDUNG', 'BANDUNG BARAT', 'Trafo 500/150 KV | Unit/Set', '2026-02-28', 2.0);

        PerformanceMetric::factory()->create([
            'metric_name' => 'ASET | UIT | Tower 500 KV | SU',
            'dataset_id' => $new->id,
            'division_id' => $division->id,
            'period' => '2026-02-28',
            'value' => 500,
        ]);

        $result = $report->totalsByUltg('2026-02-28', $division->id);

        $this->assertSame('2026-02-28', $result['period']);
        $this->assertCount(2, $result['by_upt']['BANDUNG']);
        $this->assertSame([], $result['by_upt']['BEKASI']);

        // Urutan alfabetis ULTG: BANDUNG BARAT lalu BANDUNG SELATAN.
        $barat = $result['by_upt']['BANDUNG'][0];
        $this->assertSame('BANDUNG BARAT', $barat['ultg']);
        $this->assertSame(12.0, $barat['tower']);
        $this->assertSame(0.0, $barat['transmisi']);
        $this->assertSame(1000.0, $barat['trafo_mva']);
        $this->assertSame(6.0, $barat['trafo_bank']);
        $this->assertSame(2.0, $barat['trafo_unit']);
        $this->assertSame([
            'Tower 500 KV | SU' => 12.0,
            'Trafo 500/150 KV | Bank' => 6.0,
            'Trafo 500/150 KV | MVA' => 1000.0,
            'Trafo 500/150 KV | Unit/Set' => 2.0,
            'Transmisi 150 KV | SU | kms' => 0.0,
        ], $barat['attributes']);

        $selatan = $result['by_upt']['BANDUNG'][1];
        $this->assertSame('BANDUNG SELATAN', $selatan['ultg']);
        $this->assertSame(0.0, $selatan['tower']);
        $this->assertNull($selatan['transmisi']);
        $this->assertNull($selatan['trafo_mva']);
        $this->assertNull($selatan['trafo_bank']);
        $this->assertNull($selatan['trafo_unit']);
    }

    public function test_capacity_ratios_menghitung_rasio_trafo_dan_proporsi_transmisi_serta_menjaga_null_dan_pembagian_nol(): void
    {
        $report = app(AssetStockReport::class);

        $empty = $report->capacityRatios();
        $this->assertNull($empty['trafo']['overall']['mva']);
        $this->assertNull($empty['trafo']['overall']['avg_mva_per_unit']);
        $this->assertNull($empty['trafo']['overall']['avg_mva_per_bank']);
        $this->assertNull($empty['transmisi']['overall']['su_kms']);
        $this->assertNull($empty['transmisi']['overall']['sk_kms']);
        $this->assertNull($empty['transmisi']['overall']['total_kms']);
        $this->assertNull($empty['transmisi']['overall']['su_pct']);
        $this->assertNull($empty['transmisi']['overall']['sk_pct']);

        $division = Division::factory()->create();
        $dataset = $this->readyDataset($division, '2026-11-01 08:00:00');
        $period = '2026-08-31';

        // Tab 1b
        $this->assetRow($dataset, 'BANDUNG', 'BANDUNG BARAT', 'Trafo 500/150 KV | MVA', $period, 1000.0);
        $this->assetRow($dataset, 'BANDUNG', 'BANDUNG BARAT', 'Trafo 500/150 KV | Bank', $period, 6.0);
        $this->assetRow($dataset, 'BANDUNG', 'BANDUNG BARAT', 'Trafo 500/150 KV | Unit/Set', $period, 2.0);
        $this->assetRow($dataset, 'BANDUNG', 'BANDUNG BARAT', 'Transmisi 150 KV | SU | kms', $period, 300.0);
        $this->assetRow($dataset, 'BANDUNG', 'BANDUNG BARAT', 'Transmisi 150 KV | SK | kms', $period, 100.0);

        // BEKASI: pembagi Unit = 0 dan transmisi eksplisit 0.0 -> tidak error, ratio = null, 0.0 terjaga.
        $this->assetRow($dataset, 'BEKASI', 'BEKASI', 'Trafo 500/150 KV | MVA', $period, 500.0);
        $this->assetRow($dataset, 'BEKASI', 'BEKASI', 'Trafo 500/150 KV | Unit/Set', $period, 0.0);
        $this->assetRow($dataset, 'BEKASI', 'BEKASI', 'Transmisi 150 KV | SU | kms', $period, 0.0);
        $this->assetRow($dataset, 'BEKASI', 'BEKASI', 'Transmisi 150 KV | SK | kms', $period, 0.0);

        // Tab 1a (authoritative UIT, termasuk format "Transmisi 150 KV | SK (kms)")
        foreach ([
            ['Trafo 500/150 KV | MVA', 20500.0],
            ['Trafo 500/150 KV | Bank', 123.0],
            ['Trafo 500/150 KV | Unit/Set', 41.0],
            ['Transmisi 150 KV | SU (kms)', 1500.0],
            ['Transmisi 150 KV | SK (kms)', 500.0],
        ] as [$attr, $val]) {
            PerformanceMetric::factory()->create([
                'metric_name' => "ASET | UIT | {$attr}",
                'dataset_id' => $dataset->id,
                'division_id' => $division->id,
                'period' => $period,
                'value' => $val,
            ]);
        }

        $ratios = $report->capacityRatios($period, $division->id);

        $this->assertSame([
            'mva' => 20500.0,
            'unit' => 41.0,
            'bank' => 123.0,
            'avg_mva_per_unit' => 500.0,
            'avg_mva_per_bank' => 166.7,
        ], $ratios['trafo']['overall']);

        $this->assertSame([
            'mva' => 1000.0,
            'unit' => 2.0,
            'bank' => 6.0,
            'avg_mva_per_unit' => 500.0,
        ], $ratios['trafo']['by_upt']['BANDUNG']);

        $this->assertSame([
            'mva' => 500.0,
            'unit' => 0.0,
            'bank' => null,
            'avg_mva_per_unit' => null,
        ], $ratios['trafo']['by_upt']['BEKASI']);

        $this->assertSame([
            'mva' => null,
            'unit' => null,
            'bank' => null,
            'avg_mva_per_unit' => null,
        ], $ratios['trafo']['by_upt']['BOGOR']);

        $this->assertSame([
            'su_kms' => 1500.0,
            'sk_kms' => 500.0,
            'total_kms' => 2000.0,
            'su_pct' => 75.0,
            'sk_pct' => 25.0,
        ], $ratios['transmisi']['overall']);

        $this->assertSame([
            'su_kms' => 300.0,
            'sk_kms' => 100.0,
            'total_kms' => 400.0,
            'su_pct' => 75.0,
            'sk_pct' => 25.0,
        ], $ratios['transmisi']['by_upt']['BANDUNG']);

        $this->assertSame([
            'su_kms' => 0.0,
            'sk_kms' => 0.0,
            'total_kms' => 0.0,
            'su_pct' => null,
            'sk_pct' => null,
        ], $ratios['transmisi']['by_upt']['BEKASI']);

        $this->assertSame([
            'su_kms' => null,
            'sk_kms' => null,
            'total_kms' => null,
            'su_pct' => null,
            'sk_pct' => null,
        ], $ratios['transmisi']['by_upt']['BOGOR']);
    }

    public function test_detailed_breakdown_menyertakan_available_periods_ultg_dan_capacity_ratios_dengan_semantik_null_vs_nol(): void
    {
        $division = Division::factory()->create();
        $dataset = $this->readyDataset($division, '2026-11-01 08:00:00');

        $this->assetRow($dataset, 'BANDUNG', 'BANDUNG BARAT', 'Trafo 500/150 KV | MVA', '2026-01-31', 1000.0);
        $this->assetRow($dataset, 'BANDUNG', 'BANDUNG BARAT', 'Trafo 500/150 KV | Bank', '2026-01-31', 6.0);
        $this->assetRow($dataset, 'BANDUNG', 'BANDUNG BARAT', 'Trafo 500/150 KV | Unit/Set', '2026-01-31', 2.0);
        $this->assetRow($dataset, 'BEKASI', 'BEKASI', 'Trafo 500/150 KV | MVA', '2026-01-31', 0.0);
        $this->assetRow($dataset, 'BANDUNG', 'BANDUNG BARAT', 'Transmisi 150 KV | SU | kms', '2026-01-31', 300.0);
        $this->assetRow($dataset, 'BANDUNG', 'BANDUNG BARAT', 'Transmisi 150 KV | SK | kms', '2026-01-31', 100.0);
        $this->assetRow($dataset, 'BEKASI', 'BEKASI', 'Tower 500 KV | SU', '2026-01-31', 0.0);
        $this->assetRow($dataset, 'BANDUNG', 'BANDUNG BARAT', 'BAY 500 KV', '2026-01-31', 0.0);

        $this->assetRow($dataset, 'BANDUNG', 'BANDUNG BARAT', 'Trafo 500/150 KV | MVA', '2026-02-28', 1500.0);

        $breakdown = app(AssetStockReport::class)->detailedBreakdown('2026-01-31', $division->id);

        $this->assertSame('2026-01-31', $breakdown['period']);
        $this->assertSame(['2026-02-28', '2026-01-31'], $breakdown['available_periods']);
        $this->assertArrayHasKey('ultg_by_upt', $breakdown);
        $this->assertArrayHasKey('capacity_ratios', $breakdown);
        $this->assertCount(1, $breakdown['ultg_by_upt']['BANDUNG']);

        $trafo500 = $breakdown['trafo_groups'][0];
        $this->assertSame(500.0, $trafo500['avg_mva_per_unit']);
        $this->assertSame(166.7, $trafo500['avg_mva_per_bank']);
        $this->assertSame(500.0, $trafo500['by_upt']['BANDUNG']['avg_mva_per_unit']);
        $this->assertSame(0.0, $trafo500['by_upt']['BEKASI']['mva']);
        $this->assertNull($trafo500['by_upt']['BEKASI']['unit']);
        $this->assertNull($trafo500['by_upt']['BEKASI']['avg_mva_per_unit']);
        $this->assertNull($trafo500['by_upt']['BOGOR']['mva']);
        $this->assertSame(1000.0, $trafo500['total_upt']['mva']);

        $transmisi150 = collect($breakdown['transmisi_groups'])->firstWhere('voltage', '150 kV');
        $this->assertNotNull($transmisi150);
        $this->assertSame(75.0, $transmisi150['su_pct']);
        $this->assertSame(25.0, $transmisi150['sk_pct']);
        $this->assertSame(75.0, $transmisi150['by_upt']['BANDUNG']['su_pct']);
        $this->assertSame(25.0, $transmisi150['by_upt']['BANDUNG']['sk_pct']);
        $this->assertNull($transmisi150['by_upt']['BOGOR']['su_kms']);

        $tower500 = collect($breakdown['tower_groups'])->firstWhere('voltage', '500 kV');
        $this->assertNotNull($tower500);
        $this->assertSame(0.0, $tower500['by_upt']['BEKASI']['su_unit']);
        $this->assertNull($tower500['by_upt']['BEKASI']['sk_unit']);
        $this->assertSame(0.0, $tower500['by_upt']['BEKASI']['total_unit']);
        $this->assertNull($tower500['by_upt']['BOGOR']['su_unit']);

        $bay500 = collect($breakdown['bay_groups'])->firstWhere('prefix', 'BAY 500 KV');
        $this->assertNotNull($bay500);
        $this->assertSame(0.0, $bay500['by_upt']['BANDUNG']);
        $this->assertNull($bay500['by_upt']['BOGOR']);

        $matrixRow = collect($breakdown['interactive_matrix'])->firstWhere('attribute', 'Trafo 500/150 KV | MVA');
        $this->assertNotNull($matrixRow);
        $this->assertSame(0.0, $matrixRow['values']['BEKASI']);
        $this->assertNull($matrixRow['values']['BOGOR']);

        // chart_config tetap numerik (tidak mengandung null).
        foreach ($breakdown['chart_config']['mva_series'] as $val) {
            $this->assertIsFloat($val);
        }
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
