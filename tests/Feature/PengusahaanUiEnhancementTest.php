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
use Tests\TestCase;

class PengusahaanUiEnhancementTest extends TestCase
{
    use RefreshDatabase;

    private Division $pengusahaan;

    private User $staffUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->pengusahaan = Division::factory()->create([
            'name' => 'Perencanaan Pengusahaan',
            'slug' => 'perencanaan-pengusahaan',
        ]);

        $staffRole = Role::where('slug', Role::STAFF_DIVISI)->firstOrFail();
        $this->staffUser = User::factory()->create([
            'name' => 'Budi Santoso',
            'role_id' => $staffRole->id,
            'division_id' => $this->pengusahaan->id,
        ]);
    }

    public function test_panel_target_realisasi_menampilkan_angka_target_realisasi_eksplisit_dan_toolbar_filter_alpine(): void
    {
        $dataset = Dataset::factory()->create([
            'division_id' => $this->pengusahaan->id,
            'user_id' => $this->staffUser->id,
            'status' => DatasetStatus::READY,
        ]);

        // Skema valid: nama unit persis 'TJBT' (case-sensitive) dan tanpa kolom 'code'
        $unit = OrgUnit::create([
            'name' => 'TJBT',
        ]);

        // Skema valid: wajib menyertakan org_unit_id dan tanpa kolom 'code'
        $indicator = Indicator::create([
            'org_unit_id' => $unit->id,
            'name' => 'Keandalan Jaringan Transmisi',
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
            'target_value' => 120.5,
            'target_type' => 'numeric',
            'realisasi_value' => 125.0,
            'realisasi_type' => 'numeric',
            'pencapaian' => 1.0373,
            'nilai' => 10.37,
            'source_sheet' => 'TJBT',
            'source_row' => 12,
        ]);

        $response = $this->actingAs($this->staffUser)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('id="target-realisasi-toolbar"', false);
        $response->assertSee('data-rag-filter="tercapai"', false);
        $response->assertSee('data-indicator-id="'.$indicator->id.'"', false);
        // Menggunakan assertSeeText agar tidak gagal karena tag <strong> atau format HTML
        $response->assertSeeText('Target: 120,5');
        $response->assertSeeText('Realisasi: 125');
        $response->assertSeeText('Kali');
    }

    public function test_tabel_kinerja_detail_memiliki_input_pencarian_cepat_dan_mempertahankan_kontrak_sel_indikator(): void
    {
        $dataset = Dataset::factory()->create([
            'division_id' => $this->pengusahaan->id,
            'user_id' => $this->staffUser->id,
            'status' => DatasetStatus::READY,
        ]);

        $unit = OrgUnit::create([
            'name' => 'TJBT',
        ]);

        $indicator = Indicator::create([
            'org_unit_id' => $unit->id,
            'name' => 'Susut Jaringan Transmisi',
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
            'target_value' => 1.5,
            'target_type' => 'numeric',
            'realisasi_value' => 1.5,
            'realisasi_type' => 'numeric',
            'pencapaian' => 1.0,
            'nilai' => 10.0,
            'source_sheet' => 'TJBT',
            'source_row' => 15,
        ]);

        $response = $this->actingAs($this->staffUser)->get(route('dashboard.kpi-detail'));

        $response->assertOk();
        $response->assertSee('id="kinerja-detail-search"', false);
        $response->assertSee('<td class="py-2.5 px-3 text-slate-800">Susut Jaringan Transmisi</td>', false);
    }

    public function test_header_dan_chart_memiliki_skeleton_dan_markline_tanpa_merusak_breadcrumb_beranda(): void
    {
        $dataset = Dataset::factory()->create([
            'division_id' => $this->pengusahaan->id,
            'user_id' => $this->staffUser->id,
            'status' => DatasetStatus::READY,
        ]);

        $unit = OrgUnit::create([
            'name' => 'TJBT',
        ]);

        $indicator = Indicator::create([
            'org_unit_id' => $unit->id,
            'name' => 'Indikator Markline',
            'satuan' => 'Unit',
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
            'realisasi_value' => 105,
            'realisasi_type' => 'numeric',
            'pencapaian' => 1.05,
            'nilai' => 10.5,
            'source_sheet' => 'TJBT',
            'source_row' => 18,
        ]);

        $response = $this->actingAs($this->staffUser)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Beranda');
        $response->assertSee('id="header-breadcrumb"', false);
        $response->assertSee('data-skeleton="trend-chart"', false);
        $response->assertSee('markLine', false);
    }
}
