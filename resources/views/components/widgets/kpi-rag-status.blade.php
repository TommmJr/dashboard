@props(['widget' => [], 'data' => null])

@php
    $counts = $data['counts'] ?? ['tercapai' => 0, 'hampir' => 0, 'perlu' => 0, 'total' => 0];
    $total = (int) ($counts['total'] ?? 0);
    $tercapai = (int) ($counts['tercapai'] ?? 0);
    $hampir = (int) ($counts['hampir'] ?? 0);
    $perlu = (int) ($counts['perlu'] ?? 0);
    $hasData = ($data['has_data'] ?? false) && $total > 0;

    $chartSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($widget['key'] ?? 'kpi-rag-status'));
    $chartId = 'kpiRagChart-'.$chartSlug.'-'.($widget['instance'] ?? 0);
    $dataId = 'data-'.$chartId;
    $ragChartData = [
        'tercapai' => $tercapai,
        'hampir' => $hampir,
        'perlu' => $perlu,
        'total' => $total,
    ];
@endphp

<div class="ibs-card h-full p-5 flex flex-col justify-between">
    <div>
        <div class="flex items-center justify-between gap-2">
            <h3 class="ibs-section-title text-base font-bold text-slate-900">{{ $widget['label'] ?? 'Status Indikator' }}</h3>
        </div>

        @if (! $hasData)
            <p class="mt-4 rounded-xl border border-slate-200/70 bg-slate-50 p-4 text-xs text-slate-500">
                Belum ada data indikator pada periode ini.
            </p>
        @else
            <div class="relative mt-2.5 flex items-center justify-center" style="height: 140px;">
                <div id="{{ $chartId }}" data-kpi-rag-chart="true" class="w-full" style="width: 100%; height: 140px;"></div>
                <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
                    <span class="text-xl font-bold leading-none text-slate-900 tabular-nums">{{ $total }}</span>
                    <span class="mt-0.5 text-[10px] font-semibold uppercase tracking-wider text-slate-500">Indikator</span>
                </div>
            </div>

            <script type="application/json" id="{{ $dataId }}">
                @json($ragChartData)
            </script>
            <script>
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
                        tooltip: {
                            trigger: 'item',
                            formatter: (params) => `${params.name}: <b>${params.value}</b> indikator (${params.percent}%)`,
                        },
                        series: [{
                            name: 'Distribusi Status',
                            type: 'pie',
                            radius: ['58%', '84%'],
                            center: ['50%', '50%'],
                            avoidLabelOverlap: true,
                            label: { show: false },
                            labelLine: { show: false },
                            itemStyle: {
                                borderColor: '#ffffff',
                                borderWidth: slices.length > 1 ? 2 : 0,
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

            <div class="mt-3 space-y-2">
                {{-- Tile 1: Tercapai --}}
                <div class="flex items-center justify-between gap-2 rounded-xl border border-slate-200/80 bg-white px-3 py-2 text-xs transition hover:border-slate-300">
                    <div class="flex items-center gap-2 min-w-0">
                        <svg class="h-4 w-4 shrink-0 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="m9 12 2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <span class="truncate font-semibold text-slate-800">Tercapai</span>
                    </div>
                    <span class="shrink-0 pl-2 font-bold text-slate-900 tabular-nums">{{ $tercapai }}</span>
                </div>

                {{-- Tile 2: Hampir Tercapai --}}
                <div class="flex items-center justify-between gap-2 rounded-xl border border-slate-200/80 bg-white px-3 py-2 text-xs transition hover:border-slate-300">
                    <div class="flex items-center gap-2 min-w-0">
                        <svg class="h-4 w-4 shrink-0 text-amber-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <circle cx="12" cy="12" r="9"/>
                            <path d="M12 7v5l3 2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <span class="truncate font-semibold text-slate-800">Hampir Tercapai</span>
                    </div>
                    <span class="shrink-0 pl-2 font-bold text-slate-900 tabular-nums">{{ $hampir }}</span>
                </div>

                {{-- Tile 3: Perlu Peningkatan --}}
                <div class="flex items-center justify-between gap-2 rounded-xl border border-slate-200/80 bg-white px-3 py-2 text-xs transition hover:border-slate-300">
                    <div class="flex items-center gap-2 min-w-0">
                        <svg class="h-4 w-4 shrink-0 text-rose-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <span class="truncate font-semibold text-slate-800">Perlu Peningkatan</span>
                    </div>
                    <span class="shrink-0 pl-2 font-bold text-slate-900 tabular-nums">{{ $perlu }}</span>
                </div>
            </div>
        @endif
    </div>
</div>
