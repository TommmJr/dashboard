<?php

namespace App\Http\Controllers;

use App\Models\Dataset;
use App\Models\Role;
use App\Models\User;
use App\Services\AssetStockConverter;
use App\Services\KpiReportConverter;
use App\Services\StructuredNkoReader;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PerformanceChartController extends Controller
{
    public function __construct(private readonly StructuredNkoReader $structuredNko) {}

    /**
     * Data agregasi untuk chart dashboard utama.
     *
     * Kontrak JSON:
     * - labels : daftar periode (Y-m-d) urut naik.
     * - series : [{ name: <nama unit>, data: [<NKO per periode>] }]
     *            — satu series per unit agar ECharts bisa menumpuk
     *            bar + line trend per unit. Periode yang belum dilaporkan
     *            bernilai null (bukan 0), supaya garis tren terputus dan
     *            tidak terbaca sebagai "NKO nol".
     * - y_axis_min : batas bawah sumbu Y chart (dihitung dinamis dari
     *            nilai NKO mentah terendah; NKO bukan besaran mulai 0).
     * - kpi    : { average_nko, ready_datasets, metric_count }
     *
     * Basis agregasi adalah baris NKO saja, BUKAN SUM(value). Kolom value
     * memuat satuan yang tidak sebanding (Rp Juta, Dokumen, Kali, %, indeks
     * 0-110) karena satu dataset KPI memuat target, realisasi, dan skor
     * sekaligus — menjumlahkannya menghasilkan angka tak bermakna.
     * NKO adalah satu-satunya angka yang sebanding lintas unit.
     * Baris domain Aset (`ASET | ...`, lihat AssetStockConverter::PREFIX)
     * dikeluarkan dari metric_count dan ready_datasets; series dan
     * average_nko aman by construction (hanya baris `% | NKO`).
     *
     * Aturan scoping (per audit keamanan #3):
     * - admin, management & editor_dashboard: agregasi SELURUH divisi.
     * - staf_divisi: hanya divisinya sendiri.
     *
     * Dataset ARCHIVED dikeluarkan dari agregasi (keputusan
     * archive-vs-delete); baris legacy tanpa dataset tetap dihitung.
     * Filter di query, TIDAK mengandalkan filter di view/frontend.
     */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'unit' => ['nullable', 'array'],
            'unit.*' => ['string', 'max:100'],
        ]);
        $user = Auth::user();

        // Admin, management & editor: lintas divisi. Staf: divisinya sendiri.
        $isPrivileged = $user->hasRole(Role::ADMIN) || $user->hasRole(Role::MANAGEMENT)
            || $user->hasRole(Role::EDITOR_DASHBOARD);

        if (config('kpi.read_model') === 'structured') {
            return $this->structuredResponse($filters, $user, $isPrivileged);
        }

        // Query via DB::table (bukan model): cast 'date' pada model
        // menggeser period saat serialisasi (app Asia/Jakarta vs cast
        // menganggap DB UTC). period diformat eksplisit Y-m-d di PHP.
        $baseQuery = fn () => DB::table('performance_metrics')
            ->leftJoin('datasets', 'datasets.id', '=', 'performance_metrics.dataset_id')
            ->where(function ($q) {
                $q->whereNull('performance_metrics.dataset_id')
                    ->orWhere('datasets.status', Dataset::STATUS_READY);
            });

        $scoped = function ($query) use ($isPrivileged, $user) {
            if (! $isPrivileged) {
                $query->where('performance_metrics.division_id', $user->division_id);
            }

            return $query;
        };

        // ponytail: MAX dipakai bila satu unit mengunggah NKO lebih dari sekali
        // untuk periode yang sama — nilai tertinggi yang tampil.
        $rows = $scoped($baseQuery())
            ->where('performance_metrics.metric_name', 'like', '%'.KpiReportConverter::NKO_SUFFIX)
            ->when($filters['start_date'] ?? null, fn ($query, $startDate) => $query->where('performance_metrics.period', '>=', $startDate))
            ->when($filters['end_date'] ?? null, fn ($query, $endDate) => $query->where('performance_metrics.period', '<=', $endDate))
            ->when($filters['unit'] ?? null, fn ($query, $units) => $query->whereIn(
                'performance_metrics.metric_name',
                collect($units)->map(fn (string $unit) => $unit.KpiReportConverter::NKO_SUFFIX),
            ))
            ->selectRaw('performance_metrics.period, performance_metrics.metric_name, MAX(performance_metrics.value) as nko')
            ->groupBy('performance_metrics.period', 'performance_metrics.metric_name')
            ->orderBy('performance_metrics.period')
            ->get()
            ->map(function (object $row): object {
                $row->unit = explode(' | ', $row->metric_name, 2)[0];

                return $row;
            });

        // Pivot: label periode unik + satu series per unit.
        $labels = $rows->pluck('period')
            ->map(fn ($period) => Carbon::parse($period)->format('Y-m-d'))
            ->unique()
            ->sort()
            ->values();

        $series = $rows->groupBy('unit')->map(function ($group, $name) use ($labels) {
            $byPeriod = $group->keyBy(fn ($row) => Carbon::parse($row->period)->format('Y-m-d'));

            return [
                'name' => $name,
                'data' => $labels->map(fn ($label) => isset($byPeriod[$label]) ? (float) $byPeriod[$label]->nko : null)->values(),
            ];
        })->values();

        // Contoh: NKO terendah 95.5 -> axis mulai di 90.
        $rawMin = $rows->isEmpty() ? 0.0 : (float) $rows->min('nko');
        $yAxisMin = $rawMin > 0 ? max(0, floor(($rawMin - 5) / 5) * 5) : 0.0;

        // KPI card: rata-rata NKO periode terbaru (kondisi terkini), jumlah
        // dataset READY (scoping sama), dan jumlah baris metric aktif.
        // Domain Aset (metric berprefix AssetStockConverter::PREFIX) tidak
        // masuk hitungan: satuannya fisik (Bank, MVA, kms) dan bukan kinerja.
        $readyDatasets = DB::table('datasets')
            ->where('status', Dataset::STATUS_READY)
            ->when(! $isPrivileged, fn ($q) => $q->where('division_id', $user->division_id))
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('performance_metrics')
                    ->whereColumn('performance_metrics.dataset_id', 'datasets.id')
                    ->where('performance_metrics.metric_name', 'like', AssetStockConverter::PREFIX.'%');
            })
            ->count();

        $latestPeriod = $labels->last();
        $averageNko = $latestPeriod === null ? 0.0 : (float) $rows
            ->filter(fn ($row) => Carbon::parse($row->period)->format('Y-m-d') === $latestPeriod)
            ->avg('nko');

        $metricCount = $scoped($baseQuery())
            ->where('performance_metrics.metric_name', 'not like', AssetStockConverter::PREFIX.'%')
            ->count();

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

    /**
     * R1 branch terstruktur
     * Sumber NKO: indicators.category = 'nko' + performance_observations.nilai,
     * pada DATASET TERBARU per periode (READY, urut created_at DESC lalu id
     * DESC). Legacy membaca performance_metrics dengan LEFT JOIN dan MAX(value);
     * branch ini memakai INNER JOIN — konsekuensinya disengaja.
     *
     * DIVERGENSI YANG DISAHKAN (jangan diklaim setara):
     *
     *   1. Baris legacy tanpa dataset (dataset_id NULL) TIDAK muncul di sini:
     *      aturan "data terbaru" bersandar pada dataset, dan baris tanpa dataset
     *      tidak punya otoritas versi. Legacy tetap menampilkannya.
     *   2. Duplikat dalam satu periode tidak lagi diringkas MAX: yang tampil
     *      adalah nilai pada dataset terbaru saja.
     *
     * `kpi.ready_datasets` dan `kpi.metric_count` tetap dihitung dari
     * performance_metrics (keduanya memang membaca baris metric, bukan NKO) —
     * perilaku legacy dipertahankan apa adanya.
     *
     * @param  array<string, mixed>  $filters
     */
    private function structuredResponse(array $filters, User $user, bool $isPrivileged)
    {
        $divisionId = $isPrivileged ? null : $user->division_id;

        $rows = $this->structuredNko->latestPerPeriod(
            $divisionId,
            $filters['start_date'] ?? null,
            $filters['end_date'] ?? null,
        );

        // Filter unit opsional, sama seperti jalur legacy.
        if (! empty($filters['unit'])) {
            $rows = $rows->filter(fn (array $row) => in_array($row['unit'], $filters['unit'], true));
        }

        $labels = $rows->pluck('period')
            ->map(fn ($period) => Carbon::parse($period)->format('Y-m-d'))
            ->unique()
            ->sort()
            ->values();

        $series = $rows->groupBy('unit')->map(function ($group, $name) use ($labels) {
            $byPeriod = $group->keyBy(fn (array $row) => Carbon::parse($row['period'])->format('Y-m-d'));

            return [
                'name' => $name,
                'data' => $labels->map(fn ($label) => isset($byPeriod[$label]) ? (float) $byPeriod[$label]['nko'] : null)->values(),
            ];
        })->values();

        $rawMin = $rows->isEmpty() ? 0.0 : (float) $rows->min('nko');
        $yAxisMin = $rawMin > 0 ? max(0, floor(($rawMin - 5) / 5) * 5) : 0.0;

        $readyDatasets = DB::table('datasets')
            ->where('status', Dataset::STATUS_READY)
            ->when(! $isPrivileged, fn ($q) => $q->where('division_id', $user->division_id))
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('performance_metrics')
                    ->whereColumn('performance_metrics.dataset_id', 'datasets.id')
                    ->where('performance_metrics.metric_name', 'like', AssetStockConverter::PREFIX.'%');
            })
            ->count();

        $baseQuery = fn () => DB::table('performance_metrics')
            ->leftJoin('datasets', 'datasets.id', '=', 'performance_metrics.dataset_id')
            ->where(function ($q) {
                $q->whereNull('performance_metrics.dataset_id')
                    ->orWhere('datasets.status', Dataset::STATUS_READY);
            });

        $metricCount = (function () use ($baseQuery, $isPrivileged, $user) {
            $query = $baseQuery()
                ->where('performance_metrics.metric_name', 'not like', AssetStockConverter::PREFIX.'%');

            if (! $isPrivileged) {
                $query->where('performance_metrics.division_id', $user->division_id);
            }

            return $query->count();
        })();

        $latestPeriod = $labels->last();
        $averageNko = $latestPeriod === null ? 0.0 : (float) $rows
            ->filter(fn (array $row) => Carbon::parse($row['period'])->format('Y-m-d') === $latestPeriod)
            ->avg('nko');

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
