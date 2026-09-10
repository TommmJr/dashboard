<?php

namespace App\Http\Controllers;

use App\Enums\DatasetStatus;
use App\Models\AuditLog;
use App\Models\Dataset;
use Illuminate\Support\Facades\Auth;
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
}
