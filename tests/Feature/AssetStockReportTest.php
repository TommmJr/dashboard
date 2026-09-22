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
}
