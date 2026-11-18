@props(['widget' => [], 'data' => null])

{{-- Widget donut: distribusi NKO per kategori pada periode aktif. --}}
@php
    $period = $data['period'] ?? null;
    $chartSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($widget['key'] ?? 'nko-donut'));
    $chartId = 'nkoDonut-'.$chartSlug.'-'.($widget['instance'] ?? 0);
    $chartData = $data['data'] ?? [];
    $totalUnits = ! empty($chartData) ? array_sum(array_column($chartData, 'value')) : 0;

    $categoryMeta = [
        'Tercapai' => [
            'desc' => 'NKO ≥ 100',
            'color' => '#10b981',
            'badge' => 'border-slate-200/80 bg-white text-slate-800',
            'dot' => 'bg-emerald-500',
        ],
        'Hampir Tercapai' => [
            'desc' => '95 ≤ NKO < 100',
            'color' => '#f59e0b',
            'badge' => 'border-slate-200/80 bg-white text-slate-800',
            'dot' => 'bg-amber-500',
        ],
        'Perlu Peningkatan' => [
            'desc' => 'NKO < 95',
            'color' => '#f43f5e',
            'badge' => 'border-slate-200/80 bg-white text-slate-800',
            'dot' => 'bg-rose-500',
        ],
    ];

    $gridCols = match (count($chartData)) {
        1 => 'grid-cols-1',
        2 => 'grid-cols-1 sm:grid-cols-2',
        default => 'grid-cols-1 sm:grid-cols-3',
    };
@endphp

<div class="ibs-card h-full p-5 flex flex-col justify-between">
    <div>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="ibs-section-title text-base font-bold text-slate-900">{{ $widget['label'] ?? 'Distribusi NKO' }}</h3>
            @if ($period !== null)
                <span class="inline-flex items-center rounded-full bg-[#e6f4f6] px-2.5 py-0.5 text-[11px] font-semibold text-[#0b6175]">
                    Periode {{ $period }}
                </span>
            @endif
        </div>

        @if (empty($chartData))
            <p class="mt-4 rounded-xl border border-slate-200/70 bg-slate-50 p-5 text-sm text-slate-600">
                Belum ada data NKO pada periode ini.
            </p>
        @else
            <div
                x-data='{
                    init() {
                        let chartInstance = null;
                        const initChart = () => {
                            const el = document.getElementById(@json($chartId));
                            if (!el || !window.echarts) {
                                setTimeout(initChart, 40);
                                return;
                            }
                            const existing = window.echarts.getInstanceByDom(el);
                            chartInstance = existing || window.echarts.init(el);

                            const palette = window.PLN_CHART_PALETTE || {
                                primary: "#0b6175",
                                accent: "#16a3b8",
                                softTeal: "#bce3e8",
                                targetSlate: "#cbd5e1",
                                gridLine: "#f1f5f9",
                                axisText: "#64748b",
                                rag: {
                                    tercapai: "#10b981",
                                    waspada: "#f59e0b",
                                    kritis: "#f43f5e"
                                }
                            };
                            const categoryColors = {
                                Tercapai: palette.rag.tercapai,
                                "Hampir Tercapai": palette.rag.waspada,
                                "Perlu Peningkatan": palette.rag.kritis
                            };
                            const rawData = @json($chartData);
                            const seriesData = rawData.map(item => ({
                                name: item.name,
                                value: item.value,
                                itemStyle: {
                                    color: categoryColors[item.name] || palette.axisText,
                                    borderRadius: 6
                                }
                            }));

                            const chartFont = window.PLN_PENGUSAHAAN_FONT || "'Geist', 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif";

                            chartInstance.setOption({
                                animation: true,
                                animationDuration: 650,
                                animationEasing: "cubicOut",
                                textStyle: { fontFamily: chartFont },
                                tooltip: {
                                    trigger: "item",
                                    formatter: "{b}: {c} unit ({d}%)"
                                },
                                legend: {
                                    show: false
                                },
                                series: [{
                                    type: "pie",
                                    radius: ["45%", "70%"],
                                    center: ["50%", "50%"],
                                    data: seriesData,
                                    label: {
                                        show: true,
                                        formatter: "{b}\n{c} unit ({d}%)",
                                        fontSize: 11,
                                        color: "#334155",
                                        lineHeight: 14
                                    },
                                    labelLine: {
                                        show: true,
                                        length: 8,
                                        length2: 10,
                                        smooth: true
                                    }
                                }]
                            });

                            if (!el.__resizeObserverAttached) {
                                const ro = new ResizeObserver(() => {
                                    if (chartInstance && !chartInstance.isDisposed()) {
                                        chartInstance.resize();
                                    }
                                });
                                ro.observe(el);
                                el.__resizeObserverAttached = true;
                            }
                        };
                        this.$nextTick(() => initChart());
                    }
                }'
                class="mt-3 w-full"
            >
                <div id="{{ $chartId }}" class="w-full" style="width: 100%; height: 240px; min-height: 240px;"></div>
            </div>
        @endif
    </div>

    @if (! empty($chartData))
        <div class="mt-4 pt-3 border-t border-slate-100">
            <div class="grid {{ $gridCols }} gap-2">
                @foreach ($chartData as $item)
                    @php
                        $name = $item['name'] ?? '';
                        $val = (int) ($item['value'] ?? 0);
                        $pct = $totalUnits > 0 ? round(($val / $totalUnits) * 100, 1) : 0;
                        $pctFormatted = rtrim(rtrim(number_format($pct, 1, ',', '.'), '0'), ',');
                        $meta = $categoryMeta[$name] ?? [
                            'desc' => 'Kategori NKO',
                            'badge' => 'border-slate-200 bg-slate-50 text-slate-800',
                            'dot' => 'bg-slate-500',
                        ];
                    @endphp
                    <div class="flex items-center justify-between rounded-xl border p-2.5 text-xs {{ $meta['badge'] }}">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="h-1.5 w-1.5 rounded-full shrink-0 {{ $meta['dot'] }}" aria-hidden="true"></span>
                            <div class="truncate">
                                <span class="font-semibold block text-slate-900">{{ $name }}</span>
                                <span class="text-[11px] text-slate-500 block">{{ $meta['desc'] }}</span>
                            </div>
                        </div>
                        <div class="text-right shrink-0 pl-2 tabular-nums">
                            <span class="font-bold text-xs text-slate-900 block">{{ $val }} unit</span>
                            <span class="text-[11px] font-medium text-slate-600 block">{{ $pctFormatted }}%</span>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="mt-2.5 flex items-center justify-between text-[11px] text-slate-500 px-0.5 tabular-nums">
                <span>Total Unit Terdistribusi</span>
                <span class="font-semibold text-slate-700">{{ $totalUnits }} unit</span>
            </div>
        </div>
    @endif
</div>
