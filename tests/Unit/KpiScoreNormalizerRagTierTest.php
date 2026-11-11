<?php

namespace Tests\Unit;

use App\Http\Controllers\DashboardController;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\Indicator;
use App\Models\OrgUnit;
use App\Models\PerformanceMetric;
use App\Models\PerformanceObservation;
use App\Models\Role;
use App\Models\User;
use App\Support\KpiScoreNormalizer;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KpiScoreNormalizerRagTierTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_thresholds_mengikuti_standar_kpi_100_dan_95(): void
    {
        $thresholds = KpiScoreNormalizer::ragThresholds();

        $this->assertSame(100.0, $thresholds['tercapai_min']);
        $this->assertSame(95.0, $thresholds['hampir_min']);

        $this->assertSame(KpiScoreNormalizer::TIER_TERCAPAI, KpiScoreNormalizer::ragTier(110.0));
        $this->assertSame(KpiScoreNormalizer::TIER_TERCAPAI, KpiScoreNormalizer::ragTier(100.0));
        $this->assertSame(KpiScoreNormalizer::TIER_HAMPIR, KpiScoreNormalizer::ragTier(99.9));
        $this->assertSame(KpiScoreNormalizer::TIER_HAMPIR, KpiScoreNormalizer::ragTier(95.0));
        $this->assertSame(KpiScoreNormalizer::TIER_PERLU, KpiScoreNormalizer::ragTier(94.9));
        $this->assertSame(KpiScoreNormalizer::TIER_PERLU, KpiScoreNormalizer::ragTier(0.0));
    }

    public function test_thresholds_dapat_dikustomisasi_lewat_config_kpi_dan_fallback_bila_kosong(): void
    {
        config([
            'kpi.rag_thresholds' => [
                'tercapai_min' => 90.0,
                'hampir_min' => 70.0,
            ],
        ]);

        $this->assertSame(KpiScoreNormalizer::TIER_TERCAPAI, KpiScoreNormalizer::ragTier(90.0));
        $this->assertSame(KpiScoreNormalizer::TIER_HAMPIR, KpiScoreNormalizer::ragTier(85.0));
        $this->assertSame(KpiScoreNormalizer::TIER_PERLU, KpiScoreNormalizer::ragTier(69.9));

        config(['kpi.rag_thresholds' => null]);

        $this->assertSame(KpiScoreNormalizer::TIER_TERCAPAI, KpiScoreNormalizer::ragTier(100.0));
        $this->assertSame(KpiScoreNormalizer::TIER_HAMPIR, KpiScoreNormalizer::ragTier(95.0));
        $this->assertSame(KpiScoreNormalizer::TIER_PERLU, KpiScoreNormalizer::ragTier(94.0));
    }

    public function test_perubahan_config_rag_thresholds_menyinkronkan_kpi_summary_kpi_rag_status_dan_target_realisasi_widget(): void
    {
        $this->seed(RolePermissionSeeder::class);

        config([
            'kpi.rag_thresholds' => [
                'tercapai_min' => 90.0,
                'hampir_min' => 70.0,
            ],
        ]);

        $division = Division::create([
            'name' => 'Perencanaan Pengusahaan',
            'slug' => 'perencanaan-pengusahaan',
        ]);

        $role = Role::where('slug', Role::STAFF_DIVISI)->firstOrFail();
        $user = User::factory()->create([
            'role_id' => $role->id,
            'division_id' => $division->id,
        ]);

        $dataset = Dataset::factory()->create([
            'division_id' => $division->id,
            'status' => Dataset::STATUS_READY,
        ]);

        $unit = OrgUnit::create(['name' => 'TJBT']);
        $indicator = Indicator::create([
            'org_unit_id' => $unit->id,
            'name' => 'Indikator Skor 92 Persen',
            'satuan' => '%',
            'is_group' => false,
            'pencapaian_formula_kind' => 'polarity_ratio',
        ]);

        // Skor 0.92 (92%): di bawah 100%, tetapi >= 90% (tercapai pada threshold kustom 90/70)
        PerformanceObservation::create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indicator->id,
            'org_unit_id' => $unit->id,
            'division_id' => $division->id,
            'period' => '2026-08-31',
            'target_type' => PerformanceObservation::TYPE_NUMERIC,
            'realisasi_type' => PerformanceObservation::TYPE_NUMERIC,
            'target_value' => 100.0,
            'realisasi_value' => 92.0,
            'pencapaian' => 0.92,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('1 / 1')
            ->assertSee('Skor &ge; 90%', false)
            ->assertSee('Pencapaian &ge; 90%', false)
            ->assertSee('Tercapai (&ge;90%): 1', false);
    }

    public function test_custom_rag_thresholds_reflect_in_kinerja_realisasi_nko_ranking_and_nko_donut(): void
    {
        $this->seed(RolePermissionSeeder::class);

        config([
            'kpi.rag_thresholds' => [
                'tercapai_min' => 102.0,
                'hampir_min' => 97.0,
            ],
        ]);

        // 1. Verifikasi nko-ranking component menggunakan threshold kustom
        $nkoData = [
            (object) ['unit' => 'TJBT', 'nko' => 101.0],
        ];
        $nkoHtml = $this->blade(
            '<x-widgets.nko-ranking :widget="[\'label\' => \'Peringkat NKO\']" :data="$data" />',
            ['data' => $nkoData]
        );
        $nkoHtml->assertSee('Hampir Tercapai')
            ->assertSee('bg-yellow-100 text-yellow-800')
            ->assertDontSee('bg-green-100 text-green-800');

        // 2. Verifikasi kinerja-realisasi component menggunakan threshold kustom
        $kinerjaRows = [
            [
                'unit' => 'TJBT',
                'achieved' => 1,
                'total' => 1,
                'avg' => 101.0,
                'status' => 'Hampir Tercapai',
            ],
        ];
        $kinerjaHtml = $this->blade(
            '<x-widgets.kinerja-realisasi :widget="[\'key\' => \'kinerja-realisasi\', \'instance\' => 0]" :data="[\'period\' => \'2026-08-31\', \'rows\' => $rows, \'has_data\' => true]" />',
            ['rows' => $kinerjaRows]
        );
        $kinerjaHtml->assertSee('#eab308')
            ->assertSee('bg-amber-500')
            ->assertDontSee('#16a34a')
            ->assertDontSee('bg-emerald-500');

        // 3. Verifikasi nkoDonut pada DashboardController menggunakan threshold kustom
        $dataset = Dataset::factory()->create(['status' => Dataset::STATUS_READY]);
        PerformanceMetric::factory()->create([
            'dataset_id' => $dataset->id,
            'metric_name' => 'TJBT | NKO',
            'value' => 101.0,
            'period' => '2026-08-31',
        ]);
        config(['kpi.visible_units' => ['TJBT']]);
        config(['kpi.read_model' => 'legacy']);

        $controller = app(DashboardController::class);
        $method = new \ReflectionMethod($controller, 'nkoDonut');
        $method->setAccessible(true);
        $donutResult = $method->invoke($controller, null);

        $this->assertSame([
            ['name' => 'Hampir Tercapai', 'value' => 1],
        ], $donutResult['data']);
    }
}
