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
            'badge' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
            'dot' => 'bg-emerald-500',
        ],
        'Hampir Tercapai' => [
            'desc' => '95 ≤ NKO < 100',
            'color' => '#f59e0b',
            'badge' => 'border-amber-200 bg-amber-50 text-amber-800',
            'dot' => 'bg-amber-500',
        ],
        'Perlu Peningkatan' => [
            'desc' => 'NKO < 95',
            'color' => '#ef4444',
            'badge' => 'border-rose-200 bg-rose-50 text-rose-800',
            'dot' => 'bg-rose-500',
        ],
    ];

    $gridCols = match (count($chartData)) {
        1 => 'grid-cols-1',
        2 => 'grid-cols-1 sm:grid-cols-2',
        default => 'grid-cols-1 sm:grid-cols-3',
    };
@endphp

<div class="h-full rounded-lg bg-white border border-slate-200 shadow-sm p-6 flex flex-col justify-between">
    <div>
        <h3 class="text-lg font-bold text-primary">{{ $widget['label'] ?? 'Distribusi NKO' }}</h3>

        @if ($period !== null)
            <p class="mt-1 text-xs text-gray-500">Periode {{ $period }}.</p>
        @endif

        @if (empty($chartData))
            <p class="mt-4 rounded-lg bg-slate-50 p-5 text-sm text-gray-600">
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

                            const categoryColors = {
                                Tercapai: "#10b981",
                                "Hampir Tercapai": "#f59e0b",
                                "Perlu Peningkatan": "#ef4444"
                            };
                            const rawData = @json($chartData);
                            const seriesData = rawData.map(item => ({
                                name: item.name,
                                value: item.value,
                                itemStyle: {
                                    color: categoryColors[item.name] || "#64748b",
                                    borderRadius: 6
                                }
                            }));

                            chartInstance.setOption({
                                animation: true,
                                animationDuration: 650,
                                animationEasing: "cubicOut",
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
                    <div class="flex items-center justify-between rounded-lg border p-2.5 text-xs {{ $meta['badge'] }}">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="h-2.5 w-2.5 rounded-full shrink-0 {{ $meta['dot'] }}"></span>
                            <div class="truncate">
                                <span class="font-semibold block text-slate-900">{{ $name }}</span>
                                <span class="text-[11px] text-slate-500 block">{{ $meta['desc'] }}</span>
                            </div>
                        </div>
                        <div class="text-right shrink-0 pl-2">
                            <span class="font-bold text-xs text-slate-900 block">{{ $val }} unit</span>
                            <span class="text-[11px] font-medium text-slate-600 block">{{ $pctFormatted }}%</span>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="mt-2.5 flex items-center justify-between text-[11px] text-slate-500 px-0.5">
                <span>Total Unit Terdistribusi</span>
                <span class="font-semibold text-slate-700">{{ $totalUnits }} unit</span>
            </div>
        </div>
    @endif
</div>
