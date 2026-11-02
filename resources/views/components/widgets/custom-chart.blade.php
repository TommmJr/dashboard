@props(['widget' => [], 'data' => null])

@php
    $config = $widget['config'] ?? [];
    $title = ! empty($config['title']) ? $config['title'] : ($widget['label'] ?? 'Grafik Kustom');
    $chartType = $config['chart_type'] ?? 'bar';
    $metric = $config['metric'] ?? 'nko_score';
    $instance = $widget['instance'] ?? 0;
    $chartId = 'customChart-' . $instance . '-' . substr(md5(json_encode($config)), 0, 6);
@endphp

<div class="rounded-lg bg-white border border-slate-200 shadow-sm p-6"
     x-data="customChartWidget(@js($config), @js($chartId))"
     x-init="initChart()">
    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <h3 class="text-lg font-bold text-primary">{{ $title }}</h3>
        <span class="text-xs font-semibold px-2 py-0.5 rounded bg-slate-100 text-slate-600">
            {{ $metric === 'nko_score' ? 'Skor NKO' : 'Rasio Pencapaian' }} ({{ ucfirst($chartType) }})
        </span>
    </div>

    <div x-show="loading" class="mt-4 flex h-64 w-full items-center justify-center text-sm text-gray-500 sm:h-80">
        Memuat data grafik...
    </div>

    <div x-show="!loading && empty" x-cloak class="mt-4 flex h-64 w-full items-center justify-center text-sm text-gray-500 sm:h-80">
        Belum ada data untuk metrik ini.
    </div>

    <div x-show="!loading && !empty" x-cloak class="mt-4">
        <div id="{{ $chartId }}" class="custom-chart-container h-64 w-full sm:h-80"></div>
    </div>
</div>

<script>
if (typeof window.customChartWidget !== 'function') {
    window.customChartWidget = function(config, chartId) {
        return {
            loading: true,
            empty: false,
            chart: null,
            initChart() {
                const params = new URLSearchParams(window.location.search);
                params.set('metric', config.metric || 'nko_score');
                params.set('chart_type', config.chart_type || 'bar');
                if (config.title) {
                    params.set('title', config.title);
                }

                fetch(`{{ route('api.dashboard.custom-chart-data') }}?${params.toString()}`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(res => res.json())
                .then(data => {
                    this.loading = false;
                    const seriesData = (data.series && data.series[0] && data.series[0].data) ? data.series[0].data : [];
                    if (!seriesData || seriesData.length === 0) {
                        this.empty = true;
                        return;
                    }
                    this.$nextTick(() => {
                        const el = document.getElementById(chartId);
                        if (!el || !window.echarts) return;
                        this.chart = window.echarts.init(el);

                        let option = {};
                        if (data.chart_type === 'donut') {
                            option = {
                                tooltip: { trigger: 'item', formatter: '{b}: {c}' },
                                legend: { bottom: 0 },
                                series: [{
                                    name: data.series[0].name || 'Metrik',
                                    type: 'pie',
                                    radius: ['45%', '70%'],
                                    data: seriesData,
                                    itemStyle: { borderRadius: 6 }
                                }]
                            };
                        } else {
                            option = {
                                tooltip: { trigger: 'axis' },
                                grid: { left: '3%', right: '4%', bottom: '10%', containLabel: true },
                                xAxis: { type: 'category', data: data.categories || [] },
                                yAxis: { type: 'value' },
                                series: [{
                                    name: data.series[0].name || 'Metrik',
                                    type: data.chart_type === 'line' ? 'line' : 'bar',
                                    data: seriesData,
                                    smooth: data.chart_type === 'line'
                                }]
                            };
                        }
                        this.chart.setOption(option);
                        new ResizeObserver(() => this.chart?.resize()).observe(el);
                    });
                })
                .catch(() => {
                    this.loading = false;
                    this.empty = true;
                });
            }
        };
    };
}
</script>
