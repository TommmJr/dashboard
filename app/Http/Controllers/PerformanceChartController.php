<?php

namespace App\Http\Controllers;

use App\Models\Dataset;
use App\Models\Role;
use App\Services\KpiReportConverter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PerformanceChartController extends Controller
{
    /**
     * Data agregasi untuk chart dashboard utama.
     *
     * Kontrak JSON:
     * - labels : daftar periode (Y-m-d) urut naik.
     * - series : [{ name: <nama unit>, data: [<NKO per periode>] }]
     *            — satu series per unit agar ECharts bisa menumpuk
     *            bar + line trend per unit.
     * - y_axis_min : batas bawah sumbu Y chart (dihitung dinamis dari
     *            nilai NKO mentah terendah; NKO bukan besaran mulai 0).
     * - kpi    : { average_nko, ready_datasets, metric_count }
     *
     * Basis agregasi adalah baris NKO saja, BUKAN SUM(value). Kolom value
     * memuat satuan yang tidak sebanding (Rp Juta, Dokumen, Kali, %, indeks
     * 0-110) karena satu dataset KPI memuat target, realisasi, dan skor
     * sekaligus — menjumlahkannya menghasilkan angka tak bermakna.
     * NKO adalah satu-satunya angka yang sebanding lintas unit.
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

        $scoped = function ($query) use ($isPrivileged, $user) {
            if (! $isPrivileged) {
                $query->where('performance_metrics.division_id', $user->division_id);
            }

            return $query;
        };

        // ponytail: MAX dipakai bila satu unit mengunggah NKO lebih dari sekali
        // untuk periode yang sama — nilai tertinggi yang tampil. Ganti ke
        // "dataset terbaru per periode" kalau re-upload jadi kebiasaan.
        $rows = $scoped($baseQuery())
            ->where('performance_metrics.metric_name', 'like', '%'.KpiReportConverter::NKO_SUFFIX)
            ->selectRaw("performance_metrics.period, performance_metrics.division_id, COALESCE(divisions.name, 'Tanpa Divisi') as division_name, MAX(performance_metrics.value) as nko")
            ->groupBy('performance_metrics.period', 'performance_metrics.division_id', 'divisions.name')
            ->orderBy('performance_metrics.period')
            ->get();

        // Pivot: label periode unik + satu series per unit.
        $labels = $rows->pluck('period')
            ->map(fn ($period) => Carbon::parse($period)->format('Y-m-d'))
            ->unique()
            ->sort()
            ->values();

        $series = $rows->groupBy('division_name')->map(function ($group, $name) use ($labels) {
            $byPeriod = $group->keyBy(fn ($row) => Carbon::parse($row->period)->format('Y-m-d'));

            return [
                'name' => $name,
                'data' => $labels->map(fn ($label) => (float) ($byPeriod[$label]->nko ?? 0))->values(),
            ];
        })->values();

        // Sumbu Y dinamis dari NILAI NKO MENTAH (bukan dari $series yang
        // di-pad nol untuk periode kosong) - padding nol akan menyeret axis
        // ke 0 secara keliru. Margin 5 poin di bawah nilai terendah,
        // dibulatkan ke bawah kelipatan 5, tidak boleh negatif.
        // Contoh: NKO terendah 95.5 -> axis mulai di 90.
        $rawMin = $rows->isEmpty() ? 0.0 : (float) $rows->min('nko');
        $yAxisMin = $rawMin > 0 ? max(0, floor(($rawMin - 5) / 5) * 5) : 0.0;

        // KPI card: rata-rata NKO periode terbaru (kondisi terkini), jumlah
        // dataset READY (scoping sama), dan jumlah baris metric aktif.
        $readyDatasets = DB::table('datasets')
            ->where('status', Dataset::STATUS_READY)
            ->when(! $isPrivileged, fn ($q) => $q->where('division_id', $user->division_id))
            ->count();

        $latestPeriod = $labels->last();
        $averageNko = $latestPeriod === null ? 0.0 : (float) $rows
            ->filter(fn ($row) => Carbon::parse($row->period)->format('Y-m-d') === $latestPeriod)
            ->avg('nko');

        $metricCount = $scoped($baseQuery())->count();

        return response()->json([
            'labels' => $labels,
            'series' => $series,
            'y_axis_min' => $yAxisMin,
            'kpi' => [
                'average_nko' => round($averageNko, 2),
                'ready_datasets' => $readyDatasets,
                'metric_count' => $metricCount,
            ],
        ]);
    }
}
