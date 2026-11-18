@props(['widget' => [], 'data' => null])

@php
    $counts = $data['counts'] ?? ['tercapai' => 0, 'hampir' => 0, 'perlu' => 0, 'total' => 0];
    $total = (int) ($counts['total'] ?? 0);
    $tercapai = (int) ($counts['tercapai'] ?? 0);
    $hampir = (int) ($counts['hampir'] ?? 0);
    $perlu = (int) ($counts['perlu'] ?? 0);
    $hasData = ($data['has_data'] ?? false) && $total > 0;

    $tercapaiPct = $total > 0 ? round(($tercapai / $total) * 100, 1) : 0;
    $hampirPct = $total > 0 ? round(($hampir / $total) * 100, 1) : 0;
    $perluPct = $total > 0 ? max(0, round(100 - $tercapaiPct - $hampirPct, 1)) : 0;

    $chartSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($widget['key'] ?? 'kpi-rag-status'));
    $chartId = 'kpiRagChart-'.$chartSlug.'-'.($widget['instance'] ?? 0);
    $dataId = 'data-'.$chartId;
    $ragChartData = [
        'tercapai' => $tercapai,
        'hampir' => $hampir,
        'perlu' => $perlu,
        'total' => $total,
    ];

    $canAccessDetail = \App\Http\Controllers\DashboardController::canAccessKpiDetail(auth()->user());
@endphp

<div class="ibs-card flex h-full flex-col justify-between p-5">
    <div>
        <div class="flex items-center justify-between gap-2 border-b border-slate-100 pb-3">
            <h3 class="ibs-section-title text-base font-bold text-slate-900">{{ $widget['label'] ?? 'Status Indikator' }}</h3>
            @if ($hasData)
                <span class="inline-flex items-center rounded-full border border-slate-200/80 bg-slate-50 px-2.5 py-0.5 text-[11px] font-semibold text-slate-700 tabular-nums">
                    {{ round($tercapaiPct) }}% optimal
                </span>
            @endif
        </div>

        @if (! $hasData)
            <div class="mt-4 rounded-xl border border-dashed border-slate-200 bg-slate-50/70 p-6 text-center">
                <p class="text-xs font-medium text-slate-500">Belum ada data indikator pada periode ini.</p>
            </div>
        @else
            {{-- Ring Donut Visual + Center Counter --}}
            <div class="relative mt-3 flex items-center justify-center" style="height: 152px;">
                <div id="{{ $chartId }}" data-kpi-rag-chart="true" class="w-full" style="width: 100%; height: 152px;"></div>
                <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
                    <span class="text-2xl font-bold leading-none text-slate-900 tabular-nums">{{ $total }}</span>
                    <span class="mt-1 text-[10px] font-semibold uppercase tracking-wider text-slate-500">Indikator</span>
                </div>
            </div>

            {{-- Tremor-style CategoryBar (Proportional Health Track) --}}
            <div class="mt-2 flex h-2 w-full overflow-hidden rounded-full bg-slate-100" aria-hidden="true">
                @if ($tercapaiPct > 0)
                    <div class="h-full bg-emerald-500 transition-all duration-500" style="width: {{ $tercapaiPct }}%"></div>
                @endif
                @if ($hampirPct > 0)
                    <div class="h-full bg-amber-500 transition-all duration-500" style="width: {{ $hampirPct }}%"></div>
                @endif
                @if ($perluPct > 0)
                    <div class="h-full bg-rose-500 transition-all duration-500" style="width: {{ $perluPct }}%"></div>
                @endif
            </div>

            <script type="application/json" id="{{ $dataId }}">
                @json($ragChartData)
            </script>
            <script @cspNonce>
            (function() {
                let chartInstance = null;
                let attempts = 0;

                function initRagDonut() {
                    const el = document.getElementById(@json($chartId));
                    const dataEl = document.getElementById(@json($dataId));
                    if (!el || !dataEl) { return; }

                    if (!window.echarts) {
                        if (attempts < 30) {
                            attempts += 1;
                            window.setTimeout(initRagDonut, 40);
                        }
                        return;
                    }

                    let counts = {};
                    try {
                        counts = JSON.parse(dataEl.textContent || '{}');
                    } catch (e) {
                        return;
                    }

                    const palette = window.PLN_CHART_PALETTE || {
                        rag: { tercapai: '#10b981', waspada: '#f59e0b', kritis: '#f43f5e' },
                    };
                    const ragColors = palette.rag || { tercapai: '#10b981', waspada: '#f59e0b', kritis: '#f43f5e' };
                    const chartFont = window.PLN_PENGUSAHAAN_FONT || "'Geist', 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif";

                    const rawSlices = [
                        { name: 'Tercapai', value: Number(counts.tercapai || 0), itemStyle: { color: ragColors.tercapai } },
                        { name: 'Hampir Tercapai', value: Number(counts.hampir || 0), itemStyle: { color: ragColors.waspada } },
                        { name: 'Perlu Peningkatan', value: Number(counts.perlu || 0), itemStyle: { color: ragColors.kritis } },
                    ];
                    const slices = rawSlices.filter((item) => item.value > 0);
                    if (slices.length === 0) { return; }

                    if (chartInstance && !chartInstance.isDisposed()) {
                        chartInstance.dispose();
                    }

                    const option = {
                        animation: true,
                        animationDuration: 550,
                        animationEasing: 'cubicOut',
                        textStyle: { fontFamily: chartFont },
                        tooltip: {
                            trigger: 'item',
                            backgroundColor: '#ffffff',
                            borderColor: '#e2e8f0',
                            borderWidth: 1,
                            textStyle: { fontFamily: chartFont, fontSize: 12, color: '#0f172a' },
                            formatter: (params) => `${params.name}: <b>${params.value}</b> indikator (${params.percent}%)`,
                        },
                        series: [{
                            name: 'Distribusi Status',
                            type: 'pie',
                            radius: ['62%', '86%'],
                            center: ['50%', '50%'],
                            avoidLabelOverlap: true,
                            label: { show: false },
                            labelLine: { show: false },
                            itemStyle: {
                                borderColor: '#ffffff',
                                borderWidth: slices.length > 1 ? 2 : 0,
                                borderRadius: 4,
                            },
                            data: slices,
                        }],
                    };

                    if (typeof window.initSafeEChart === 'function') {
                        chartInstance = window.initSafeEChart(el, option);
                    } else {
                        chartInstance = window.echarts.init(el);
                        chartInstance.setOption(option);
                        new ResizeObserver(() => chartInstance && !chartInstance.isDisposed() && chartInstance.resize()).observe(el);
                    }
                }

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', initRagDonut);
                } else {
                    initRagDonut();
                }
            })();
            </script>

            {{-- Interactive Neutral Status Tiles (tanpa background berwarna berat) --}}
            @php
                $tiles = [
                    ['key' => 'tercapai', 'label' => 'Tercapai', 'count' => $tercapai, 'pct' => $tercapaiPct, 'dot' => 'bg-emerald-500'],
                    ['key' => 'hampir', 'label' => 'Hampir Tercapai', 'count' => $hampir, 'pct' => $hampirPct, 'dot' => 'bg-amber-500'],
                    ['key' => 'perlu', 'label' => 'Perlu Peningkatan', 'count' => $perlu, 'pct' => $perluPct, 'dot' => 'bg-rose-500'],
                ];
            @endphp

            <div class="mt-3.5 space-y-2">
                @foreach ($tiles as $tile)
                    @if ($canAccessDetail)
                        <a href="{{ route('dashboard.kpi-detail', ['rag' => $tile['key']]) }}" class="group flex items-center justify-between gap-2 rounded-xl border border-slate-200/80 bg-white px-3 py-2 text-xs transition hover:border-[#0b6175]/35 hover:bg-slate-50/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]">
                    @else
                        <div class="flex items-center justify-between gap-2 rounded-xl border border-slate-200/80 bg-white px-3 py-2 text-xs">
                    @endif
                            <div class="flex items-center gap-2.5 min-w-0">
                                <span class="h-2 w-2 shrink-0 rounded-full {{ $tile['dot'] }}" aria-hidden="true"></span>
                                <span class="truncate font-semibold text-slate-800">{{ $tile['label'] }}</span>
                            </div>
                            <div class="flex items-center gap-2 shrink-0 tabular-nums">
                                <span class="text-[11px] font-medium text-slate-400">{{ number_format($tile['pct'], 0) }}%</span>
                                <span class="font-bold text-slate-900">{{ $tile['count'] }}</span>
                                @if ($canAccessDetail)
                                    <svg class="h-3.5 w-3.5 text-slate-300 transition-transform group-hover:translate-x-0.5 group-hover:text-[#0b6175]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path d="m9 18 6-6-6-6" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                @endif
                            </div>
                    @if ($canAccessDetail)
                        </a>
                    @else
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
    </div>
</div>
