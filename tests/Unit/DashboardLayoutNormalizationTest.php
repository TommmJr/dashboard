<?php

namespace Tests\Unit;

use App\Models\DashboardLayout;
use Tests\TestCase;

class DashboardLayoutNormalizationTest extends TestCase
{
    public function test_normalizes_legacy_string_widget_array_with_catalog_defaults(): void
    {
        $catalog = [
            'kpi-summary' => ['label' => 'Ringkasan KPI', 'default_cols' => 3],
            'nko-donut' => ['label' => 'Distribusi NKO', 'default_cols' => 1],
        ];

        $raw = ['kpi-summary', 'nko-donut'];
        $normalized = DashboardLayout::normalizeWidgets($raw, $catalog);

        $this->assertSame([
            ['key' => 'kpi-summary', 'cols' => 3, 'config' => []],
            ['key' => 'nko-donut', 'cols' => 1, 'config' => []],
        ], $normalized);
    }

    public function test_preserves_custom_cols_and_config_and_falls_back_when_invalid(): void
    {
        $catalog = [
            'trend-chart' => ['label' => 'Tren Kinerja', 'default_cols' => 2],
        ];

        $raw = [
            ['key' => 'trend-chart', 'cols' => 1, 'config' => ['metric' => 'nko_score']],
            ['key' => 'trend-chart', 'cols' => 5, 'config' => []],
        ];

        $normalized = DashboardLayout::normalizeWidgets($raw, $catalog);

        $this->assertSame([
            ['key' => 'trend-chart', 'cols' => 1, 'config' => ['metric' => 'nko_score']],
            ['key' => 'trend-chart', 'cols' => 2, 'config' => []],
        ], $normalized);
    }
}
