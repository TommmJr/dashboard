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
        $response->assertSee('Dataset READY (Divisi)');
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
}
