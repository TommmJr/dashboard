<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesKpiUnits;
use App\Models\Dataset;
use App\Models\Role;
use App\Services\StructuredNkoReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomChartController extends Controller
{
    use ScopesKpiUnits;

    public function __construct(private readonly StructuredNkoReader $structuredNko) {}

    public function data(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'metric' => ['required', 'string', 'in:nko_score,pencapaian_ratio'],
            'chart_type' => ['required', 'string', 'in:bar,line,donut'],
            'title' => ['nullable', 'string', 'max:60'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'unit' => ['nullable', 'array'],
            'unit.*' => ['string', 'max:100'],
        ]);

        $user = $request->user();
        $isPrivileged = $user !== null && (
            $user->hasRole(Role::ADMIN)
            || $user->hasRole(Role::MANAGEMENT)
            || $user->hasRole(Role::EDITOR_DASHBOARD)
        );

        if (! $isPrivileged && $user?->division_id === null) {
            return response()->json([
                'metric' => $validated['metric'],
                'chart_type' => $validated['chart_type'],
                'title' => $validated['title'] ?? null,
                'categories' => [],
                'series' => [],
            ]);
        }

        $divisionId = $isPrivileged ? null : $user?->division_id;
        $metric = $validated['metric'];
        $chartType = $validated['chart_type'];
        $unitsFilter = $validated['unit'] ?? $request->query('units');
        if (is_string($unitsFilter)) {
            $unitsFilter = [$unitsFilter];
        }

        $categories = [];
        $series = [];

        if ($metric === 'nko_score') {
            $period = $this->structuredNko->latestNkoPeriod(
                $divisionId !== null ? (string) $divisionId : null,
                $validated['start_date'] ?? null,
                $validated['end_date'] ?? null,
            );

            if ($period !== null) {
                $rows = $this->structuredNko->latestForPeriod($divisionId !== null ? (string) $divisionId : null, $period)
                    ->filter(fn (array $row): bool => $this->unitVisible((string) $row['unit']))
                    ->when(! empty($unitsFilter), fn ($c) => $c->filter(fn (array $row) => in_array($row['unit'], $unitsFilter, true)))
                    ->values();

                $categories = $rows->pluck('unit')->all();
                $values = $rows->pluck('nko')->map(fn ($v) => round((float) $v, 2))->all();

                if ($chartType === 'donut') {
                    $pieData = [];
                    foreach ($categories as $idx => $cat) {
                        $pieData[] = ['name' => $cat, 'value' => $values[$idx] ?? 0];
                    }
                    $series = [
                        [
                            'name' => 'Skor NKO',
                            'type' => 'pie',
                            'data' => $pieData,
                        ],
                    ];
                } else {
                    $series = [
                        [
                            'name' => 'Skor NKO',
                            'type' => $chartType,
                            'data' => $values,
                        ],
                    ];
                }
            }
        } elseif ($metric === 'pencapaian_ratio') {
            $period = DB::table('performance_observations')
                ->join('datasets', 'datasets.id', '=', 'performance_observations.dataset_id')
                ->join('indicators', 'indicators.id', '=', 'performance_observations.indicator_id')
                ->where('datasets.status', Dataset::STATUS_READY)
                ->where('indicators.is_group', false)
                ->whereNotNull('performance_observations.pencapaian')
                ->when($divisionId !== null, fn ($q) => $q->where('performance_observations.division_id', $divisionId))
                ->when($validated['start_date'] ?? null, fn ($q, $d) => $q->where('performance_observations.period', '>=', $d))
                ->when($validated['end_date'] ?? null, fn ($q, $d) => $q->where('performance_observations.period', '<=', $d))
                ->max('performance_observations.period');

            if ($period !== null) {
                $latestIds = $this->structuredNko->latestDatasetIds(
                    $divisionId !== null ? (string) $divisionId : null,
                    $period,
                );

                if (! empty($latestIds)) {
                    $observations = DB::table('performance_observations')
                        ->join('indicators', 'indicators.id', '=', 'performance_observations.indicator_id')
                        ->join('org_units', 'org_units.id', '=', 'performance_observations.org_unit_id')
                        ->where('performance_observations.period', $period)
                        ->where('indicators.is_group', false)
                        ->whereNotNull('performance_observations.pencapaian')
                        ->whereIn('performance_observations.dataset_id', $latestIds)
                        ->when($divisionId !== null, fn ($q) => $q->where('performance_observations.division_id', $divisionId))
                        ->select('org_units.name as unit', 'performance_observations.pencapaian')
                        ->get();

                    $unitGroups = [];
                    foreach ($observations as $row) {
                        $unit = (string) $row->unit;
                        if (! $this->unitVisible($unit)) {
                            continue;
                        }
                        if (! empty($unitsFilter) && ! in_array($unit, $unitsFilter, true)) {
                            continue;
                        }

                        $raw = (float) $row->pencapaian;
                        $score = $raw > 1.1 ? $raw : $raw * 100.0;
                        $clamped = max(0.0, min(110.0, $score));

                        $unitGroups[$unit][] = $clamped;
                    }

                    $values = [];
                    foreach ($unitGroups as $unit => $scores) {
                        $avg = count($scores) > 0 ? array_sum($scores) / count($scores) : 0.0;
                        $categories[] = $unit;
                        $values[] = round($avg, 2);
                    }

                    if ($chartType === 'donut') {
                        $pieData = [];
                        foreach ($categories as $idx => $cat) {
                            $pieData[] = ['name' => $cat, 'value' => $values[$idx] ?? 0];
                        }
                        $series = [
                            [
                                'name' => 'Rasio Pencapaian',
                                'type' => 'pie',
                                'data' => $pieData,
                            ],
                        ];
                    } else {
                        $series = [
                            [
                                'name' => 'Rasio Pencapaian',
                                'type' => $chartType,
                                'data' => $values,
                            ],
                        ];
                    }
                }
            }
        }

        return response()->json([
            'metric' => $metric,
            'chart_type' => $chartType,
            'title' => $validated['title'] ?? null,
            'categories' => $categories,
            'series' => $series,
        ]);
    }
}
