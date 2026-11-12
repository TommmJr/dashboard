<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesKpiUnits;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use App\Services\StructuredNkoReader;
use App\Services\TargetRealisasiPerIndicatorReader;
use App\Support\KpiScoreNormalizer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Controller untuk Analitik & Bottleneck KPI (/dashboard/kpi-analytics).
 *
 * Menyediakan deep-dive analisis indikator kinerja PLN TJBT:
 * - Executive Summary & Gap Analysis (Skor NKO, Rasio Tercapai, Rata-rata Pencapaian, Indikator Kritis)
 * - Bottleneck Finder / Indikator Prioritas Perbaikan (Urut pencapaian terendah, selisih realisasi vs target)
 * - Visualisasi ECharts Horizontal Bullet & Distribusi Status RAG
 */
class KpiAnalyticsController extends Controller
{
    use ScopesKpiUnits;

    public function __construct(
        private readonly StructuredNkoReader $structuredNko,
        private readonly TargetRealisasiPerIndicatorReader $targetRealisasiReader,
    ) {}

    /**
     * Otorisasi fail-closed untuk analitik KPI:
     * - Management
     * - Staf Divisi Perencanaan Pengusahaan
     *
     * Admin, Staf Managemen Aset, Editor Dashboard, dan divisi lain ditolak 403.
     */
    public static function canAccessKpiAnalytics(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $user->loadMissing('role', 'division');

        if ($user->hasRole(Role::MANAGEMENT)) {
            return true;
        }

        return $user->hasRole(Role::STAFF_DIVISI)
            && $user->division?->slug === 'perencanaan-pengusahaan';
    }

    /**
     * Halaman Analitik & Bottleneck KPI.
     */
    public function index(Request $request): View
    {
        $user = Auth::user();
        abort_unless(self::canAccessKpiAnalytics($user), 403);

        $startDate = $this->dateOrNull($request->query('start_date'));
        $endDate = $this->dateOrNull($request->query('end_date'));

        if ($startDate !== null && $endDate !== null && $startDate > $endDate) {
            $startDate = null;
            $endDate = null;
        }

        $pengusahaanDivision = Division::where('slug', 'perencanaan-pengusahaan')->first();
        $divisionId = $pengusahaanDivision?->id;

        $period = $this->structuredNko->latestNkoPeriod($divisionId, $startDate, $endDate);
        if ($period === null) {
            $period = DB::table('performance_observations')
                ->join('datasets', 'datasets.id', '=', 'performance_observations.dataset_id')
                ->where('datasets.status', Dataset::STATUS_READY)
                ->when($divisionId !== null, fn ($q) => $q->where('performance_observations.division_id', $divisionId))
                ->when($startDate !== null, fn ($q) => $q->where('performance_observations.period', '>=', $startDate))
                ->when($endDate !== null, fn ($q) => $q->where('performance_observations.period', '<=', $endDate))
                ->max('performance_observations.period');
        }

        $visibleUnits = $this->visibleUnits() ?? ['TJBT'];

        // Ambil data skor NKO periode aktif
        $nkoScore = null;
        if ($period !== null) {
            $nkoRows = $this->structuredNko->latestForPeriod($divisionId, $period);
            $primaryRow = $nkoRows->first(fn ($r) => in_array($r['unit'], $visibleUnits, true)) ?? $nkoRows->first();
            if ($primaryRow !== null) {
                $nkoScore = (float) $primaryRow['nko'];
            }
        }

        // Ambil data observasi terstruktur target vs realisasi
        $targetRealisasiData = $period !== null
            ? $this->targetRealisasiReader->panels($divisionId, $period, $visibleUnits)
            : ['period' => null, 'units' => [], 'sections' => []];

        $indicators = [];
        $ragCounts = [
            'tercapai' => 0,
            'hampir' => 0,
            'perlu' => 0,
            'unverified' => 0,
        ];
        $groupStats = [];

        foreach ($targetRealisasiData['sections'] ?? [] as $section) {
            $groupName = ! empty($section['name']) ? (string) $section['name'] : 'Umum';

            if (! isset($groupStats[$groupName])) {
                $groupStats[$groupName] = [
                    'name' => $groupName,
                    'total' => 0,
                    'tercapai' => 0,
                    'hampir' => 0,
                    'perlu' => 0,
                    'score_sum' => 0.0,
                    'score_count' => 0,
                ];
            }

            foreach ($section['panels'] ?? [] as $panel) {
                $pct = $panel['score']['percent'] ?? null;
                $pctFloat = $pct !== null ? (float) $pct : null;
                $clampedScore = $pctFloat !== null ? KpiScoreNormalizer::clamp($pctFloat) : null;

                $tier = 'unverified';
                if ($pctFloat !== null) {
                    $tier = KpiScoreNormalizer::ragTier($pctFloat);
                } elseif ($panel['mode'] === 'date') {
                    $tier = 'date';
                }

                if (isset($ragCounts[$tier])) {
                    $ragCounts[$tier]++;
                }

                $groupStats[$groupName]['total']++;
                if ($tier === KpiScoreNormalizer::TIER_TERCAPAI) {
                    $groupStats[$groupName]['tercapai']++;
                } elseif ($tier === KpiScoreNormalizer::TIER_HAMPIR) {
                    $groupStats[$groupName]['hampir']++;
                } elseif ($tier === KpiScoreNormalizer::TIER_PERLU) {
                    $groupStats[$groupName]['perlu']++;
                }

                if ($clampedScore !== null) {
                    $groupStats[$groupName]['score_sum'] += $clampedScore;
                    $groupStats[$groupName]['score_count']++;
                }

                $targetVal = $panel['numeric']['target'] ?? null;
                $realisasiVal = $panel['numeric']['realisasi'] ?? null;
                $gap = null;
                $gapFormatted = null;

                if ($targetVal !== null && $realisasiVal !== null) {
                    $gap = (float) $realisasiVal - (float) $targetVal;
                    $sign = $gap > 0 ? '+' : '';
                    $gapFormatted = $sign.number_format($gap, 2, ',', '.').(! empty($panel['satuan']) ? ' '.$panel['satuan'] : '');
                }

                $indicators[] = [
                    'id' => (int) $panel['indicator_id'],
                    'name' => (string) $panel['name'],
                    'group' => $groupName,
                    'satuan' => $panel['satuan'] ?? '',
                    'mode' => $panel['mode'],
                    'target' => $targetVal,
                    'realisasi' => $realisasiVal,
                    'target_date' => $panel['date']['target'] ?? null,
                    'realisasi_date' => $panel['date']['realisasi'] ?? null,
                    'delta_days' => $panel['date']['delta_days'] ?? null,
                    'pencapaian' => $pctFloat,
                    'clamped_score' => $clampedScore,
                    'tier' => $tier,
                    'gap' => $gap,
                    'gap_formatted' => $gapFormatted,
                ];
            }
        }

        // Hitung rata-rata per grup
        foreach ($groupStats as $k => $g) {
            $groupStats[$k]['avg'] = $g['score_count'] > 0
                ? round($g['score_sum'] / $g['score_count'], 1)
                : 0.0;
        }

        $totalIndicators = count($indicators);
        $scoredIndicators = array_values(array_filter($indicators, fn ($i) => $i['pencapaian'] !== null));
        $scoredCount = count($scoredIndicators);

        $achievedCount = $ragCounts['tercapai'];
        $nearCount = $ragCounts['hampir'];
        $criticalCount = $ragCounts['perlu'];
        $bottleneckCount = $nearCount + $criticalCount;

        $avgPencapaian = $scoredCount > 0
            ? round(array_sum(array_column($scoredIndicators, 'clamped_score')) / $scoredCount, 1)
            : null;

        // Bottlenecks: urutkan dari pencapaian terendah ke tertinggi (ascending)
        $bottleneckIndicators = array_values(array_filter(
            $indicators,
            fn ($i) => $i['tier'] === KpiScoreNormalizer::TIER_PERLU || $i['tier'] === KpiScoreNormalizer::TIER_HAMPIR
        ));
        usort($bottleneckIndicators, fn ($a, $b) => ($a['pencapaian'] ?? 0) <=> ($b['pencapaian'] ?? 0));

        // Urutkan semua indikator untuk grafik (terendah ke tertinggi agar grafik horizontal menampilkannya teratur)
        $chartIndicators = $scoredIndicators;
        usort($chartIndicators, fn ($a, $b) => ($a['pencapaian'] ?? 0) <=> ($b['pencapaian'] ?? 0));

        $ragThresholds = KpiScoreNormalizer::ragThresholds();

        return view('dashboard.kpi-analytics', [
            'period' => $period,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'nkoScore' => $nkoScore,
            'totalIndicators' => $totalIndicators,
            'scoredCount' => $scoredCount,
            'achievedCount' => $achievedCount,
            'nearCount' => $nearCount,
            'criticalCount' => $criticalCount,
            'bottleneckCount' => $bottleneckCount,
            'avgPencapaian' => $avgPencapaian,
            'indicators' => $indicators,
            'bottlenecks' => $bottleneckIndicators,
            'chartIndicators' => $chartIndicators,
            'groupStats' => array_values($groupStats),
            'ragCounts' => $ragCounts,
            'ragThresholds' => $ragThresholds,
            'visibleUnits' => $visibleUnits,
        ]);
    }

    private function dateOrNull(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
