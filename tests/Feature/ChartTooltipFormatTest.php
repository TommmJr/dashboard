<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Guard display-only untuk tooltip chart.
 *
 * assertSee tidak bisa menjalankan JS, jadi yang dikunci di sini hanya bahwa
 * blok formatter BENAR-BENAR ikut ter-render ke script blade (bukan cuma ada di
 * file), pada komponen blade `widgets.target-vs-realisasi`. Perilaku
 * runtime-nya (pembulatan 2 desimal) tidak bisa diuji lewat PHP.
 *
 * RETARGET Fase 4.3 Tahap C: widget `target-vs-realisasi` telah dicabut dari
 * layout/katalog produksi (Sesi 95) dan reader R8 `DashboardController::targetVsRealisasi()`
 * telah dihapus dari controller. Komponen blade `widgets.target-vs-realisasi`
 * tetap diuji langsung dengan payload statis supaya guard formatter 2 desimal
 * tetap terverifikasi tanpa memanggil reader R8 yang sudah pensiun.
 */
class ChartTooltipFormatTest extends TestCase
{
    public function test_tooltip_target_vs_realisasi_memakai_formatter_dua_desimal(): void
    {
        $html = Blade::render(
            '<x-widgets.target-vs-realisasi :widget="$widget" :data="$data" />',
            [
                'widget' => [
                    'key' => 'target-vs-realisasi',
                    'label' => 'Target vs Realisasi',
                    'instance' => 0,
                ],
                'data' => [
                    'period' => '2026-08-31',
                    'labels' => ['TEK'],
                    'target' => [322247.71],
                    'realisasi' => [322247.71],
                ],
            ],
        );

        // Label widget tetap dirender.
        $this->assertStringContainsString('Target vs Realisasi', $html);

        // Formatter tooltip ikut ter-render ke script.
        $this->assertStringContainsString('valueFormatter: fmtTooltipValue', $html);
        $this->assertStringContainsString('maximumFractionDigits: 2', $html);
    }
}
