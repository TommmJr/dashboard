@props(['widget' => [], 'data' => null])

@php
    $config = $widget['config'] ?? [];
    $title = ! empty($config['title']) ? $config['title'] : ($widget['label'] ?? 'Grafik Kustom');
    $chartType = $config['chart_type'] ?? 'bar';
    $metric = $config['metric'] ?? 'nko_score';
    $instance = $widget['instance'] ?? 0;
    $chartId = 'customChart-' . $instance . '-' . substr(md5(json_encode($config)), 0, 6);
@endphp

<div class="ibs-card p-5"
     x-data="customChartWidget(@js($config), @js($chartId))"
     x-init="initChart()">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="ibs-section-title text-base font-bold text-slate-900">{{ $title }}</h3>
        <span class="inline-flex items-center rounded-full bg-[#e6f4f6] px-2.5 py-0.5 text-[11px] font-semibold text-[#0b6175]">
            {{ $metric === 'nko_score' ? 'Skor NKO' : 'Rasio Pencapaian' }} ({{ ucfirst($chartType) }})
        </span>
    </div>

    <div x-show="loading" class="mt-4 flex h-64 w-full items-center justify-center text-xs font-medium text-slate-500 sm:h-80">
        Memuat data grafik...
    </div>

    <div x-show="!loading && empty" x-cloak class="mt-4 flex h-64 w-full items-center justify-center text-xs font-medium text-slate-500 sm:h-80">
        Belum ada data untuk metrik ini.
    </div>

    <div x-show="!loading && !empty" x-cloak class="mt-4">
        <div id="{{ $chartId }}" class="custom-chart-container h-64 w-full sm:h-80" style="width: 100%; height: 320px; min-height: 256px;"></div>
    </div>
</div>

<script>
if (typeof window.customChartWidget !== 'function') {
    window.customChartWidget = function(config, chartId) {
        let chartInstance = null;

        return {
            loading: true,
            empty: false,
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
                        if (!chartInstance) {
                            chartInstance = window.echarts.getInstanceByDom(el) || window.echarts.init(el);
                            new ResizeObserver(() => {
                                if (chartInstance && !chartInstance.isDisposed()) {
                                    chartInstance.resize();
                                }
                            }).observe(el);
                        }

                        const palette = window.PLN_CHART_PALETTE || {
                            primary: '#0b6175',
                            accent: '#16a3b8',
                            softTeal: '#bce3e8',
                            targetSlate: '#cbd5e1',
                            gridLine: '#f1f5f9',
                            axisText: '#64748b',
                            rag: {
                                tercapai: '#10b981',
                                waspada: '#f59e0b',
                                kritis: '#f43f5e'
                            }
                        };

                        const fmtTooltipNumber = (v) => (v === null || v === undefined || String(v).trim() === '' || Number.isNaN(Number(v)))
                            ? '-'
                            : Number(v).toLocaleString('id-ID', { maximumFractionDigits: 1 });
                        const escHtml = (s) => String(s ?? '').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
                        let option = {};
                        if (data.chart_type === 'donut') {
                            option = {
                                animation: true,
                                animationDuration: 650,
                                animationEasing: 'cubicOut',
                                color: [palette.primary, palette.accent, palette.softTeal, palette.rag.tercapai, palette.rag.waspada, palette.targetSlate],
                                tooltip: { trigger: 'item', formatter: (p) => `${escHtml(p.name)}: ${fmtTooltipNumber(p.value)}` },
                                legend: { bottom: 0, textStyle: { color: palette.axisText, fontSize: 11 } },
                                series: [{
                                    name: data.series[0].name || 'Metrik',
                                    type: 'pie',
                                    radius: ['45%', '70%'],
                                    data: seriesData,
                                    itemStyle: { borderRadius: 6 }
                                }]
                            };
                        } else {
                            const isLine = data.chart_type === 'line';
                            option = {
                                animation: true,
                                animationDuration: 650,
                                animationEasing: 'cubicOut',
                                tooltip: { trigger: 'axis', valueFormatter: fmtTooltipNumber },
                                grid: { left: '3%', right: '4%', bottom: '10%', containLabel: true },
                                xAxis: {
                                    type: 'category',
                                    data: data.categories || [],
                                    axisLabel: { color: palette.axisText, fontSize: 11 }
                                },
                                yAxis: {
                                    type: 'value',
                                    axisLabel: { color: palette.axisText, fontSize: 11 },
                                    splitLine: { lineStyle: { color: palette.gridLine } }
                                },
                                series: [{
                                    name: data.series[0].name || 'Metrik',
                                    type: isLine ? 'line' : 'bar',
                                    data: seriesData,
                                    smooth: isLine,
                                    itemStyle: {
                                        color: palette.primary,
                                        borderRadius: isLine ? 0 : [6, 6, 0, 0]
                                    },
                                    lineStyle: isLine ? { color: palette.primary, width: 2.5 } : undefined
                                }]
                            };
                        }
                        chartInstance.setOption(option);
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
