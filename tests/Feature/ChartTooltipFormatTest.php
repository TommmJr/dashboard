<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Division;
use App\Models\PerformanceMetric;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guard display-only untuk tooltip chart.
 *
 * assertSee tidak bisa menjalankan JS, jadi yang dikunci di sini hanya bahwa
 * blok formatter BENAR-BENAR ikut ter-render ke script blade (bukan cuma ada di
 * file), pada widget yang membawa artefak float R8. Perilaku runtime-nya
 * (pembulatan 2 desimal) tidak bisa diuji lewat PHP.
 *
 * Test ini TIDAK mengubah karakterisasi R8: nilai payload tetap apa adanya,
 * hanya presentasi tooltip yang dibulatkan.
 */
class ChartTooltipFormatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_tooltip_target_vs_realisasi_memakai_formatter_dua_desimal(): void
    {
        $division = Division::factory()->create(['slug' => 'perencanaan-pengusahaan']);
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $division->id]);

        // Nilai sengaja bergaya artefak float R8 (ekor panjang) supaya payload
        // tetap diuji "apa adanya" di sisi lain; formatter tidak menyentuhnya.
        $dataset = Dataset::factory()->create(['division_id' => $division->id]);
        PerformanceMetric::factory()->create([
            'dataset_id' => $dataset->id,
            'division_id' => $division->id,
            'metric_name' => 'TEK | I | target',
            'value' => 322247.71,
            'period' => '2026-08-31',
        ]);
        PerformanceMetric::factory()->create([
            'dataset_id' => $dataset->id,
            'division_id' => $division->id,
            'metric_name' => 'TEK | I | realisasi',
            'value' => 322247.71,
            'period' => '2026-08-31',
        ]);

        $response = $this->actingAs($staff)->get(route('dashboard'))->assertOk();

        // Label widget tetap dirender (payload tidak berubah).
        $response->assertSee('Target vs Realisasi');

        // Formatter tooltip ikut ter-render ke script.
        $response->assertSee('valueFormatter: fmtTooltipValue', false);
        $response->assertSee('maximumFractionDigits: 2', false);
    }
}
