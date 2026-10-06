<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Indicator;
use App\Models\OrgUnit;
use App\Models\PerformanceObservation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Schema + model check untuk model KPI terstruktur (Unit 2 dan Unit 3).
 *
 * Fokus: constraint yang load-bearing (unique per-unit, hierarchy, FK deletion
 * rules) dan nilai bertipe. Bukan validasi bisnis: tidak ada angka NKO/TJBT
 * yang dianggap kebenaran bisnis di sini.
 */
class StructuredKpiSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_nama_org_unit_unik(): void
    {
        OrgUnit::factory()->create(['name' => 'TJBT']);

        $this->expectException(QueryException::class);
        OrgUnit::factory()->create(['name' => 'TJBT']);
    }

    public function test_identitas_indikator_per_unit_bukan_global(): void
    {
        $unitA = OrgUnit::factory()->create(['name' => 'TJBT']);
        $unitB = OrgUnit::factory()->create(['name' => 'MES']);

        // Nama sama di unit berbeda: sah, karena identitas per-unit.
        Indicator::factory()->create(['org_unit_id' => $unitA->id, 'name' => 'Realisasi']);
        Indicator::factory()->create(['org_unit_id' => $unitB->id, 'name' => 'Realisasi']);

        $this->assertSame(2, Indicator::where('name', 'Realisasi')->count());

        // Nama sama di unit yang sama: ditolak.
        $this->expectException(QueryException::class);
        Indicator::factory()->create(['org_unit_id' => $unitA->id, 'name' => 'Realisasi']);
    }

    public function test_hierarki_parent_child_dan_null_on_delete(): void
    {
        $parent = Indicator::factory()->group()->bobotNumeric(20)->create();
        $child = Indicator::factory()->create([
            'org_unit_id' => $parent->org_unit_id,
            'parent_id' => $parent->id,
        ]);

        $this->assertTrue($parent->children->contains($child));
        $this->assertTrue($child->parent->is($parent));

        // Hapus induk -> anak tetap ada, parent_id jadi null.
        $parent->delete();

        $this->assertDatabaseHas('indicators', ['id' => $child->id, 'parent_id' => null]);
    }

    public function test_observation_unik_per_dataset_indikator_unit_periode(): void
    {
        $observation = PerformanceObservation::factory()->create(['period' => '2026-08-31']);

        $this->expectException(QueryException::class);
        PerformanceObservation::factory()->create([
            'dataset_id' => $observation->dataset_id,
            'indicator_id' => $observation->indicator_id,
            'org_unit_id' => $observation->org_unit_id,
            'period' => '2026-08-31',
        ]);
    }

    public function test_target_bertipe_numeric_date_text_empty(): void
    {
        $numeric = PerformanceObservation::factory()->numeric()->create();
        $date = PerformanceObservation::factory()->dateTarget()->create();
        $text = PerformanceObservation::factory()->textTarget()->create();
        $empty = PerformanceObservation::factory()->emptyTarget()->create();

        $this->assertSame(PerformanceObservation::TYPE_NUMERIC, $numeric->target_type);
        $this->assertNotNull($numeric->target_value);

        $this->assertSame(PerformanceObservation::TYPE_DATE, $date->target_type);
        $this->assertSame('2025-08-31', $date->target_date->toDateString());

        $this->assertSame(PerformanceObservation::TYPE_TEXT, $text->target_type);
        $this->assertSame('Sesuai RKAP', $text->target_text);

        $this->assertSame(PerformanceObservation::TYPE_EMPTY, $empty->target_type);
        $this->assertNull($empty->target_value);
        $this->assertNull($empty->target_text);
        $this->assertNull($empty->target_date);
    }

    public function test_polarity_dan_bobot_band_tidak_dipaksa_numeric(): void
    {
        $higher = Indicator::factory()->higherIsBetter()->bobotNumeric(30)->create();
        $lower = Indicator::factory()->lowerIsBetter()->bobotBand()->create();

        $this->assertSame('higher_is_better', $higher->polarity);
        $this->assertSame('30.0000', $higher->bobot_value);

        $this->assertSame('lower_is_better', $lower->polarity);
        $this->assertSame('Max -8 Max +1,5', $lower->bobot);
        $this->assertNull($lower->bobot_value);
        $this->assertNull($lower->bobot_min);
        $this->assertNull($lower->bobot_max);
    }

    public function test_hapus_dataset_menghapus_observation_cascade(): void
    {
        $observation = PerformanceObservation::factory()->create();
        $datasetId = $observation->dataset_id;

        Dataset::findOrFail($datasetId)->delete();

        $this->assertDatabaseMissing('performance_observations', ['id' => $observation->id]);
    }

    public function test_hapus_indikator_dengan_observation_ditolak(): void
    {
        $observation = PerformanceObservation::factory()->create();

        $this->expectException(QueryException::class);
        Indicator::findOrFail($observation->indicator_id)->delete();
    }

    public function test_hapus_org_unit_dengan_indikator_ditolak(): void
    {
        $indicator = Indicator::factory()->create();

        $this->expectException(QueryException::class);
        OrgUnit::findOrFail($indicator->org_unit_id)->delete();
    }

    public function test_tabel_legacy_performance_metrics_masih_ada(): void
    {
        $this->assertTrue(
            Schema::hasTable('performance_metrics'),
            'performance_metrics harus dipertahankan sebagai compatibility layer.',
        );
    }
}
