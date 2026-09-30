<?php

namespace App\Http\Controllers;

use App\Enums\DatasetStatus;
use App\Models\AuditLog;
use App\Models\Dataset;
use App\Models\PerformanceMetric;
use App\Models\Role;
use App\Models\User;
use App\Services\AssetStockReport;
use App\Services\KpiReportConverter;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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
     * Prefix atribut yang ditampilkan pada grafik Aset per UPT.
     *
     * Sesuai fokus prototype yang disetujui mentor (2026-09-25): Tower,
     * Transmisi, dan Trafo. Sengaja daftar prefix eksplisit, BUKAN
     * str_contains: nama atribut lain di rekap bisa memuat kata-kata ini di
     * tengah (mis. "DIAMETER | CUT OFF CB") sehingga pencocokan substring
     * meloloskan atribut di luar fokus.
     *
     * @var list<string>
     */
    private const ASET_ATTRIBUTE_PREFIXES = ['Tower ', 'Transmisi ', 'Trafo '];

    /**
     * Rakit daftar widget terurut untuk user aktif, lalu render view.
     */
    public function __invoke(Request $request): View
    {
        $config = config('dashboard_layouts');
        $user = Auth::user();
        $roleSlug = $user?->loadMissing('role')->role?->slug;
        $layout = $config['layouts'][$roleSlug] ?? $config['default'];

        // Filter dashboard datang dari query string (form GET, bukan fetch):
        // halaman dimuat ulang sehingga widget SERVER-SIDE (nko-ranking) dan
        // widget CLIENT-SIDE (chart) memakai rentang yang sama. Tanggal tidak
        // valid diabaikan, bukan error — ini halaman GET, bukan endpoint API.
        $filters = [
            'start_date' => $this->dateOrNull($request->query('start_date')),
            'end_date' => $this->dateOrNull($request->query('end_date')),
            'units' => array_values(array_filter(
                (array) $request->query('unit', []),
                fn ($unit): bool => is_string($unit) && $unit !== '',
            )),
        ];

        if ($filters['start_date'] !== null && $filters['end_date'] !== null
            && $filters['start_date'] > $filters['end_date']) {
            $filters['start_date'] = null;
            $filters['end_date'] = null;
        }

        $widgets = collect($layout)
            ->map(fn (string $key) => isset($config['widgets'][$key])
                ? ['key' => $key] + $config['widgets'][$key]
                : null)
            ->filter()
            ->filter(fn (array $widget) => $widget['permission'] === null
                || Gate::allows($widget['permission']))
            ->map(function (array $widget, int $key) use ($user, $filters) {
                // Instance = indeks layout: unik per render dalam satu
                // response meski widget key yang sama tampil berulang.
                $widget['instance'] = $key;
                $widget['data'] = $this->dataFor($widget['key'], $user, $filters);

                return $widget;
            })
            ->values()
            ->all();

        return view('dashboard', [
            'widgets' => $widgets,
            'filters' => $filters,
            'availableUnits' => $this->availableUnits($user),
        ]);
    }

    /**
     * Terima hanya tanggal Y-m-d; selain itu null. Dipakai untuk query string
     * halaman dashboard agar nilai rusak tidak pernah sampai ke query DB.
     */
    private function dateOrNull(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    /**
     * Semua unit yang punya baris NKO di divisi user (untuk dropdown filter).
     * Ditampilkan SERVER-SIDE agar dropdown tetap lengkap meski filter aktif
     * (kalau diambil dari payload API, unit yang ter-filter akan menghilang
     * dari daftar pilihannya sendiri).
     *
     * @return list<string>
     */
    private function availableUnits(?User $user): array
    {
        $isPrivileged = $this->isPrivileged($user);

        return DB::table('performance_metrics')
            ->join('datasets', 'datasets.id', '=', 'performance_metrics.dataset_id')
            ->where('datasets.status', Dataset::STATUS_READY)
            ->where('performance_metrics.metric_name', 'like', '%'.KpiReportConverter::NKO_SUFFIX)
            ->when(! $isPrivileged, fn ($query) => $query->where('performance_metrics.division_id', $user?->division_id))
            ->distinct()
            ->pluck('performance_metrics.metric_name')
            ->map(fn (string $metric): string => explode(' | ', $metric, 2)[0])
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function isPrivileged(?User $user): bool
    {
        return $user !== null && (
            $user->hasRole(Role::ADMIN)
            || $user->hasRole(Role::MANAGEMENT)
            || $user->hasRole(Role::EDITOR_DASHBOARD)
        );
    }

    /**
     * Data server-side ringan per widget. Widget client-side (kpi-summary,
     * trend-chart) mengambil data sendiri via /api/performance-data -> null.
     *
     * @param  array{start_date: ?string, end_date: ?string}  $filters
     */
    private function dataFor(string $key, ?User $user = null, array $filters = []): mixed
    {
        return match ($key) {
            'nko-ranking' => $this->nkoRanking($user, $filters['start_date'] ?? null, $filters['end_date'] ?? null),
            'aset-upt-chart' => $this->asetUptChart($user),
            'recent-audit' => $this->recentAudit($user),
            'failed-datasets' => $this->failedDatasets(),
            'admin-summary' => $this->adminSummary(),
            'admin-quick-actions' => null,
            default => null,
        };
    }

    private function adminSummary(): array
    {
        return [
            'total_datasets' => Dataset::query()->count(),
            'failed_datasets' => Dataset::query()
                ->where('status', DatasetStatus::FAILED)
                ->count(),
            'audit_today' => AuditLog::query()
                ->whereDate('created_at', today())
                ->count(),
            'total_users' => User::query()->count(),
        ];
    }

    /**
     * Data ringkasan dan detail dataset gagal untuk dashboard admin.
     *
     * @return array{count: int, rows: Collection<int, Dataset>}
     */
    private function failedDatasets(): array
    {
        return [
            'count' => Dataset::query()
                ->where('status', DatasetStatus::FAILED)
                ->count(),
            'rows' => Dataset::query()
                ->with(['division:id,name', 'user:id,name'])
                ->where('status', DatasetStatus::FAILED)
                ->latest()
                ->limit(3)
                ->get(),
        ];
    }

    /**
     * Lima entri audit log terbaru, discope sesuai hak akses user:
     * - Pemegang audit.view_all (Admin & Management): seluruh entri termasuk aktivitas login/user.
     * - Tanpa audit.view_all (mis. Editor Dashboard): dibatasi ke entri Dataset & PerformanceMetric.
     *
     * @return Collection<int, AuditLog>
     */
    private function recentAudit(?User $user): Collection
    {
        $dataOnly = ! ($user?->can('audit.view_all') ?? false);

        return AuditLog::query()
            ->with('user:id,name')
            ->when($dataOnly, fn ($query) => $query->whereIn('auditable_type', [
                (new Dataset)->getMorphClass(),
                (new PerformanceMetric)->getMorphClass(),
            ]))
            ->latest('created_at')
            ->limit(5)
            ->get();
    }

    /**
     * Data grafik stok aset per UPT untuk periode terbaru.
     *
     * Bentuk siap render ECharts bar: labels = UPT, satu series per atribut
     * (atribut tidak dicampur dalam satu series karena satuannya beda:
     * Bank, MVA, kms). Snapshot, bukan time-series — tanpa line/trend.
     *
     * Series dibatasi ke prefix ASET_ATTRIBUTE_PREFIXES (Tower, Transmisi,
     * Trafo) sesuai fokus prototype; atribut di luar fokus tetap tersimpan
     * sebagai data, hanya tidak digambar.
     *
     * Scoping divisi: hanya role privileged (Admin, Management, Editor
     * Dashboard) yang melihat lintas divisi. Staf Divisi dibatasi ke
     * divisinya sendiri; divisi selain pemilik data Aset mendapat data
     * kosong sehingga widget menampilkan empty state, bukan kebocoran data.
     * Pola ini sama dengan nkoRanking() dan PerformanceChartController.
     *
     * @return array{period: ?string, labels: list<string>, series: list<array{name: string, data: list<float>}>}
     */
    private function asetUptChart(?User $user): array
    {
        $isPrivileged = $user && (
            $user->hasRole(Role::ADMIN)
            || $user->hasRole(Role::MANAGEMENT)
            || $user->hasRole(Role::EDITOR_DASHBOARD)
        );

        $divisionId = $isPrivileged ? null : $user?->division_id;

        $report = app(AssetStockReport::class)->totalsByUpt(divisionId: $divisionId);

        $upts = $report['totals']->pluck('upt')->unique()->values();

        // Batasi ke fokus prototype: hanya kelompok Tower, Transmisi, dan
        // Trafo. Atribut di luar itu (SIRKIT, DIAMETER, Joint, Gardu Induk)
        // tetap tersimpan sebagai data, hanya tidak digambar sebagai series.
        // Kalau tidak ada satu pun yang cocok, series dikosongkan supaya
        // widget menampilkan empty state — bukan diam-diam menampilkan
        // seluruh atribut seperti perilaku lama.
        $allAttributes = $report['totals']->pluck('attribute')->unique()->values();
        $attributes = $allAttributes->filter(
            fn (string $attr): bool => Str::startsWith($attr, self::ASET_ATTRIBUTE_PREFIXES)
        )->values();

        $byKey = $report['totals']->keyBy(fn (object $total): string => $total->upt."\0".$total->attribute);

        $series = $attributes->map(fn (string $attribute): array => [
            'name' => $attribute,
            'data' => $upts->map(fn (string $upt): float => (float) ($byKey[$upt."\0".$attribute]->total ?? 0))->all(),
        ])->values()->all();

        return ['period' => $report['period'], 'labels' => $upts->all(), 'series' => $series];
    }

    /**
     * Peringkat NKO per unit.
     *
     * Hanya baris berakhiran '| NKO' (dihasilkan KpiReportConverter) yang dihitung.
     * Aturan scoping divisi:
     * - Admin, Management, dan Editor Dashboard: lintas divisi.
     * - Staf Divisi / role lain: hanya divisinya sendiri.
     *
     * Filter tanggal (opsi 2026-10-01): bila rentang diberikan, periode yang
     * dipakai = periode TERAKHIR DI DALAM rentang itu. NKO adalah indeks, bukan
     * besaran aditif, jadi rentang tidak dijumlahkan/dirata-ratakan — hanya
     * menggeser "periode terbaru" ke dalam rentang. Filter unit sengaja TIDAK
     * diterapkan di sini (keputusan user 2026-10-01).
     *
     * @return Collection<int, object>
     */
    private function nkoRanking(?User $user, ?string $startDate = null, ?string $endDate = null): Collection
    {
        $isPrivileged = $this->isPrivileged($user);

        $base = fn () => DB::table('performance_metrics')
            ->join('datasets', 'datasets.id', '=', 'performance_metrics.dataset_id')
            ->where('datasets.status', Dataset::STATUS_READY)
            ->where('performance_metrics.metric_name', 'like', '%'.KpiReportConverter::NKO_SUFFIX)
            ->when(! $isPrivileged, fn ($query) => $query->where('performance_metrics.division_id', $user?->division_id))
            ->when($startDate !== null, fn ($query) => $query->where('performance_metrics.period', '>=', $startDate))
            ->when($endDate !== null, fn ($query) => $query->where('performance_metrics.period', '<=', $endDate));

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
