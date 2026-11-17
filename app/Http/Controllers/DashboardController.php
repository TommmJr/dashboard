<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DashboardLayout;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use App\Services\Dashboard\DashboardLayoutService;
use App\Services\Dashboard\ExecutiveDashboardService;
use App\Services\Dashboard\KpiDashboardReadService;
use App\Services\SystemHealthService;
use App\Support\DatasetFreshness;
use App\Support\KpiScoreNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Halaman dashboard berbasis widget per role (Dashboard Builder).
 *
 * Controller berfungsi sebagai thin orchestrator yang mendelegasikan manajemen
 * layout ke DashboardLayoutService, metadata eksekutif/admin/aset ke
 * ExecutiveDashboardService, serta pembacaan metrik KPI ke KpiDashboardReadService.
 */
class DashboardController extends Controller
{
    public const CANONICAL_STAFF_DIVISION_SLUGS = DashboardLayoutService::CANONICAL_STAFF_DIVISION_SLUGS;

    /**
     * Prefix atribut yang ditampilkan pada grafik Aset per UPT.
     *
     * @var list<string>
     */
    private const ASET_ATTRIBUTE_PREFIXES = ExecutiveDashboardService::ASET_ATTRIBUTE_PREFIXES;

    /** Batas skor indikator R6/R9 dalam persen (keputusan Pak Gama 2026-10-25, mengikuti Excel). */
    private const KPI_SCORE_MIN = KpiScoreNormalizer::SCORE_MIN;

    private const KPI_SCORE_MAX = KpiScoreNormalizer::SCORE_MAX;

    /**
     * Batas atas skor PENCAPAIAN Excel dalam skala rasio.
     */
    private const KPI_SCORE_RATIO_MAX = KpiScoreNormalizer::RATIO_MAX;

    /**
     * Jumlah pasangan/injeksi R6 legacy yang dilewati karena sumber dataset
     * atau pencocokan performance_observations ambigu (> 1 kandidat).
     */
    private int $kinerjaRealisasiAmbiguousCount = 0;

    /**
     * Daftar lengkap unit pada periode terpilih untuk dropdown widget kinerja-detail.
     *
     * @var list<string>|null
     */
    private ?array $kinerjaDetailUnits = null;

    public function __construct(
        private readonly DashboardLayoutService $layoutService,
        private readonly ExecutiveDashboardService $executiveDashboard,
        private readonly KpiDashboardReadService $kpiDashboardRead,
        private readonly ?SystemHealthService $systemHealth = null,
    ) {}

    /**
     * Rakit daftar widget terurut untuk user aktif, lalu render view.
     */
    public function __invoke(Request $request): View
    {
        $config = config('dashboard_layouts');
        $config = is_array($config) ? $config : [];
        $user = Auth::user();
        $user->loadMissing('role', 'division');
        $layout = $this->resolveLayout($user, $config);

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

        $normalizedLayout = DashboardLayout::normalizeWidgets($layout, $config['widgets'] ?? []);

        $widgets = collect($normalizedLayout)
            ->map(function (array $item) use ($config): ?array {
                $key = $item['key'];
                if (! isset($config['widgets'][$key])) {
                    return null;
                }

                return array_merge($config['widgets'][$key], $item);
            })
            ->filter()
            ->filter(fn (array $widget) => $widget['permission'] === null
                || Gate::allows($widget['permission']))
            // Dashboard layout editor disediakan di halaman tersendiri (/dashboard/builder).
            ->reject(fn (array $widget) => $widget['key'] === 'layout-editor-placeholder')
            ->map(function (array $widget, int $key) use ($user, $filters) {
                $widget['instance'] = $key;
                $widget['data'] = $this->dataFor($widget['key'], $user, $filters);

                if ($widget['key'] === 'dataset-status') {
                    $widget['is_non_kpi'] = (! $this->isPrivileged($user) && $user?->division?->slug !== 'perencanaan-pengusahaan');
                }

                if ($widget['key'] === 'kinerja-detail') {
                    $widget['units'] = $this->kinerjaDetailUnits ?? [];
                }

                return $widget;
            })
            ->values()
            ->all();

        $executiveMeta = self::canAccessKpiDetail($user)
            ? $this->buildExecutiveMeta($user, $widgets, $filters)
            : null;

        $kpiFreshness = $executiveMeta['freshness']
            ?? ($user?->hasRole(Role::ADMIN) ? DatasetFreshness::forKpi() : null);

        $systemHealth = $user?->hasRole(Role::ADMIN)
            ? ($this->systemHealth ?? app(SystemHealthService::class))->snapshot()
            : null;

        return view('dashboard.index', [
            'widgets' => $widgets,
            'filters' => $filters,
            'availableUnits' => $this->availableUnits($user),
            'executiveMeta' => $executiveMeta,
            'kpiFreshness' => $kpiFreshness,
            'systemHealth' => $systemHealth,
        ]);
    }

    /**
     * Ringkasan metadata SSR untuk Executive Header & fallback NKO di L1 KPI.
     *
     * @param  list<array<string, mixed>>  $widgets
     * @param  array{start_date: ?string, end_date: ?string, units: list<string>}  $filters
     * @return array<string, mixed>
     */
    private function buildExecutiveMeta(?User $user, array $widgets, array $filters): array
    {
        return $this->executiveDashboard->buildExecutiveMeta(
            $user,
            $widgets,
            $filters,
            fn (?User $u, ?string $start, ?string $end): array => $this->kpiSummary($u, $start, $end),
            fn (?User $u, ?string $start, ?string $end): Collection => $this->nkoRanking($u, $start, $end),
            fn (?User $u, ?string $start, ?string $end): ?string => $this->latestReadyPeriod($u, $start, $end),
        );
    }

    /**
     * Periksa apakah user berhak membuka halaman rincian KPI terpisah:
     * - Role lintas divisi ber-permission dashboard.view (management, editor_dashboard)
     * - Staf divisi Perencanaan Pengusahaan
     */
    public static function canAccessKpiDetail(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $user->loadMissing('role', 'division');

        if ($user->hasRole(Role::MANAGEMENT)
            || $user->hasRole(Role::EDITOR_DASHBOARD)) {
            return Gate::forUser($user)->allows('dashboard.view');
        }

        return $user->hasRole(Role::STAFF_DIVISI)
            && $user->division?->slug === 'perencanaan-pengusahaan';
    }

    /**
     * Halaman rincian KPI mandiri (Tahap 3): menampilkan panel per indikator
     * dan tabel detail metrik penuh tanpa pemotongan 10 baris.
     */
    public function kpiDetail(Request $request): View
    {
        $user = Auth::user();
        abort_unless(self::canAccessKpiDetail($user), 403);

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

        $targetRealisasiData = $this->targetRealisasiPerIndikator(
            $user,
            $filters['start_date'],
            $filters['end_date'],
        );

        $kinerjaDetailData = $this->enrichKinerjaDetailDisplayMeta(
            $this->kinerjaDetail(
                $user,
                $filters['start_date'],
                $filters['end_date'],
                $filters['units'],
            ),
            $user,
        );

        $fullscreenChartData = $this->kpiFullscreenChartData(
            $targetRealisasiData,
            $kinerjaDetailData,
            $filters['units'],
        );

        return view('dashboard.kpi-detail', [
            'filters' => $filters,
            'availableUnits' => $this->availableUnits($user),
            'targetRealisasiWidget' => [
                'key' => 'target-realisasi-per-indikator',
                'label' => 'Target dan Realisasi per Indikator',
                'instance' => 0,
            ],
            'targetRealisasiData' => $targetRealisasiData,
            'kinerjaDetailWidget' => [
                'key' => 'kinerja-detail',
                'label' => 'Detail Metrik KPI',
                'limit' => PHP_INT_MAX,
                'units' => $this->kinerjaDetailUnits ?? [],
                'instance' => 1,
            ],
            'kinerjaDetailData' => $kinerjaDetailData,
            'fullscreenChartData' => $fullscreenChartData,
        ]);
    }

    /**
     * Halaman tersendiri untuk Dashboard Layout Builder.
     */
    public function builder(): View
    {
        $user = Auth::user();
        abort_unless(
            $user !== null && $user->can('dashboard.update') && $this->isPrivileged($user),
            403,
        );

        $user->loadMissing('role', 'division');
        $builderData = $this->layoutEditorData($user);

        return view('dashboard.builder', [
            'builderData' => $builderData,
        ]);
    }

    /**
     * Simpan atau perbarui konfigurasi susunan widget dashboard di database.
     */
    public function updateLayout(Request $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user !== null && $user->can('dashboard.update') && $this->isPrivileged($user),
            403,
        );

        [$roleSlug, $divisionId, $layoutKey, $widgets] = $this->validateLayoutTarget($request, requireWidgets: true);

        $saved = $this->layoutService->saveLayout($user, $layoutKey, $roleSlug, $divisionId, $widgets);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Layout dashboard berhasil disimpan.',
                'layout_key' => $saved->layout_key,
                'widgets' => $saved->widgets,
            ]);
        }

        return redirect()
            ->route('dashboard')
            ->with('status', 'Layout dashboard berhasil disimpan.');
    }

    /**
     * Hapus override layout database agar kembali ke konfigurasi bawaan.
     */
    public function resetLayout(Request $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user !== null && $user->can('dashboard.update') && $this->isPrivileged($user),
            403,
        );

        [$roleSlug, $divisionId, $layoutKey] = $this->validateLayoutTarget($request, requireWidgets: false);

        $this->layoutService->deleteLayout($layoutKey, $roleSlug, $divisionId);

        if ($request->expectsJson()) {
            $config = config('dashboard_layouts');
            $config = is_array($config) ? $config : [];
            $divisionSlug = $divisionId !== null
                ? Division::query()->whereKey($divisionId)->value('slug')
                : null;

            return response()->json([
                'message' => 'Layout dashboard dikembalikan ke bawaan.',
                'layout_key' => $layoutKey,
                'widgets' => $this->resolveFallbackWidgetsForTarget(
                    $roleSlug,
                    is_string($divisionSlug) ? $divisionSlug : null,
                    $config,
                ),
            ]);
        }

        return redirect()
            ->route('dashboard')
            ->with('status', 'Layout dashboard dikembalikan ke bawaan.');
    }

    /**
     * Terima hanya tanggal Y-m-d; selain itu null.
     */
    private function dateOrNull(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function isPrivileged(?User $user): bool
    {
        return $this->kpiDashboardRead->isPrivileged($user);
    }

    /**
     * Data server-side ringan per widget.
     *
     * @param  array{start_date?: ?string, end_date?: ?string, units?: list<string>}  $filters
     */
    private function dataFor(string $key, ?User $user = null, array $filters = []): mixed
    {
        return match ($key) {
            'kpi-summary' => $this->kpiSummary($user, $filters['start_date'] ?? null, $filters['end_date'] ?? null),
            'kpi-rag-status' => $this->kpiRagStatus($user, $filters['start_date'] ?? null, $filters['end_date'] ?? null),
            'nko-ranking' => $this->nkoRanking($user, $filters['start_date'] ?? null, $filters['end_date'] ?? null),
            'aset-upt-chart' => $this->asetUptChart($user),
            'recent-audit' => $this->recentAudit($user),
            'failed-datasets' => $this->failedDatasets(),
            'admin-summary' => $this->adminSummary(),
            'admin-quick-actions' => null,
            'dataset-status' => $this->datasetStatus($user),
            'kinerja-realisasi' => $this->kinerjaRealisasi($user, $filters['start_date'] ?? null, $filters['end_date'] ?? null),
            'kinerja-detail' => $this->enrichKinerjaDetailDisplayMeta(
                $this->kinerjaDetail($user, $filters['start_date'] ?? null, $filters['end_date'] ?? null, $filters['units'] ?? []),
                $user,
            ),
            'target-realisasi-per-indikator' => $this->targetRealisasiPerIndikator($user, $filters['start_date'] ?? null, $filters['end_date'] ?? null),
            'pencapaian-nko' => $this->pencapaianNko($user, $filters['start_date'] ?? null, $filters['end_date'] ?? null),
            'nko-donut' => $this->nkoDonut($user, $filters['start_date'] ?? null, $filters['end_date'] ?? null),
            'layout-editor-placeholder' => $this->layoutEditorData($user),
            default => null,
        };
    }

    /**
     * @return array{total_datasets: int, failed_datasets: int, audit_today: int, total_users: int}
     */
    private function adminSummary(): array
    {
        return $this->executiveDashboard->adminSummary();
    }

    /**
     * @return array{count: int, rows: Collection<int, Dataset>}
     */
    private function failedDatasets(): array
    {
        return $this->executiveDashboard->failedDatasets();
    }

    /**
     * @return Collection<int, AuditLog>
     */
    private function recentAudit(?User $user): Collection
    {
        return $this->executiveDashboard->recentAudit($user);
    }

    /**
     * @return array<string, mixed>
     */
    private function asetUptChart(?User $user): array
    {
        return $this->executiveDashboard->asetUptChart($user);
    }

    /**
     * @return array<string, mixed>
     */
    private function datasetStatus(?User $user): array
    {
        return $this->executiveDashboard->datasetStatus($user);
    }

    /**
     * @return array<string, mixed>
     */
    private function datasetStatusStructured(?User $user, \Closure $scope): array
    {
        return $this->executiveDashboard->datasetStatusStructured($user, $scope);
    }

    /**
     * @param  array<string, mixed>  $targetRealisasiData
     * @param  array<string, mixed>  $kinerjaDetailData
     * @param  list<string>  $selectedUnits
     * @return array{
     *     period: string|null,
     *     unitLabel: string,
     *     ragThresholds: array{tercapai_min: float, hampir_min: float},
     *     indicators: list<array<string, mixed>>
     * }
     */
    private function kpiFullscreenChartData(array $targetRealisasiData, array $kinerjaDetailData, array $selectedUnits): array
    {
        return $this->kpiDashboardRead->kpiFullscreenChartData($targetRealisasiData, $kinerjaDetailData, $selectedUnits);
    }

    /**
     * @return list<string>
     */
    private function availableUnits(?User $user): array
    {
        return $this->kpiDashboardRead->availableUnits($user);
    }

    /**
     * @return Collection<int, \stdClass>
     */
    private function nkoRanking(?User $user, ?string $startDate = null, ?string $endDate = null): Collection
    {
        return $this->kpiDashboardRead->nkoRanking($user, $startDate, $endDate);
    }

    private function latestReadyPeriod(?User $user, ?string $startDate = null, ?string $endDate = null): ?string
    {
        return $this->kpiDashboardRead->latestReadyPeriod($user, $startDate, $endDate);
    }

    private function kpiReadModelStructured(): bool
    {
        return $this->kpiDashboardRead->kpiReadModelStructured();
    }

    private function latestLegacyReadyPeriod(?User $user, ?string $startDate = null, ?string $endDate = null): ?string
    {
        return $this->kpiDashboardRead->latestLegacyReadyPeriod($user, $startDate, $endDate);
    }

    private function latestStructuredReadyPeriod(?User $user, ?string $startDate = null, ?string $endDate = null): ?string
    {
        return $this->kpiDashboardRead->latestStructuredReadyPeriod($user, $startDate, $endDate);
    }

    /**
     * @return array{period: ?string, rows: list<array<string, mixed>>, has_data: bool}
     */
    private function kinerjaRealisasi(?User $user, ?string $startDate = null, ?string $endDate = null): array
    {
        $result = $this->kpiDashboardRead->kinerjaRealisasi($user, $startDate, $endDate);
        $this->kinerjaRealisasiAmbiguousCount = $this->kpiDashboardRead->getKinerjaRealisasiAmbiguousCount();

        return $result;
    }

    private function latestLegacyScoredObservationPeriod(?User $user, ?string $startDate = null, ?string $endDate = null): ?string
    {
        return $this->kpiDashboardRead->latestLegacyScoredObservationPeriod($user, $startDate, $endDate);
    }

    /**
     * @param  array<string, array<string, array{target?: float, realisasi?: float}>>  $pairs
     * @param  array<string, array<string, array{target?: int, realisasi?: int}>>  $pairDatasets
     * @param  array<string, array<string, array{target: int, realisasi: int}>>  $pairKindCounts
     * @param  array<string, array<int, true>>  $unitDatasets
     * @return array<string, array<string, array{target?: float, realisasi?: float, pencapaian?: float}>>
     */
    private function applyLegacyObservationScores(
        array $pairs,
        array $pairDatasets,
        array $pairKindCounts,
        array $unitDatasets,
        string $period,
        bool $isPrivileged,
        ?User $user,
    ): array {
        $result = $this->kpiDashboardRead->applyLegacyObservationScores(
            $pairs,
            $pairDatasets,
            $pairKindCounts,
            $unitDatasets,
            $period,
            $isPrivileged,
            $user,
        );
        $this->kinerjaRealisasiAmbiguousCount = $this->kpiDashboardRead->getKinerjaRealisasiAmbiguousCount();

        return $result;
    }

    /**
     * @param  array<string, array<string, array{target?: float, realisasi?: float, pencapaian?: float}>>  $pairs
     * @return array{period: ?string, rows: list<array<string, mixed>>, has_data: bool}
     */
    private function kinerjaRealisasiRows(string $period, array $pairs): array
    {
        return $this->kpiDashboardRead->kinerjaRealisasiRows($period, $pairs);
    }

    /**
     * @return array{has_data: bool, avg_pencapaian: ?float, achieved_count: ?int, total_count: ?int}
     */
    private function kpiSummary(?User $user, ?string $startDate = null, ?string $endDate = null): array
    {
        return $this->kpiDashboardRead->kpiSummary($user, $startDate, $endDate);
    }

    /**
     * @return array{period: ?string, has_data: bool, counts: array{tercapai: int, hampir: int, perlu: int, total: int}}
     */
    private function kpiRagStatus(?User $user, ?string $startDate = null, ?string $endDate = null): array
    {
        return $this->kpiDashboardRead->kpiRagStatus($user, $startDate, $endDate);
    }

    /**
     * @param  list<string>  $units
     * @return array{period: ?string, rows: list<array<string, mixed>>, truncated: bool, total_rows: int}
     */
    private function kinerjaDetail(?User $user, ?string $startDate = null, ?string $endDate = null, array $units = []): array
    {
        $result = $this->kpiDashboardRead->kinerjaDetail($user, $startDate, $endDate, $units);
        $this->kinerjaDetailUnits = $this->kpiDashboardRead->getKinerjaDetailUnits();

        return $result;
    }

    /**
     * @param  array<string, array<string, array<string, array{dataset_id: int}>>>  $candidates
     * @return array<string, array<string, array{target?: string, realisasi?: string, pencapaian?: float|null}>>
     */
    private function kinerjaDetailDateDisplays(array $candidates, string $period): array
    {
        return $this->kpiDashboardRead->kinerjaDetailDateDisplays($candidates, $period);
    }

    /**
     * @param  list<string>  $units
     * @return list<string>
     */
    private function kinerjaDetailUnitOrder(array $units): array
    {
        return $this->kpiDashboardRead->kinerjaDetailUnitOrder($units);
    }

    private function latestStructuredPanelPeriod(?User $user, ?string $startDate = null, ?string $endDate = null): ?string
    {
        return $this->kpiDashboardRead->latestStructuredPanelPeriod($user, $startDate, $endDate);
    }

    /**
     * @return array<string, mixed>
     */
    private function targetRealisasiPerIndikator(?User $user, ?string $startDate = null, ?string $endDate = null): array
    {
        return $this->kpiDashboardRead->targetRealisasiPerIndikator($user, $startDate, $endDate);
    }

    /**
     * @return array{period: ?string, labels: list<string>, data: list<float>}
     */
    private function pencapaianNko(?User $user, ?string $startDate = null, ?string $endDate = null): array
    {
        return $this->kpiDashboardRead->pencapaianNko($user, $startDate, $endDate);
    }

    /**
     * @return array{period: ?string, data: list<array{name: string, value: int}>}
     */
    private function nkoDonut(?User $user, ?string $startDate = null, ?string $endDate = null): array
    {
        return $this->kpiDashboardRead->nkoDonut($user, $startDate, $endDate);
    }

    /**
     * @return Collection<int, \stdClass>
     */
    private function nkoRankingStructured(?User $user, ?string $startDate = null, ?string $endDate = null): Collection
    {
        return $this->kpiDashboardRead->nkoRankingStructured($user, $startDate, $endDate);
    }

    /**
     * @return array{period: ?string, data: list<array{name: string, value: int}>}
     */
    private function nkoDonutStructured(?User $user, ?string $startDate = null, ?string $endDate = null): array
    {
        return $this->kpiDashboardRead->nkoDonutStructured($user, $startDate, $endDate);
    }

    /**
     * @param  list<string>  $units
     * @return array{period: ?string, rows: list<array<string, mixed>>, truncated: bool, total_rows: int}
     */
    private function kinerjaDetailStructured(?User $user, ?string $startDate = null, ?string $endDate = null, array $units = []): array
    {
        $result = $this->kpiDashboardRead->kinerjaDetailStructured($user, $startDate, $endDate, $units);
        $this->kinerjaDetailUnits = $this->kpiDashboardRead->getKinerjaDetailUnits();

        return $result;
    }

    /**
     * @param  array{period: string|null, rows: list<array<string, mixed>>, truncated: bool, total_rows: int}  $data
     * @return array{period: string|null, rows: list<array<string, mixed>>, truncated: bool, total_rows: int}
     */
    private function enrichKinerjaDetailDisplayMeta(array $data, ?User $user): array
    {
        return $this->kpiDashboardRead->enrichKinerjaDetailDisplayMeta($data, $user);
    }

    /**
     * @return array{period: ?string, rows: list<array<string, mixed>>, has_data: bool}
     */
    private function kinerjaRealisasiStructured(?User $user, ?string $startDate = null, ?string $endDate = null): array
    {
        $result = $this->kpiDashboardRead->kinerjaRealisasiStructured($user, $startDate, $endDate);
        $this->kinerjaRealisasiAmbiguousCount = $this->kpiDashboardRead->getKinerjaRealisasiAmbiguousCount();

        return $result;
    }

    private function normalizeExcelScore(float $raw): float
    {
        return $this->kpiDashboardRead->normalizeExcelScore($raw);
    }

    /**
     * @return array<string, float>
     */
    private function structuredNkoValues(?string $divisionId, string $period): array
    {
        return $this->kpiDashboardRead->structuredNkoValues($divisionId, $period);
    }

    private function structuredNkoPeriod(?string $divisionId, ?string $startDate, ?string $endDate): ?string
    {
        return $this->kpiDashboardRead->structuredNkoPeriod($divisionId, $startDate, $endDate);
    }

    /**
     * @return array<string, mixed>
     */
    private function layoutEditorData(?User $user): array
    {
        return $this->layoutService->layoutEditorData($user);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function resolveFallbackWidgetsForTarget(string $roleSlug, ?string $divisionSlug, array $config): array
    {
        return $this->layoutService->resolveFallbackWidgetsForTarget($roleSlug, $divisionSlug, $config);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, mixed>
     */
    private function resolveLayout(?User $user, array $config): array
    {
        return $this->layoutService->resolveLayout($user, $config);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function resolveDbLayout(string $layoutKey, array $config): array
    {
        return $this->layoutService->resolveDbLayout($layoutKey, $config);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function resolveConfigLayout(string $layoutKey, array $config): array
    {
        return $this->layoutService->resolveConfigLayout($layoutKey, $config);
    }

    /**
     * @return array{0: string, 1: int|null, 2: string, 3: list<string|array{key: string, cols: int, config: array<string, mixed>}>}
     */
    private function validateLayoutTarget(Request $request, bool $requireWidgets): array
    {
        return $this->layoutService->validateLayoutTarget($request, $requireWidgets);
    }
}
