@props(['widget' => [], 'data' => null])

{{-- Widget realisasi vs target KPI per unit (server-side).
     Modern visual summary dengan ECharts gauge, status pill, dan unit breakdown table.
     Data dihitung dari periode terbaru; status mengikuti legenda laporan:
     Tercapai >= 100, Hampir Tercapai >= 95, Perlu Peningkatan < 95. --}}
@php
    $period = $data['period'] ?? null;
    $rows = $data['rows'] ?? [];
    $hasData = ($data['has_data'] ?? false) && ! empty($rows);

    $pill = function (string $status): string {
        return match ($status) {
            'Tercapai' => 'bg-emerald-50 text-emerald-700 border border-emerald-200',
            'Hampir Tercapai' => 'bg-amber-50 text-amber-700 border border-amber-200',
            default => 'bg-rose-50 text-rose-700 border border-rose-200',
        };
    };

    $chartSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($widget['key'] ?? 'kinerja-realisasi'));
    $chartId = 'kinerjaRealisasi-'.$chartSlug.'-'.($widget['instance'] ?? 0);

    $firstRow = $rows[0] ?? null;
    $avgVal = $firstRow ? round((float) ($firstRow['avg'] ?? 0), 1) : 0;
    $avgTier = \App\Support\KpiScoreNormalizer::ragTier($avgVal);
    $gaugeColor = match ($avgTier) {
        \App\Support\KpiScoreNormalizer::TIER_TERCAPAI => '#16a34a',
        \App\Support\KpiScoreNormalizer::TIER_HAMPIR => '#eab308',
        default => '#dc2626',
    };
    $status = (string) ($firstRow['status'] ?? 'Perlu Peningkatan');
@endphp

<div class="h-full rounded-lg bg-white border border-slate-200 shadow-sm p-6 flex flex-col">
    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <h3 class="text-lg font-bold text-primary">{{ $widget['label'] ?? 'Realisasi vs Target' }}</h3>
        @if ($period !== null)
            <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">
                Periode {{ $period }}
            </span>
        @endif
    </div>

    @if (! $hasData)
        <p class="mt-4 rounded-lg bg-slate-50 p-5 text-sm text-gray-600">
            Belum ada data realisasi vs target pada periode ini.
        </p>
    @else
        {{-- Executive summary with Gauge + Key Metrics --}}
        <div class="mt-4 flex flex-col sm:flex-row items-center gap-4 rounded-xl bg-slate-50/70 p-4 border border-slate-100">
            <div id="{{ $chartId }}" class="h-28 w-28 shrink-0"></div>
            <div class="flex-1 text-center sm:text-left space-y-1">
                <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Pencapaian {{ $firstRow['unit'] ?? 'Unit' }}</span>
                <div class="text-lg sm:text-xl font-extrabold text-primary">
                    {{ number_format((float) ($firstRow['achieved'] ?? 0), 0, ',', '.') }} dari {{ number_format((float) ($firstRow['total'] ?? 0), 0, ',', '.') }} <span class="text-sm font-medium text-slate-600">tercapai</span>
                </div>
                <div class="pt-0.5">
                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $pill($status) }}">
                        {{ $status }}
                    </span>
                </div>
            </div>
        </div>

        {{-- Unit breakdown table with progress bar --}}
        <div class="mt-4 flex-1 overflow-x-auto rounded-lg border border-slate-200">
            <table class="w-full text-sm">
                <thead class="bg-slate-50/80 text-xs text-slate-600 font-semibold uppercase tracking-wider border-b border-slate-200">
                    <tr>
                        <th class="py-2.5 px-3 text-left">Unit</th>
                        <th class="py-2.5 px-3 text-left">Tercapai</th>
                        <th class="py-2.5 px-3 text-right">Rata-rata Pencapaian</th>
                        <th class="py-2.5 px-3 text-right">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($rows as $row)
                        @php
                            $rowAvg = (float) ($row['avg'] ?? 0);
                            $rowProgress = max(0, min(100, $rowAvg));
                            $rowTier = \App\Support\KpiScoreNormalizer::ragTier($rowAvg);
                            $rowBarColor = match ($rowTier) {
                                \App\Support\KpiScoreNormalizer::TIER_TERCAPAI => 'bg-emerald-500',
                                \App\Support\KpiScoreNormalizer::TIER_HAMPIR => 'bg-amber-500',
                                default => 'bg-rose-500',
                            };
                        @endphp
                        <tr class="hover:bg-slate-50/60 transition-colors">
                            <td class="py-2.5 px-3 font-semibold text-primary">{{ $row['unit'] ?? '—' }}</td>
                            <td class="py-2.5 px-3 font-medium text-slate-700">
                                {{ number_format((float) ($row['achieved'] ?? 0), 0, ',', '.') }} dari {{ number_format((float) ($row['total'] ?? 0), 0, ',', '.') }}
                            </td>
                            <td class="py-2.5 px-3 text-right">
                                <div class="flex flex-col items-end">
                                    <span class="font-bold text-slate-800">{{ number_format((float) ($row['avg'] ?? 0), 1, ',', '.') }}%</span>
                                    <div class="mt-1 h-1.5 w-16 sm:w-20 rounded-full bg-slate-100 overflow-hidden">
                                        <div class="h-full rounded-full {{ $rowBarColor }}" style="width: {{ round($rowProgress, 1) }}%"></div>
                                    </div>
                                </div>
                            </td>
                            <td class="py-2.5 px-3 text-right">
                                <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $pill((string) ($row['status'] ?? '')) }}">
                                    {{ $row['status'] ?? 'Perlu Peningkatan' }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <script>
            (function() {
                function init() {
                    const el = document.getElementById(@json($chartId));
                    if (!el || !window.echarts) return;
                    const chart = window.echarts.init(el);
                    chart.setOption({
                        series: [{
                            type: 'gauge',
                            min: 0,
                            max: 110,
                            radius: '100%',
                            startAngle: 210,
                            endAngle: -30,
                            splitNumber: 5,
                            progress: {
                                show: true,
                                width: 7,
                                itemStyle: {
                                    color: @json($gaugeColor),
                                },
                            },
                            pointer: {
                                show: false,
                            },
                            axisLine: {
                                lineStyle: {
                                    width: 7,
                                    color: [[1, '#e2e8f0']],
                                },
                            },
                            axisTick: { show: false },
                            splitLine: { show: false },
                            axisLabel: { show: false },
                            title: { show: false },
                            detail: {
                                valueAnimation: true,
                                fontSize: 15,
                                fontWeight: 700,
                                color: '#0b6175',
                                offsetCenter: [0, '10%'],
                                formatter: function(value) {
                                    return Number(value).toFixed(1).replace('.', ',') + '%';
                                },
                            },
                            data: [{
                                value: {{ $avgVal }},
                            }],
                        }],
                    });
                    new ResizeObserver(() => chart.resize()).observe(el);
                }
                if (document.readyState === 'loading') {
                    window.addEventListener('DOMContentLoaded', init);
                } else {
                    init();
                }
            })();
        </script>
    @endif
</div>
