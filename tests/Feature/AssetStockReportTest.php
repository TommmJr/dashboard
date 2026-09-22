<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Division;
use App\Models\PerformanceMetric;
use App\Services\AssetStockReport;
use App\Services\KpiReportConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Query read-only snapshot Aset: level query/unit saja, tanpa assertion
 * terhadap halaman dashboard (visual belum diputuskan mentor).
 */
class AssetStockReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_mengelompokkan_periode_terbaru_per_upt_ultg(): void
    {
        $division = Division::factory()->create();
        $dataset = Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_READY,
        ]);

        $row = fn (string $metric, string $period, float $value) => PerformanceMetric::factory()->create([
            'metric_name' => $metric,
            'dataset_id' => $dataset->id,
            'division_id' => $division->id,
            'period' => $period,
            'value' => $value,
        ]);

        $row('ASET | BANDUNG | BANDUNG BARAT | Trafo 500/150 KV | Bank', '2026-01-31', 2);
        $row('ASET | BANDUNG | BANDUNG BARAT | Trafo 500/150 KV | MVA', '2026-01-31', 100.5);
        $row('ASET | BANDUNG | BANDUNG SELATAN | Trafo 500/150 KV | Bank', '2026-01-31', 0);
        $row('ASET | BANDUNG | BANDUNG BARAT | Trafo 500/150 KV | Bank', '2026-02-28', 3);

        $snapshot = app(AssetStockReport::class)->latestSnapshot();

        $this->assertSame('2026-02-28', $snapshot['period']);

        $groups = $snapshot['groups'];
        $this->assertCount(1, $groups);
        $this->assertSame('BANDUNG', $groups[0]->upt);
        $this->assertSame('BANDUNG BARAT', $groups[0]->ultg);
        $this->assertSame([['attribute' => 'Trafo 500/150 KV | Bank', 'value' => 3.0]],
            $groups[0]->metrics->map(fn ($m) => ['attribute' => $m->attribute, 'value' => $m->value])->all());
    }

    public function test_snapshot_mengabaikan_baris_non_aset_dan_dataset_non_ready(): void
    {
        $division = Division::factory()->create();
        $ready = Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_READY,
        ]);
        $draft = Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_FAILED,
        ]);

        PerformanceMetric::factory()->create([
            'metric_name' => 'RST'.KpiReportConverter::NKO_SUFFIX,
            'dataset_id' => $ready->id,
            'division_id' => $division->id,
            'period' => '2026-01-31',
            'value' => 100,
        ]);
        PerformanceMetric::factory()->create([
            'metric_name' => 'ASET | BOGOR | BOGOR | Trafo 500/150 KV | Bank',
            'dataset_id' => $draft->id,
            'division_id' => $division->id,
            'period' => '2026-01-31',
            'value' => 9,
        ]);
        PerformanceMetric::factory()->create([
            'metric_name' => 'ASET | BEKASI | BEKASI | Trafo 500/150 KV | Bank',
            'dataset_id' => $ready->id,
            'division_id' => $division->id,
            'period' => '2026-01-31',
            'value' => 4,
        ]);

        $snapshot = app(AssetStockReport::class)->latestSnapshot();

        $this->assertSame('2026-01-31', $snapshot['period']);
        $this->assertCount(1, $snapshot['groups']);
        $this->assertSame('BEKASI', $snapshot['groups'][0]->upt);
    }

    public function test_snapshot_kosong_tanpa_baris_aset(): void
    {
        $snapshot = app(AssetStockReport::class)->latestSnapshot();

        $this->assertNull($snapshot['period']);
        $this->assertTrue($snapshot['groups']->isEmpty());
    }

    public function test_baris_bernilai_nol_tetap_muncul_di_snapshot(): void
    {
        // Nol adalah data (belum ada realisasi, atau cached zero dari
        // converter) — bukan "tidak ada data", jadi tidak boleh difilter.
        $division = Division::factory()->create();
        $dataset = Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_READY,
        ]);
        PerformanceMetric::factory()->create([
            'metric_name' => 'ASET | BANDUNG | BANDUNG BARAT | Trafo 500/150 KV | Bank',
            'dataset_id' => $dataset->id,
            'division_id' => $division->id,
            'period' => '2026-01-31',
            'value' => 0,
        ]);

        $snapshot = app(AssetStockReport::class)->latestSnapshot();

        $this->assertSame('2026-01-31', $snapshot['period']);
        $this->assertCount(1, $snapshot['groups']);

        $metrics = $snapshot['groups'][0]->metrics;
        $this->assertCount(1, $metrics);
        $this->assertSame('Trafo 500/150 KV | Bank', $metrics[0]->attribute);
        $this->assertSame(0.0, $metrics[0]->value);
    }

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
