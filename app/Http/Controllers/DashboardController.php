<?php

namespace App\Http\Controllers;

use App\Enums\DatasetStatus;
use App\Models\AuditLog;
use App\Models\Dataset;
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
 * difilter permission via Gate di backend — config BUKAN mekanisme
 * keamanan. Data server-side (audit terbaru, jumlah dataset FAILED)
 * hanya disiapkan untuk widget yang benar-benar lolos filter.
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
            ->map(function (array $widget) {
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
     * Peringkat NKO per unit untuk periode terbaru.
     *
     * Hanya baris berakhiran '| NKO' (dihasilkan KpiReportConverter) yang
     * diambil. NKO BUKAN besaran aditif: menjumlahkannya seperti pada chart
     * tren (SUM(value)) menghasilkan angka tak bermakna, jadi query ini
     * terpisah dan mengambil nilai per unit apa adanya.
     *
     * Cross-unit by design — widget ini hanya dipasang di layout role yang
     * memang melihat semua divisi (lihat config/dashboard_layouts.php).
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
            ->join('divisions', 'divisions.id', '=', 'performance_metrics.division_id')
            ->where('performance_metrics.period', $latestPeriod)
            ->select('divisions.name as unit', 'performance_metrics.value as nko')
            ->orderByDesc('performance_metrics.value')
            ->get();
    }
}
