@props(['widget' => [], 'data' => null])

{{-- Panel Target vs Realisasi PER INDIKATOR (Looker Studio style).
     Menampilkan bar horizontal % pencapaian per indikator dengan garis target 100%.
     Target, realisasi, dan satuan mentah tersedia di tooltip.
     Milestone tanggal dan skor unverified disajikan dalam kartu terpisah di bawah grafik.

     Data: App\Services\TargetRealisasiPerIndicatorReader (selalu terstruktur).
--}}
@php
    $period = $data['period'] ?? null;
    $sections = $data['sections'] ?? [];
    $units = $data['units'] ?? [];

    $fmt = function (?float $value): string {
        if ($value === null) {
            return '—';
        }

        $text = number_format($value, 4, ',', '.');

        return str_contains($text, ',') ? rtrim(rtrim($text, '0'), ',') : $text;
    };

    $fmtPercent = fn (?float $value): string => $value === null ? '—' : $fmt(round($value, 1)).'%';

    $instanceId = $widget['instance'] ?? 0;

    // Summary counts across all sections
    $totalCount = 0;
    $achievedCount = 0;
    $nearCount = 0;
    $belowCount = 0;

    foreach ($sections as $section) {
        foreach ($section['panels'] ?? [] as $panel) {
            $totalCount++;
            $pct = $panel['score']['percent'] ?? null;
            if ($pct !== null) {
                if ($pct >= 100) {
                    $achievedCount++;
                } elseif ($pct >= 95) {
                    $nearCount++;
                } else {
                    $belowCount++;
                }
            }
        }
    }
@endphp

<div class="h-full rounded-lg bg-white border border-slate-200 shadow-sm p-4 sm:p-5">
    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <h3 class="text-base font-bold text-primary">{{ $widget['label'] ?? 'Target dan Realisasi per Indikator' }}</h3>
        @if ($period !== null)
            <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">
                Periode {{ $period }}@if (count($units) > 0) · {{ implode(', ', $units) }}@endif
            </span>
        @endif
    </div>

    @if (empty($sections))
        <p class="mt-4 rounded-lg bg-slate-50 p-5 text-sm text-gray-600">
            Belum ada data target/realisasi terstruktur pada periode ini.
        </p>
    @else
        {{-- Summary stat badges --}}
        <div class="mt-4 flex flex-wrap items-center gap-2 text-xs">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 font-medium text-slate-700">
                <span class="h-2 w-2 rounded-full bg-slate-400"></span>
                Total: {{ $totalCount }} Indikator
            </span>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 font-medium text-emerald-700 border border-emerald-200">
                <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                Tercapai (&ge;100%): {{ $achievedCount }}
            </span>
            @if ($nearCount > 0)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 font-medium text-amber-700 border border-amber-200">
                    <span class="h-2 w-2 rounded-full bg-amber-500"></span>
                    Mendekati (95–99%): {{ $nearCount }}
                </span>
            @endif
            @if ($belowCount > 0)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-50 px-2.5 py-1 font-medium text-rose-700 border border-rose-200">
                    <span class="h-2 w-2 rounded-full bg-rose-500"></span>
                    Perlu Perhatian (&lt;95%): {{ $belowCount }}
                </span>
            @endif
        </div>

        <div class="mt-4 space-y-4">
            @foreach ($sections as $sectionIndex => $section)
                @php
                    $panels = $section['panels'] ?? [];
                    $chartable = [];
                    $datePanels = [];
                    $unverifiedPanels = [];

                    foreach ($panels as $panel) {
                        if ($panel['mode'] === 'date') {
                            $datePanels[] = $panel;
                        } elseif ($panel['score'] !== null && $panel['score']['scale'] === 'unverified') {
                            $unverifiedPanels[] = $panel;
                        } elseif ($panel['score'] !== null && $panel['score']['percent'] !== null) {
                            $chartable[] = $panel;
                        }
                    }

                    $chartId = 'target-realisasi-chart-'.$sectionIndex.'-'.$instanceId;

                    $names = [];
                    $seriesData = [];
                    $extraData = [];
                    $maxVal = 120;

                    foreach ($chartable as $p) {
                        $pct = round((float) $p['score']['percent'], 1);
                        if ($pct > $maxVal) {
                            $maxVal = $pct;
                        }
                        $names[] = $p['name'];
                        $color = $pct >= 100 ? '#16a34a' : ($pct >= 95 ? '#eab308' : '#dc2626');
                        $seriesData[] = [
                            'value' => $pct,
                            'itemStyle' => ['color' => $color, 'borderRadius' => [0, 4, 4, 0]],
                        ];
                        $extraData[] = [
                            'target' => $p['numeric'] ? $fmt((float) $p['numeric']['target']) : null,
                            'realisasi' => $p['numeric'] ? $fmt((float) $p['numeric']['realisasi']) : null,
                            'satuan' => $p['satuan'],
                        ];
                    }

                    $xAxisMax = (int) (ceil($maxVal / 10) * 10);
                    $chartHeight = max(220, count($chartable) * 38 + 60);
                @endphp

                <section>
                    @if (! empty($section['name']))
                        <h4 class="text-sm font-semibold text-primary border-b border-slate-100 pb-1.5">{{ $section['name'] }}</h4>
                    @endif

                    @if (count($chartable) > 0)
                        <div class="mt-3">
                            <div id="{{ $chartId }}" class="w-full" style="height: {{ $chartHeight }}px;"></div>
                            <script>
                                (function() {
                                    function init() {
                                        const el = document.getElementById(@json($chartId));
                                        if (!el || !window.echarts) return;
                                        const extra = @json($extraData);
                                        const chart = window.echarts.init(el);
                                        chart.setOption({
                                            tooltip: {
                                                trigger: 'axis',
                                                axisPointer: { type: 'shadow' },
                                                formatter: function(params) {
                                                    if (!params || !params.length) return '';
                                                    const p = params[0];
                                                    const item = extra[p.dataIndex];
                                                    let tip = '<div class="text-xs font-semibold text-slate-800 mb-1">' + p.name + '</div>';
                                                    tip += '<div class="text-xs text-slate-600 space-y-0.5">';
                                                    if (item && item.target !== null && item.target !== undefined) {
                                                        tip += '<div>Target: <span class="font-medium text-slate-800">' + item.target + (item.satuan ? ' ' + item.satuan : '') + '</span></div>';
                                                    }
                                                    if (item && item.realisasi !== null && item.realisasi !== undefined) {
                                                        tip += '<div>Realisasi: <span class="font-medium text-slate-800">' + item.realisasi + (item.satuan ? ' ' + item.satuan : '') + '</span></div>';
                                                    }
                                                    tip += '<div>Pencapaian: <span class="font-bold" style="color:' + p.color + '">' + p.value + '%</span></div>';
                                                    tip += '</div>';
                                                    return tip;
                                                }
                                            },
                                            grid: {
                                                left: 8,
                                                right: 50,
                                                top: 20,
                                                bottom: 24,
                                                containLabel: true,
                                            },
                                            xAxis: {
                                                type: 'value',
                                                min: 0,
                                                max: {{ $xAxisMax }},
                                                axisLabel: {
                                                    formatter: '{value}%',
                                                    color: '#64748b',
                                                    fontSize: 11,
                                                },
                                                splitLine: {
                                                    lineStyle: {
                                                        type: 'dashed',
                                                        color: '#e2e8f0',
                                                    },
                                                },
                                            },
                                            yAxis: {
                                                type: 'category',
                                                inverse: true,
                                                data: @json($names),
                                                axisLabel: {
                                                    color: '#334155',
                                                    fontSize: 11,
                                                    width: 180,
                                                    overflow: 'truncate',
                                                    ellipsis: '...',
                                                },
                                                axisTick: { show: false },
                                                axisLine: { lineStyle: { color: '#cbd5e1' } },
                                            },
                                            series: [
                                                {
                                                    name: 'Pencapaian %',
                                                    type: 'bar',
                                                    barMaxWidth: 18,
                                                    data: @json($seriesData),
                                                    label: {
                                                        show: true,
                                                        position: 'right',
                                                        formatter: '{c}%',
                                                        color: '#475569',
                                                        fontSize: 11,
                                                        fontWeight: 500,
                                                    },
                                                    markLine: {
                                                        symbol: 'none',
                                                        silent: true,
                                                        lineStyle: {
                                                            color: '#0b6175',
                                                            type: 'dashed',
                                                            width: 1.5,
                                                        },
                                                        data: [
                                                            {
                                                                xAxis: 100,
                                                                label: {
                                                                    formatter: '100%',
                                                                    position: 'end',
                                                                    color: '#0b6175',
                                                                    fontSize: 10,
                                                                    fontWeight: 600,
                                                                },
                                                            },
                                                        ],
                                                    },
                                                },
                                            ],
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
                        </div>
                    @endif

                    {{-- Timeline & Milestone Tanggal --}}
                    @if (count($datePanels) > 0)
                        <div class="mt-4 space-y-2">
                            <h5 class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Milestone & Indikator Tanggal</h5>
                            <div class="grid gap-3 sm:grid-cols-2">
                                @foreach ($datePanels as $panel)
                                    <div
                                        class="rounded-lg border border-slate-200 bg-slate-50/60 p-3"
                                        data-indicator-id="{{ $panel['indicator_id'] }}"
                                        data-mode="{{ $panel['mode'] }}"
                                    >
                                        <div class="flex items-start justify-between gap-2">
                                            <span class="text-xs font-medium text-slate-800">{{ $panel['name'] }}</span>
                                            @if ($panel['score'] !== null && $panel['score']['percent'] !== null)
                                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold {{ $panel['score']['percent'] >= 100 ? 'bg-emerald-100 text-emerald-800' : ($panel['score']['percent'] >= 95 ? 'bg-amber-100 text-amber-800' : 'bg-rose-100 text-rose-800') }}">
                                                    {{ $fmtPercent($panel['score']['percent']) }}
                                                </span>
                                            @endif
                                        </div>
                                        <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-600">
                                            <div>Target: <span class="font-medium text-slate-800">{{ $panel['date']['target'] ?? '—' }}</span></div>
                                            <div>Realisasi: <span class="font-medium text-slate-800">{{ $panel['date']['realisasi'] ?? '—' }}</span></div>
                                            <div>
                                                Selisih:
                                                <span class="font-medium {{ ($panel['date']['delta_days'] ?? 0) <= 0 ? 'text-emerald-700' : 'text-rose-700' }}">
                                                    @if ($panel['date']['delta_days'] === null)
                                                        —
                                                    @else
                                                        {{ $panel['date']['delta_days'] }} hari ({{ $panel['date']['delta_days'] <= 0 ? 'lebih cepat' : 'lebih lambat' }})
                                                    @endif
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Unverified Skor --}}
                    @if (count($unverifiedPanels) > 0)
                        <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50/60 p-3.5">
                            <h5 class="text-xs font-semibold text-amber-900 flex items-center gap-1.5">
                                <svg class="h-4 w-4 text-amber-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                                Perlu Verifikasi Skala Skor
                            </h5>
                            <p class="mt-1 text-xs text-amber-800">
                                Indikator berikut tidak diplot pada grafik bar % untuk mencegah kesalahan skala (skor workbook asli tanpa satuan baku):
                            </p>
                            <div class="mt-2.5 divide-y divide-amber-200/60">
                                @foreach ($unverifiedPanels as $panel)
                                    <div
                                        class="py-1.5 flex flex-wrap items-center justify-between gap-2 text-xs"
                                        data-indicator-id="{{ $panel['indicator_id'] }}"
                                        data-mode="{{ $panel['mode'] }}"
                                    >
                                        <span class="font-medium text-amber-950">{{ $panel['name'] }}</span>
                                        <div class="flex items-center gap-2">
                                            @if ($panel['satuan'])
                                                <span class="text-amber-800">({{ $panel['satuan'] }})</span>
                                            @endif
                                            <span class="rounded bg-yellow-100 px-2 py-0.5 font-medium text-yellow-800">Skor tidak terverifikasi</span>
                                            <span class="text-amber-700">Nilai mentah: {{ $fmt($panel['score']['raw'] ?? null) }}</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Accessible representation for characterization tests and screen readers --}}
                    <div class="sr-only" aria-label="Aksesibilitas data indikator seksi {{ $section['name'] ?? '' }}">
                        @foreach ($panels as $panel)
                            <div data-indicator-id="{{ $panel['indicator_id'] }}" data-mode="{{ $panel['mode'] }}">
                                <span>{{ $panel['name'] }}</span>
                                @if ($panel['satuan'])
                                    <span>{{ $panel['satuan'] }}</span>
                                @endif
                                @if ($panel['score'] !== null)
                                    @if ($panel['score']['scale'] === 'unverified')
                                        <span>Skor tidak terverifikasi</span>
                                    @else
                                        <span>{{ $fmtPercent($panel['score']['percent']) }}</span>
                                    @endif
                                @endif
                                @if ($panel['mode'] === 'numeric')
                                    <span>Target: {{ $fmt((float) $panel['numeric']['target']) }}</span>
                                    <span>Realisasi: {{ $fmt((float) $panel['numeric']['realisasi']) }}</span>
                                @endif
                                @if (! empty($panel['period_conflict']))
                                    <span>Periode banner sheet berbeda dari periode upload.</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</div>
