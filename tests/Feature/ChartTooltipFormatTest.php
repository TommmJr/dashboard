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
 *
 * RETARGET 2026-10-19: widget `target-vs-realisasi` dicabut dari layout
 * `staff_divisi:perencanaan-pengusahaan` (keputusan user; lihat
 * config/dashboard_layouts.php). Widget-nya TETAP ada di katalog, jadi guard
 * formatter di sini dipasang lewat override layout di setUp supaya blade-nya
 * tetap ter-render. Ini bukan bukti widget tampil di dashboard produksi:
 * absennya di layout produksi dikunci `DashboardWidgetLayoutTest`.
 */
class ChartTooltipFormatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // Guard display-only tooltip memakai nama unit stand-in (TEK). Batas
        // unit TAMPIL (keputusan 2026-10-12, `kpi.visible_units`) dimatikan
        // supaya stand-in non-TJBT tetap valid; batas itu diuji di
        // `KpiUnitScopeTest`.
        config(['kpi.visible_units' => null]);

        // Mount sementara: katalog dan layout produksi tidak lagi memuat widget
        // lama setelah Keputusan Bisnis 5 Pak Gama (2026-10-25), tetapi blade +
        // reader R8 masih diisolasi sampai deprecate legacy Fase 4.3.
        $widgets = config('dashboard_layouts.widgets');
        $widgets['target-vs-realisasi'] = [
            'label' => 'Target vs Realisasi',
            'view' => 'widgets.target-vs-realisasi',
            'permission' => null,
        ];
        $layouts = config('dashboard_layouts.layouts');
        $layouts['staff_divisi:perencanaan-pengusahaan'][] = 'target-vs-realisasi';
        config([
            'dashboard_layouts.widgets' => $widgets,
            'dashboard_layouts.layouts' => $layouts,
        ]);
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
