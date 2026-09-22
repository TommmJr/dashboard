<?php

namespace App\Http\Controllers;

use App\Enums\DatasetStatus;
use App\Models\AuditLog;
use App\Models\Dataset;
use App\Services\AssetStockReport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Halaman dashboard berbasis widget per role (Dashboard Builder Fase 1).
 *
 * Layout dibaca dari config/dashboard_layouts.php berdasarkan slug role
 * (fallback 'default' untuk role null / slug tak dikenal). Katalog widget
 */
class DashboardController extends Controller
{
    /**
     * Rakit daftar widget terurut untuk user aktif, lalu render view.
     */
    public function __invoke(): View
    {
        $config = config('dashboard_layouts');
        $roleSlug = Auth::user()->loadMissing('role')->role?->slug;
        $layout = $config['layouts'][$roleSlug] ?? $config['default'];

        $widgets = collect($layout)
            ->map(fn (string $key) => isset($config['widgets'][$key])
                ? ['key' => $key] + $config['widgets'][$key]
                : null)
            ->filter()
            ->filter(fn (array $widget) => $widget['permission'] === null
                || Gate::allows($widget['permission']))
            ->map(function (array $widget, int $key) {
                // Instance = indeks layout: unik per render dalam satu
                // response meski widget key yang sama tampil berulang.
                $widget['instance'] = $key;
                $widget['data'] = $this->dataFor($widget['key']);

                return $widget;
            })
            ->values()
            ->all();

        return view('dashboard', ['widgets' => $widgets]);
    }

    /**
     * Data server-side ringan per widget. Widget client-side (kpi-summary,
     * trend-chart) mengambil data sendiri via /api/performance-data -> null.
     */
    private function dataFor(string $key): mixed
    {
        return match ($key) {
            'nko-ranking' => $this->nkoRanking(),
            'aset-upt-chart' => $this->asetUptChart(),
            'recent-audit' => AuditLog::query()
                ->with('user:id,name')
                ->latest('created_at')
                ->limit(5)
                ->get(),
            'failed-datasets' => Dataset::query()
                ->where('status', DatasetStatus::FAILED)
                ->count(),
            default => null,
        };
    }

    /**
     * Data grafik stok aset per UPT untuk periode terbaru.
     *
     * Bentuk siap render ECharts bar: labels = UPT, satu series per atribut
     * (atribut tidak dicampur dalam satu series karena satuannya beda:
     * Bank, MVA, kms). Snapshot, bukan time-series — tanpa line/trend.
     * Widget ini BELUM dipasang ke layout role mana pun (M2 sebagian
     * terbuka: visual per UPT confirmed, workbook valid + mounting
     * production masih blocked).
     *
     * @return array{period: ?string, labels: list<string>, series: list<array{name: string, data: list<float>}>}
     */
    private function asetUptChart(): array
    {
        $report = app(AssetStockReport::class)->totalsByUpt();

        $upts = $report['totals']->pluck('upt')->unique()->values();
        $attributes = $report['totals']->pluck('attribute')->unique()->values();
        $byKey = $report['totals']->keyBy(fn (object $total): string => $total->upt."\0".$total->attribute);

        $series = $attributes->map(fn (string $attribute): array => [
            'name' => $attribute,
            'data' => $upts->map(fn (string $upt): float => (float) ($byKey[$upt."\0".$attribute]->total ?? 0))->all(),
        ])->values()->all();

        return ['period' => $report['period'], 'labels' => $upts->all(), 'series' => $series];
    }

    /**
     * Peringkat NKO per unit untuk periode terbaru.
     *
     * Hanya baris berakhiran '| NKO' (dihasilkan KpiReportConverter) yang
     * Cross-unit by design — widget ini hanya dipasang di layout role yang
     *
     * @return Collection<int, object>
     */
    private function nkoRanking(): Collection
    {
        $base = fn () => DB::table('performance_metrics')
            ->join('datasets', 'datasets.id', '=', 'performance_metrics.dataset_id')
            ->where('datasets.status', Dataset::STATUS_READY)
            ->where('performance_metrics.metric_name', 'like', '% | NKO');

        $latestPeriod = $base()->max('performance_metrics.period');

        if ($latestPeriod === null) {
            return collect();
        }

        return $base()
            ->where('performance_metrics.period', $latestPeriod)
            ->select('performance_metrics.metric_name', 'performance_metrics.value as nko')
            ->orderByDesc('performance_metrics.value')
            ->get()
            ->map(function (object $row): object {
                $row->unit = explode(' | ', $row->metric_name, 2)[0];

                return $row;
            });
    }
}
