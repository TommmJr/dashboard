<?php

namespace Tests\Feature;

use App\Enums\DatasetStatus;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\Indicator;
use App\Models\OrgUnit;
use App\Models\PerformanceObservation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class UiSimplificationTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    private User $staffUser;

    private Division $pengusahaan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $adminRole = Role::where('slug', Role::ADMIN)->firstOrFail();
        $this->adminUser = User::factory()->create([
            'role_id' => $adminRole->id,
            'division_id' => null,
        ]);

        $this->pengusahaan = Division::factory()->create([
            'name' => 'Perencanaan Pengusahaan',
            'slug' => 'perencanaan-pengusahaan',
        ]);

        $staffRole = Role::where('slug', Role::STAFF_DIVISI)->firstOrFail();
        $this->staffUser = User::factory()->create([
            'role_id' => $staffRole->id,
            'division_id' => $this->pengusahaan->id,
        ]);
    }

    public function test_dataset_status_cards_have_neutral_backgrounds_and_colored_numbers(): void
    {
        $dataset = Dataset::factory()->create([
            'division_id' => $this->pengusahaan->id,
            'user_id' => $this->staffUser->id,
            'status' => DatasetStatus::READY,
        ]);

        $unit = OrgUnit::create(['name' => 'TJBT']);
        $indicator = Indicator::create([
            'org_unit_id' => $unit->id,
            'name' => 'Indikator Test Dataset Status',
            'satuan' => '%',
            'category' => 'kpi',
            'is_group' => false,
        ]);

        PerformanceObservation::create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indicator->id,
            'org_unit_id' => $unit->id,
            'division_id' => $this->pengusahaan->id,
            'period' => '2026-08-31',
            'target_value' => 100,
            'target_type' => 'numeric',
            'realisasi_value' => 100,
            'realisasi_type' => 'numeric',
            'pencapaian' => 1.0,
            'nilai' => 10.0,
            'source_sheet' => 'TJBT',
            'source_row' => 10,
        ]);

        $response = $this->actingAs($this->staffUser)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Dataset Berhasil (Divisi)');
        $response->assertDontSee('bg-emerald-50/40', false);
        $response->assertDontSee('bg-rose-50/40', false);
        $response->assertDontSee('bg-blue-50/40', false);
    }

    public function test_kpi_rag_status_tiles_have_neutral_background_with_colored_dots_and_percentages(): void
    {
        $dataset = Dataset::factory()->create([
            'division_id' => $this->pengusahaan->id,
            'user_id' => $this->staffUser->id,
            'status' => DatasetStatus::READY,
        ]);

        $unit = OrgUnit::create(['name' => 'TJBT']);
        $indicator = Indicator::create([
            'org_unit_id' => $unit->id,
            'name' => 'Indikator Test RAG',
            'satuan' => '%',
            'category' => 'kpi',
            'is_group' => false,
        ]);

        PerformanceObservation::create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indicator->id,
            'org_unit_id' => $unit->id,
            'division_id' => $this->pengusahaan->id,
            'period' => '2026-08-31',
            'target_value' => 100,
            'target_type' => 'numeric',
            'realisasi_value' => 100,
            'realisasi_type' => 'numeric',
            'pencapaian' => 1.0,
            'nilai' => 10.0,
            'source_sheet' => 'TJBT',
            'source_row' => 10,
        ]);

        $response = $this->actingAs($this->staffUser)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Status RAG Indikator');
        $response->assertDontSee('bg-emerald-50/70', false);
        $response->assertDontSee('bg-amber-50/70', false);
        $response->assertDontSee('bg-rose-50/70', false);
    }

    public function test_target_realisasi_filter_buttons_have_colored_dots_and_no_colored_backgrounds(): void
    {
        $dataset = Dataset::factory()->create([
            'division_id' => $this->pengusahaan->id,
            'user_id' => $this->staffUser->id,
            'status' => DatasetStatus::READY,
        ]);

        $unit = OrgUnit::create(['name' => 'TJBT']);
        $indicator = Indicator::create([
            'org_unit_id' => $unit->id,
            'name' => 'Test Indicator Toolbar',
            'satuan' => 'Kali',
            'category' => 'kpi',
            'is_group' => false,
        ]);

        PerformanceObservation::create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indicator->id,
            'org_unit_id' => $unit->id,
            'division_id' => $this->pengusahaan->id,
            'period' => '2026-08-31',
            'target_value' => 10,
            'target_type' => 'numeric',
            'realisasi_value' => 10,
            'realisasi_type' => 'numeric',
            'pencapaian' => 1.0,
            'nilai' => 10.0,
            'source_sheet' => 'TJBT',
            'source_row' => 11,
        ]);

        $response = $this->actingAs($this->staffUser)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('id="target-realisasi-toolbar"', false);
        $response->assertSee('data-rag-filter="tercapai"', false);
        // Ensure no heavy colored backgrounds on buttons
        $response->assertDontSee("activeRag === 'tercapai' ? 'bg-emerald-600", false);
        $response->assertDontSee("activeRag === 'hampir' ? 'bg-amber-500", false);
        $response->assertDontSee("activeRag === 'perlu' ? 'bg-rose-600", false);
        $response->assertSee("activeRag === 'tercapai' ? 'border-emerald-600", false);
        $response->assertSee("activeRag === 'hampir' ? 'border-amber-600", false);
        $response->assertSee("activeRag === 'perlu' ? 'border-rose-600", false);
    }

    public function test_kinerja_detail_filter_buttons_have_colored_dots_and_no_colored_backgrounds_on_kpi_detail_page(): void
    {
        $dataset = Dataset::factory()->create([
            'division_id' => $this->pengusahaan->id,
            'user_id' => $this->staffUser->id,
            'status' => DatasetStatus::READY,
        ]);

        $unit = OrgUnit::create(['name' => 'TJBT']);
        $indicator = Indicator::create([
            'org_unit_id' => $unit->id,
            'name' => 'Indikator Detail Rincian',
            'satuan' => '%',
            'category' => 'kpi',
            'is_group' => false,
        ]);

        PerformanceObservation::create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indicator->id,
            'org_unit_id' => $unit->id,
            'division_id' => $this->pengusahaan->id,
            'period' => '2026-08-31',
            'target_value' => 10,
            'target_type' => 'numeric',
            'realisasi_value' => 10,
            'realisasi_type' => 'numeric',
            'pencapaian' => 1.0,
            'nilai' => 10.0,
            'source_sheet' => 'TJBT',
            'source_row' => 12,
        ]);

        $response = $this->actingAs($this->staffUser)->get(route('dashboard.kpi-detail'));

        $response->assertOk();
        $response->assertSee('id="kinerja-detail-search"', false);
        $response->assertDontSee("tableRag === 'tercapai' ? 'bg-emerald-600", false);
        $response->assertDontSee("tableRag === 'hampir' ? 'bg-amber-500", false);
        $response->assertDontSee("tableRag === 'perlu' ? 'bg-rose-600", false);
        $response->assertSee("tableRag === 'tercapai' ? 'border-emerald-600", false);
        $response->assertSee("tableRag === 'hampir' ? 'border-amber-600", false);
        $response->assertSee("tableRag === 'perlu' ? 'border-rose-600", false);
    }

    public function test_admin_dashboard_omits_marketing_filler_and_kpi_analytics_uses_outline_tabs(): void
    {
        $this->actingAs($this->adminUser)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Pusat Kendali Operasional')
            ->assertDontSee('Monitoring dataset, audit aktivitas, dan administrasi sistem.');

        $analyticsResponse = $this->actingAs($this->staffUser)
            ->get(route('dashboard.kpi-analytics'));

        $analyticsResponse->assertOk()
            ->assertDontSee('bg-gradient-to-r', false)
            ->assertDontSee("activeTab === 'kritis' ? 'bg-rose-600", false)
            ->assertDontSee("activeTab === 'tercapai' ? 'bg-emerald-600", false)
            ->assertSee("activeTab === 'kritis' ? 'border-rose-600", false)
            ->assertSee("activeTab === 'tercapai' ? 'border-emerald-600", false);
    }

    public function test_kpi_summary_avoids_decorative_gradients(): void
    {
        $managementRole = Role::where('slug', Role::MANAGEMENT)->firstOrFail();
        $management = User::factory()->create([
            'role_id' => $managementRole->id,
            'division_id' => null,
        ]);

        $dataset = Dataset::factory()->create([
            'division_id' => $this->pengusahaan->id,
            'user_id' => $this->staffUser->id,
            'status' => DatasetStatus::READY,
        ]);

        $unit = OrgUnit::create(['name' => 'TJBT']);
        $nkoIndicator = Indicator::create([
            'org_unit_id' => $unit->id,
            'name' => 'NKO',
            'satuan' => 'Skor',
            'category' => 'nko',
            'is_group' => false,
        ]);

        PerformanceObservation::create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $nkoIndicator->id,
            'org_unit_id' => $unit->id,
            'division_id' => $this->pengusahaan->id,
            'period' => '2026-08-31',
            'target_value' => 100,
            'target_type' => 'numeric',
            'realisasi_value' => 101.5,
            'realisasi_type' => 'numeric',
            'pencapaian' => 101.5,
            'nilai' => 101.5,
            'source_sheet' => 'TJBT',
            'source_row' => 5,
        ]);

        $this->actingAs($management)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('bg-gradient-to-r', false);
    }

    public function test_admin_and_governance_pages_use_ibs_card_without_font_mono_or_dash_placeholders(): void
    {
        $divisionWithoutDescription = Division::factory()->create([
            'name' => 'Divisi Tanpa Deskripsi',
            'slug' => 'divisi-tanpa-deskripsi',
            'description' => null,
        ]);

        $datasetWithoutDivision = Dataset::factory()->create([
            'division_id' => $this->pengusahaan->id,
            'user_id' => $this->adminUser->id,
            'status' => DatasetStatus::READY,
        ]);
        $datasetWithoutDivision->setRelation('division', null);

        $routes = [
            route('dashboard'),
            route('dashboard.builder'),
            route('users.index'),
            route('users.create'),
            route('users.edit', $this->staffUser),
            route('divisions.index'),
            route('divisions.create'),
            route('divisions.edit', $divisionWithoutDescription),
            route('audit-logs.index'),
        ];

        foreach ($routes as $url) {
            $response = $this->actingAs($this->adminUser)->get($url);

            $response->assertOk()
                ->assertSee('ibs-card', false)
                ->assertDontSee('font-mono', false);

            $visibleHtml = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $response->getContent());
            $this->assertStringNotContainsString('—', $visibleHtml, "Em-dash ditemukan di {$url}");
            $this->assertStringNotContainsString('&mdash;', $visibleHtml, "Entity &mdash; ditemukan di {$url}");
            $this->assertStringNotContainsString('-- ', $visibleHtml, "Double-dash placeholder ditemukan di {$url}");
        }

        $this->actingAs($this->adminUser)
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('Tanpa divisi')
            ->assertSee('Akun Anda');

        $this->actingAs($this->adminUser)
            ->get(route('divisions.index'))
            ->assertOk()
            ->assertSee('Belum ada deskripsi');

        if (Route::has('reports.index')) {
            $reportsHtml = (string) $this->actingAs($this->adminUser)
                ->view('reports.index', [
                    'datasets' => collect([$datasetWithoutDivision]),
                ]);

            $this->assertStringContainsString('ibs-card', $reportsHtml);
            $this->assertStringContainsString('ibs-section-title', $reportsHtml);
            $this->assertStringContainsString('Tanpa divisi', $reportsHtml);
            $this->assertStringNotContainsString('font-mono', $reportsHtml);
            $this->assertStringNotContainsString('—', $reportsHtml);
            $this->assertStringNotContainsString('&mdash;', $reportsHtml);
            $this->assertStringNotContainsString('-- ', $reportsHtml);
        } else {
            $reportsSource = file_get_contents(resource_path('views/reports/index.blade.php'));
            $this->assertNotFalse($reportsSource);
            $reportsVisibleSource = preg_replace('/\{\{--.*?--\}\}/s', '', $reportsSource);
            $this->assertStringContainsString('ibs-card', $reportsVisibleSource);
            $this->assertStringContainsString('ibs-section-title', $reportsVisibleSource);
            $this->assertStringContainsString('Tanpa divisi', $reportsVisibleSource);
            $this->assertStringNotContainsString('font-mono', $reportsVisibleSource);
            $this->assertStringNotContainsString('—', $reportsVisibleSource);
            $this->assertStringNotContainsString('&mdash;', $reportsVisibleSource);
            $this->assertStringNotContainsString('-- ', $reportsVisibleSource);
        }
    }
}
