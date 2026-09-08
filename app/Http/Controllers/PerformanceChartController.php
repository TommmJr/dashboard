<?php

namespace App\Http\Controllers;

use App\Models\Dataset;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PerformanceChartController extends Controller
{
    /**
     * Data agregasi untuk chart dashboard utama (Tahap 7 parsial).
     *
     * Kontrak JSON:
     * - labels : daftar periode (Y-m-d) urut naik.
     * - series : [{ name: <nama divisi>, data: [<total per periode>] }]
     *            — satu series per divisi agar ECharts bisa menumpuk
     *            bar + line trend per divisi.
     * - kpi    : { total_value, ready_datasets, metric_count }
     *
     * Aturan scoping (per audit keamanan #3):
     * - admin & management: agregasi SELURUH divisi.
     * - role lain (staf_divisi, editor_dashboard): hanya divisinya sendiri.
     *
     * Dataset ARCHIVED dikeluarkan dari agregasi (keputusan
     * archive-vs-delete); baris legacy tanpa dataset tetap dihitung.
     * Filter di query, TIDAK mengandalkan filter di view/frontend.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        // Query via DB::table (bukan model): cast 'date' pada model
        // menggeser period saat serialisasi (app Asia/Jakarta vs cast
        // menganggap DB UTC). period diformat eksplisit Y-m-d di PHP.
        $baseQuery = fn () => DB::table('performance_metrics')
            ->leftJoin('datasets', 'datasets.id', '=', 'performance_metrics.dataset_id')
            ->leftJoin('divisions', 'divisions.id', '=', 'performance_metrics.division_id')
            ->where(function ($q) {
                $q->whereNull('performance_metrics.dataset_id')
                    ->orWhere('datasets.status', Dataset::STATUS_READY);
            });

        // Non-admin/non-management: batasi ke division_id miliknya.
        $isPrivileged = $user->hasRole(Role::ADMIN) || $user->hasRole(Role::MANAGEMENT);

        $scoped = $baseQuery();
        if (! $isPrivileged) {
            $scoped->where('performance_metrics.division_id', $user->division_id);
        }

        $rows = (clone $scoped)
            ->selectRaw("performance_metrics.period, performance_metrics.division_id, COALESCE(divisions.name, 'Tanpa Divisi') as division_name, SUM(performance_metrics.value) as total")
            ->groupBy('performance_metrics.period', 'performance_metrics.division_id', 'divisions.name')
            ->orderBy('performance_metrics.period')
            ->get();

        // Pivot: label periode unik + satu series per divisi.
        $labels = $rows->pluck('period')
            ->map(fn ($period) => Carbon::parse($period)->format('Y-m-d'))
            ->unique()
            ->sort()
            ->values();

        $series = $rows->groupBy('division_name')->map(function ($group, $name) use ($labels) {
            $byPeriod = $group->keyBy(fn ($row) => Carbon::parse($row->period)->format('Y-m-d'));

            return [
                'name' => $name,
                'data' => $labels->map(fn ($label) => (float) ($byPeriod[$label]->total ?? 0))->values(),
            ];
        })->values();

        // KPI card: total nilai seluruh periode tampil, jumlah dataset READY
        // (mengikuti scoping yang sama), dan jumlah baris metric aktif.
        $readyDatasets = DB::table('datasets')
            ->where('status', Dataset::STATUS_READY)
            ->when(! $isPrivileged, fn ($q) => $q->where('division_id', $user->division_id))
            ->count();

        $metricCount = (clone $scoped)->count();
        $totalValue = (float) $rows->sum('total');

        return response()->json([
            'labels' => $labels,
            'series' => $series,
            'kpi' => [
                'total_value' => $totalValue,
                'ready_datasets' => $readyDatasets,
                'metric_count' => $metricCount,
            ],
        ]);
    }
}
