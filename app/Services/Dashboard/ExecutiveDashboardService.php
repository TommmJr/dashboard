<?php

namespace App\Services\Dashboard;

use App\Enums\DatasetStatus;
use App\Http\Controllers\Concerns\ScopesKpiUnits;
use App\Models\AuditLog;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\PerformanceMetric;
use App\Models\Role;
use App\Models\User;
use App\Services\AssetStockConverter;
use App\Services\AssetStockReport;
use App\Support\DatasetFreshness;
use Carbon\Carbon;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Layanan baca data ringkasan eksekutif, admin, audit, aset UPT, dan status dataset dashboard.
 */
class ExecutiveDashboardService
{
    use ScopesKpiUnits;

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
    public const ASET_ATTRIBUTE_PREFIXES = ['Tower ', 'Transmisi ', 'Trafo '];

    private const KPI_READ_SERVICE_CLASS = 'App\\Services\\Dashboard\\KpiDashboardReadService';

    public function isPrivileged(?User $user): bool
    {
        return $user !== null && (
            $user->hasRole(Role::ADMIN)
            || $user->hasRole(Role::MANAGEMENT)
            || $user->hasRole(Role::EDITOR_DASHBOARD)
        );
    }

    /**
     * Ringkasan metadata SSR untuk Executive Header & fallback NKO di L1 KPI.
     *
     * @param  list<array<string, mixed>>  $widgets
     * @param  array{start_date?: ?string, end_date?: ?string, units?: list<string>}  $filters
     * @return array<string, mixed>
     */
    public function buildExecutiveMeta(
        ?User $user,
        array $widgets,
        array $filters,
        ?Closure $kpiSummaryFallback = null,
        ?Closure $nkoRankingFallback = null,
        ?Closure $latestPeriodFallback = null,
    ): array {
        $startDate = $filters['start_date'] ?? null;
        $endDate = $filters['end_date'] ?? null;
        $filterUnits = $filters['units'] ?? [];

        $widgetMap = collect($widgets)->keyBy('key');
        $summaryData = $widgetMap->get('kpi-summary')['data'] ?? null;

        if (! is_array($summaryData)) {
            $summaryData = $this->resolveKpiSummaryFallback($user, $startDate, $endDate, $kpiSummaryFallback);
        }

        $ragData = $widgetMap->get('kpi-rag-status')['data'] ?? null;

        if ($widgetMap->has('nko-ranking')) {
            $nkoRows = collect($widgetMap->get('nko-ranking')['data'] ?? []);
        } else {
            $nkoRows = $this->resolveNkoRankingFallback($user, $startDate, $endDate, $nkoRankingFallback);
        }

        if ($filterUnits !== []) {
            $nkoRows = $nkoRows->filter(
                fn (object $row): bool => in_array($row->unit ?? null, $filterUnits, true)
            )->values();
        }

        $averageNko = $nkoRows->isNotEmpty()
            ? round((float) $nkoRows->avg('nko'), 2)
            : null;

        $freshness = DatasetFreshness::forKpi();
        $hasDateFilter = $startDate !== null || $endDate !== null;

        $period = is_array($ragData) ? ($ragData['period'] ?? null) : null;
        if ($period === null) {
            if (! $hasDateFilter && $freshness['latest_period'] !== null) {
                $period = $freshness['latest_period'];
            } else {
                $period = $this->resolveLatestPeriodFallback($user, $startDate, $endDate, $latestPeriodFallback);
            }
        }

        $isPrivileged = $this->isPrivileged($user);
        $kpiDivisionId = Division::where('slug', 'perencanaan-pengusahaan')->value('id');

        if ($kpiDivisionId === null) {
            $readyDatasets = 0;
        } elseif (! $isPrivileged && ($user?->division_id === null || (int) $user->division_id !== (int) $kpiDivisionId)) {
            $readyDatasets = 0;
        } else {
            $readyDatasets = DB::table('datasets')
                ->where('status', Dataset::STATUS_READY)
                ->where('division_id', $kpiDivisionId)
                ->whereNotExists(function ($query): void {
                    $query->select(DB::raw(1))
                        ->from('performance_metrics')
                        ->whereColumn('performance_metrics.dataset_id', 'datasets.id')
                        ->where('performance_metrics.metric_name', 'like', AssetStockConverter::PREFIX.'%');
                })
                ->count();
        }

        $visibleUnitsConfig = config('kpi.visible_units');
        $unitLabel = $filterUnits !== []
            ? implode(', ', $filterUnits)
            : (is_array($visibleUnitsConfig) && $visibleUnitsConfig !== [] ? implode(', ', $visibleUnitsConfig) : 'Semua Unit');

        $periodLabel = $period !== null
            ? DatasetFreshness::formatMonthYear((string) $period)
            : null;

        $achievedCount = $summaryData['achieved_count'] ?? null;
        $totalCount = $summaryData['total_count'] ?? null;

        return [
            'unit_label' => $unitLabel,
            'period' => $period,
            'period_label' => $periodLabel,
            'average_nko' => $averageNko,
            'achieved_count' => $achievedCount,
            'total_count' => $totalCount,
            'ready_datasets' => $readyDatasets,
            'has_data' => $averageNko !== null || (($summaryData['has_data'] ?? false) === true),
            'freshness' => $freshness,
        ];
    }

    /**
     * Ringkasan metrik admin: jumlah dataset, dataset gagal, audit hari ini, dan total user.
     *
     * @return array{total_datasets: int, failed_datasets: int, audit_today: int, total_users: int}
     */
    public function adminSummary(): array
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
    public function failedDatasets(): array
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
    public function recentAudit(?User $user): Collection
    {
        $dataOnly = ! ($user?->can('audit.view_all') ?? false);

        return AuditLog::query()
            ->with('user:id,name')
            ->cleanAuditTrail()
            ->when($dataOnly, fn ($query) => $query->whereIn('auditable_type', [
                (new Dataset)->getMorphClass(),
                (new PerformanceMetric)->getMorphClass(),
            ]))
            ->latest('created_at')
            ->limit(5)
            ->get();
    }

    /**
     * Data grafik stok aset per UPT untuk periode terpilih atau terbaru.
     *
     * @return array{
     *     period: ?string,
     *     period_uit: ?string,
     *     period_upt: ?string,
     *     available_periods: list<string>,
     *     freshness: array{has_dataset: bool, last_updated_label: string, last_updated_iso: ?string, latest_period: ?string, latest_period_label: string, is_stale: bool, stale_message: ?string},
     *     labels: list<string>,
     *     series: list<array{name: string, data: list<float>}>,
     *     summary: array{total_tower: float, total_transmisi: float, total_trafo: float, trafo_bank?: ?float, trafo_unit?: ?float, source?: string},
     *     summary_upt: array{total_tower: float, total_transmisi: float, total_trafo: float},
     *     table_rows: list<array{upt: string, tower: ?float, transmisi: ?float, trafo: ?float}>,
     *     series_by_category: array{tower: list<array{name: string, data: list<float>}>, transmisi: list<array{name: string, data: list<float>}>, trafo: list<array{name: string, data: list<float>}>},
     *     ultg_by_upt: array<string, list<array<string, mixed>>>,
     *     capacity_ratios: array<string, mixed>
     * }
     */
    public function asetUptChart(?User $user, ?string $period = null): array
    {
        $emptyFreshness = DatasetFreshness::empty('Belum ada data aktif', 'Belum ada dataset aset aktif');

        $isPrivileged = $this->isPrivileged($user);
        $isStaff = $user !== null && $user->hasRole(Role::STAFF_DIVISI);
        $divisionSlug = $user?->division?->slug;

        // Otorisasi fail-closed: Staf Divisi yang bukan managemen-aset (dan bukan data-aset)
        // tidak boleh menerima data aset sama sekali.
        if ($isStaff && ! in_array($divisionSlug, ['managemen-aset', 'data-aset'], true)) {
            return $this->emptyAsetUptChartPayload(null, $emptyFreshness);
        }

        if (! $isPrivileged && $user?->division_id === null) {
            return $this->emptyAsetUptChartPayload(null, $emptyFreshness);
        }

        $divisionId = $isPrivileged ? null : $user?->division_id;

        $assetStockReport = app(AssetStockReport::class);
        $availablePeriods = $assetStockReport->availablePeriods($divisionId);
        $resolvedPeriod = $assetStockReport->resolveRequestedPeriod($period, $divisionId);
        $report = $assetStockReport->totalsByUpt($resolvedPeriod, $divisionId);
        $freshness = DatasetFreshness::forAsset($divisionId, $report['period']);

        $upts = $report['totals']->pluck('upt')->unique()->values();

        $allAttributes = $report['totals']->pluck('attribute')->unique()->values();
        $attributes = $allAttributes->filter(
            fn (string $attr): bool => Str::startsWith($attr, self::ASET_ATTRIBUTE_PREFIXES)
        )->values();

        if ($upts->isEmpty() || $attributes->isEmpty()) {
            return $this->emptyAsetUptChartPayload($report['period'], $freshness, $availablePeriods);
        }

        $byKey = $report['totals']->keyBy(fn (object $total): string => $total->upt."\0".$total->attribute);

        $series = $attributes->map(fn (string $attribute): array => [
            'name' => $attribute,
            'data' => $upts->map(fn (string $upt): float => (float) ($byKey[$upt."\0".$attribute]->total ?? 0))->all(),
        ])->values()->all();

        $seriesByCategory = [
            'tower' => array_values(array_filter($series, fn (array $s): bool => Str::startsWith($s['name'], 'Tower '))),
            'transmisi' => array_values(array_filter($series, fn (array $s): bool => Str::startsWith($s['name'], 'Transmisi '))),
            'trafo' => array_values(array_filter($series, fn (array $s): bool => Str::startsWith($s['name'], 'Trafo ') && Str::contains($s['name'], 'MVA'))),
        ];

        $canonicalUpts = AssetStockReport::CANONICAL_UPTS;
        $totalsByUptName = $report['totals']->groupBy('upt');
        $tableRows = [];
        $totalTowerUpt = 0.0;
        $totalTransmisiUpt = 0.0;
        $totalTrafoUpt = 0.0;

        foreach ($canonicalUpts as $upt) {
            $uptTower = null;
            $uptTransmisi = null;
            $uptTrafo = null;

            foreach ($totalsByUptName->get($upt, collect()) as $metricRow) {
                $attrName = (string) $metricRow->attribute;
                $val = (float) $metricRow->total;
                if (Str::startsWith($attrName, 'Tower ')) {
                    $uptTower = ($uptTower ?? 0.0) + $val;
                } elseif (Str::startsWith($attrName, 'Transmisi ')) {
                    $uptTransmisi = ($uptTransmisi ?? 0.0) + $val;
                } elseif (Str::startsWith($attrName, 'Trafo ') && Str::contains($attrName, 'MVA')) {
                    $uptTrafo = ($uptTrafo ?? 0.0) + $val;
                }
            }

            $totalTowerUpt += $uptTower ?? 0.0;
            $totalTransmisiUpt += $uptTransmisi ?? 0.0;
            $totalTrafoUpt += $uptTrafo ?? 0.0;

            $tableRows[] = [
                'upt' => $upt,
                'tower' => $uptTower !== null ? round($uptTower, 0) : null,
                'transmisi' => $uptTransmisi !== null ? round($uptTransmisi, 1) : null,
                'trafo' => $uptTrafo !== null ? round($uptTrafo, 1) : null,
            ];
        }

        $uitReport = $assetStockReport->totalsByUit($report['period'], $divisionId);
        if ($uitReport['totals']->isEmpty() && $report['period'] !== null) {
            $uitReport = $assetStockReport->totalsByUit(null, $divisionId);
        }
        $uitTotals = $uitReport['totals'];
        $hasUitData = $uitTotals->isNotEmpty();

        $uitTower = $hasUitData
            ? (float) $uitTotals->filter(fn (object $t): bool => Str::startsWith($t->kelompok ?? '', 'Tower '))->sum('total')
            : null;
        $uitTransmisi = $hasUitData
            ? (float) $uitTotals->filter(fn (object $t): bool => Str::startsWith($t->kelompok ?? '', 'Transmisi ') && (Str::contains($t->satuan ?? '', 'kms') || Str::contains($t->attribute ?? '', 'kms')))->sum('total')
            : null;
        $uitTrafo = $hasUitData
            ? (float) $uitTotals->filter(fn (object $t): bool => Str::startsWith($t->kelompok ?? '', 'Trafo ') && (Str::contains($t->satuan ?? '', 'MVA') || Str::contains($t->attribute ?? '', 'MVA')))->sum('total')
            : null;

        $capacityRatios = $assetStockReport->capacityRatios($report['period'], $divisionId);
        $trafoOverall = is_array($capacityRatios['trafo']['overall'] ?? null)
            ? $capacityRatios['trafo']['overall']
            : [];

        $summary = [
            'total_tower' => $uitTower ?? $totalTowerUpt,
            'total_transmisi' => round($uitTransmisi ?? $totalTransmisiUpt, 1),
            'total_trafo' => round($uitTrafo ?? $totalTrafoUpt, 1),
            'trafo_bank' => isset($trafoOverall['bank']) && is_numeric($trafoOverall['bank']) ? (float) $trafoOverall['bank'] : null,
            'trafo_unit' => isset($trafoOverall['unit']) && is_numeric($trafoOverall['unit']) ? (float) $trafoOverall['unit'] : null,
            'source' => $hasUitData ? 'uit' : 'upt',
        ];

        $ultgReport = $assetStockReport->totalsByUltg($report['period'], $divisionId);
        $ultgByUptFocused = [];
        foreach ($ultgReport['by_upt'] as $uptKey => $ultgRows) {
            $ultgByUptFocused[$uptKey] = array_map(function (array $uRow): array {
                $focusedAttrs = [];
                foreach ($uRow['attributes'] as $attrKey => $attrVal) {
                    if (Str::startsWith((string) $attrKey, self::ASET_ATTRIBUTE_PREFIXES)) {
                        $focusedAttrs[$attrKey] = $attrVal;
                    }
                }
                $uRow['attributes'] = $focusedAttrs;

                return $uRow;
            }, $ultgRows);
        }

        return [
            'period' => $report['period'],
            'period_uit' => $uitReport['period'] ?? $report['period'],
            'period_upt' => $report['period'],
            'available_periods' => $availablePeriods,
            'freshness' => $freshness,
            'labels' => $upts->all(),
            'series' => $series,
            'summary' => $summary,
            'summary_upt' => [
                'total_tower' => $totalTowerUpt,
                'total_transmisi' => round($totalTransmisiUpt, 1),
                'total_trafo' => round($totalTrafoUpt, 1),
            ],
            'table_rows' => $tableRows,
            'series_by_category' => $seriesByCategory,
            'ultg_by_upt' => $ultgByUptFocused,
            'capacity_ratios' => $capacityRatios,
        ];
    }

    /**
     * Ringkasan dataset organisasi: periode data terbaru yang aktif, jumlah
     * dataset per status, dan jumlah baris aktif periode terbaru.
     *
     * @return array{latest_period: ?string, ready: int, archived: int, failed: int, latest_rows: int, fresh: bool}
     */
    public function datasetStatus(?User $user): array
    {
        $isPrivileged = $this->isPrivileged($user);
        $scope = fn ($query) => $query->when(! $isPrivileged, fn ($q) => $q->where('division_id', $user?->division_id));

        if ($this->kpiReadModelStructured()) {
            return $this->datasetStatusStructured($user, $scope);
        }

        $latestPeriod = $this->latestLegacyReadyPeriod($user);
        $latestRows = 0;
        if ($latestPeriod !== null) {
            $latestRows = $this->countVisibleUnitRows(DB::table('performance_metrics')
                ->join('datasets', 'datasets.id', '=', 'performance_metrics.dataset_id')
                ->where('datasets.status', Dataset::STATUS_READY)
                ->where('performance_metrics.period', $latestPeriod)
                ->when(! $isPrivileged, fn ($q) => $q->where('performance_metrics.division_id', $user?->division_id)));
        }

        return [
            'latest_period' => $latestPeriod,
            'ready' => $scope(Dataset::query())->where('status', Dataset::STATUS_READY)->count(),
            'archived' => $scope(Dataset::query())->where('status', Dataset::STATUS_ARCHIVED)->count(),
            'failed' => $scope(Dataset::query())->where('status', DatasetStatus::FAILED)->count(),
            'latest_rows' => $latestRows,
            'fresh' => $latestPeriod !== null && $latestPeriod >= now()->subDays(45)->toDateString(),
        ];
    }

    /**
     * R5 sumber terstruktur dengan fallback ke performance_metrics untuk divisi non-KPI.
     *
     * @return array{latest_period: ?string, ready: int, archived: int, failed: int, latest_rows: int, fresh: bool}
     */
    public function datasetStatusStructured(?User $user, ?Closure $scope = null): array
    {
        $isPrivileged = $this->isPrivileged($user);
        $scope ??= fn ($query) => $query->when(! $isPrivileged, fn ($q) => $q->where('division_id', $user?->division_id));

        $structuredPeriod = $this->latestStructuredReadyPeriod($user);
        $isNonKpiFallback = false;

        if ($structuredPeriod !== null) {
            $max = $structuredPeriod;
        } else {
            $max = ($isPrivileged || $user?->division_id !== null) ? $this->latestLegacyReadyPeriod($user) : null;
            if ($max !== null) {
                $isNonKpiFallback = true;
            }
        }

        $latestPeriod = $max === null ? null : Carbon::parse($max)->endOfMonth()->toDateString();
        $visible = $this->visibleUnits();
        $latestRows = 0;

        if ($latestPeriod !== null) {
            if (! $isNonKpiFallback) {
                $latestRows = DB::table('performance_observations')
                    ->join('datasets', 'datasets.id', '=', 'performance_observations.dataset_id')
                    ->join('org_units', 'org_units.id', '=', 'performance_observations.org_unit_id')
                    ->where('datasets.status', Dataset::STATUS_READY)
                    ->whereBetween('performance_observations.period', [Carbon::parse($latestPeriod)->startOfMonth()->toDateString(), $latestPeriod])
                    ->when($visible !== null, fn ($q) => $q->whereIn('org_units.name', $visible))
                    ->when(! $isPrivileged, fn ($q) => $q->where('performance_observations.division_id', $user?->division_id))
                    ->count();
            } else {
                $metricQuery = DB::table('performance_metrics')
                    ->join('datasets', 'datasets.id', '=', 'performance_metrics.dataset_id')
                    ->where('datasets.status', Dataset::STATUS_READY)
                    ->whereBetween('performance_metrics.period', [Carbon::parse($latestPeriod)->startOfMonth()->toDateString(), $latestPeriod])
                    ->when(! $isPrivileged, fn ($q) => $q->where('performance_metrics.division_id', $user?->division_id));

                if (! $isPrivileged && $user?->division?->slug !== 'perencanaan-pengusahaan') {
                    $latestRows = $metricQuery->count();
                } else {
                    $latestRows = $this->countVisibleUnitRows($metricQuery);
                }
            }
        }

        $ready = $scope(Dataset::query())->where('status', Dataset::STATUS_READY)->count();
        $archived = $scope(Dataset::query())->where('status', Dataset::STATUS_ARCHIVED)->count();
        $failed = $scope(Dataset::query())->where('status', DatasetStatus::FAILED)->count();

        return [
            'latest_period' => $latestPeriod,
            'ready' => $ready,
            'archived' => $archived,
            'failed' => $failed,
            'latest_rows' => $latestRows,
            'fresh' => $latestPeriod !== null && $latestPeriod >= now()->subDays(45)->toDateString(),
        ];
    }

    public function latestReadyPeriod(?User $user, ?string $startDate = null, ?string $endDate = null): ?string
    {
        return $this->kpiReadModelStructured()
            ? $this->latestStructuredReadyPeriod($user, $startDate, $endDate)
            : $this->latestLegacyReadyPeriod($user, $startDate, $endDate);
    }

    public function kpiReadModelStructured(): bool
    {
        return config('kpi.read_model') === 'structured';
    }

    public function latestLegacyReadyPeriod(?User $user, ?string $startDate = null, ?string $endDate = null): ?string
    {
        $isPrivileged = $this->isPrivileged($user);

        $max = DB::table('performance_metrics')
            ->join('datasets', 'datasets.id', '=', 'performance_metrics.dataset_id')
            ->where('datasets.status', Dataset::STATUS_READY)
            ->when(! $isPrivileged, fn ($query) => $query->where('performance_metrics.division_id', $user?->division_id))
            ->when($startDate !== null, fn ($query) => $query->where('performance_metrics.period', '>=', $startDate))
            ->when($endDate !== null, fn ($query) => $query->where('performance_metrics.period', '<=', $endDate))
            ->max('performance_metrics.period');

        return $max !== null ? (string) $max : null;
    }

    public function latestStructuredReadyPeriod(?User $user, ?string $startDate = null, ?string $endDate = null): ?string
    {
        $isPrivileged = $this->isPrivileged($user);

        if (! $isPrivileged && $user?->division_id === null) {
            return null;
        }

        $max = DB::table('performance_observations')
            ->join('datasets', 'datasets.id', '=', 'performance_observations.dataset_id')
            ->where('datasets.status', Dataset::STATUS_READY)
            ->when(! $isPrivileged, fn ($query) => $query->where('performance_observations.division_id', $user?->division_id))
            ->when($startDate !== null, fn ($query) => $query->where('performance_observations.period', '>=', $startDate))
            ->when($endDate !== null, fn ($query) => $query->where('performance_observations.period', '<=', $endDate))
            ->max('performance_observations.period');

        return $max !== null ? (string) $max : null;
    }

    /**
     * @return array{achieved_count: ?int, total_count: ?int, has_data: bool}
     */
    private function resolveKpiSummaryFallback(?User $user, ?string $startDate, ?string $endDate, ?Closure $fallback): array
    {
        if ($fallback !== null) {
            $result = $fallback($user, $startDate, $endDate);

            return is_array($result) ? $result : ['achieved_count' => null, 'total_count' => null, 'has_data' => false];
        }

        $service = $this->resolveKpiReadService();
        if ($service !== null) {
            $callable = [$service, 'kpiSummary'];
            if (is_callable($callable)) {
                $result = $callable($user, $startDate, $endDate);

                return is_array($result) ? $result : ['achieved_count' => null, 'total_count' => null, 'has_data' => false];
            }
        }

        return [
            'achieved_count' => null,
            'total_count' => null,
            'has_data' => false,
        ];
    }

    /**
     * @return Collection<int, object>
     */
    private function resolveNkoRankingFallback(?User $user, ?string $startDate, ?string $endDate, ?Closure $fallback): Collection
    {
        if ($fallback !== null) {
            return collect($fallback($user, $startDate, $endDate));
        }

        $service = $this->resolveKpiReadService();
        if ($service !== null) {
            $callable = [$service, 'nkoRanking'];
            if (is_callable($callable)) {
                return collect($callable($user, $startDate, $endDate));
            }
        }

        return collect();
    }

    private function resolveLatestPeriodFallback(?User $user, ?string $startDate, ?string $endDate, ?Closure $fallback): ?string
    {
        if ($fallback !== null) {
            $result = $fallback($user, $startDate, $endDate);

            return $result !== null ? (string) $result : null;
        }

        $service = $this->resolveKpiReadService();
        if ($service !== null) {
            $callable = [$service, 'latestReadyPeriod'];
            if (is_callable($callable)) {
                $result = $callable($user, $startDate, $endDate);

                return $result !== null ? (string) $result : null;
            }
        }

        return $this->latestReadyPeriod($user, $startDate, $endDate);
    }

    private function resolveKpiReadService(): ?object
    {
        $class = self::KPI_READ_SERVICE_CLASS;

        if (! class_exists($class)) {
            return null;
        }

        $resolved = app($class);

        return is_object($resolved) ? $resolved : null;
    }

    /**
     * @param  array{has_dataset: bool, last_updated_label: string, last_updated_iso: ?string, latest_period: ?string, latest_period_label: string, is_stale: bool, stale_message: ?string}  $freshness
     * @param  list<string>  $availablePeriods
     * @return array{
     *     period: ?string,
     *     period_uit: ?string,
     *     period_upt: ?string,
     *     available_periods: list<string>,
     *     freshness: array{has_dataset: bool, last_updated_label: string, last_updated_iso: ?string, latest_period: ?string, latest_period_label: string, is_stale: bool, stale_message: ?string},
     *     labels: list<string>,
     *     series: list<array{name: string, data: list<float>}>,
     *     summary: array{total_tower: float, total_transmisi: float, total_trafo: float},
     *     summary_upt: array{total_tower: float, total_transmisi: float, total_trafo: float},
     *     table_rows: list<array{upt: string, tower: ?float, transmisi: ?float, trafo: ?float}>,
     *     series_by_category: array{tower: list<array{name: string, data: list<float>}>, transmisi: list<array{name: string, data: list<float>}>, trafo: list<array{name: string, data: list<float>}>},
     *     ultg_by_upt: array<string, list<array<string, mixed>>>,
     *     capacity_ratios: array<string, mixed>
     * }
     */
    private function emptyAsetUptChartPayload(?string $period, array $freshness, array $availablePeriods = []): array
    {
        $emptySummary = [
            'total_tower' => 0.0,
            'total_transmisi' => 0.0,
            'total_trafo' => 0.0,
            'trafo_bank' => null,
            'trafo_unit' => null,
        ];

        return [
            'period' => $period,
            'period_uit' => null,
            'period_upt' => $period,
            'available_periods' => $availablePeriods,
            'freshness' => $freshness,
            'labels' => [],
            'series' => [],
            'summary' => $emptySummary,
            'summary_upt' => $emptySummary,
            'table_rows' => [],
            'series_by_category' => [
                'tower' => [],
                'transmisi' => [],
                'trafo' => [],
            ],
            'ultg_by_upt' => array_fill_keys(AssetStockReport::CANONICAL_UPTS, []),
            'capacity_ratios' => [
                'trafo' => [
                    'overall' => [
                        'mva' => null,
                        'unit' => null,
                        'bank' => null,
                        'avg_mva_per_unit' => null,
                        'avg_mva_per_bank' => null,
                    ],
                    'by_voltage' => [],
                    'by_upt' => [],
                ],
                'transmisi' => [
                    'overall' => [
                        'su_kms' => null,
                        'sk_kms' => null,
                        'total_kms' => null,
                        'su_pct' => null,
                        'sk_pct' => null,
                    ],
                    'by_voltage' => [],
                    'by_upt' => [],
                ],
            ],
        ];
    }
}
