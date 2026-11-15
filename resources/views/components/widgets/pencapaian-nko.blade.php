@props(['widget' => [], 'data' => null])

{{-- Widget bar: pencapaian rata-rata (%) per unit untuk periode aktif. --}}
@php
    $labels = collect($data['labels'] ?? []);
    $period = $data['period'] ?? null;
    $chartSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($widget['key'] ?? 'pencapaian-nko'));
    $chartId = 'pencapaianNko-'.$chartSlug.'-'.($widget['instance'] ?? 0);
    $dataId = 'data-'.$chartId;
    $rag = \App\Support\KpiScoreNormalizer::ragThresholds();
    $pencapaianChartData = [
        'labels' => $labels->all(),
        'seriesData' => $data['data'] ?? [],
        'rag' => $rag,
    ];
@endphp

<div class="ibs-card h-full p-5">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="ibs-section-title text-base font-bold text-slate-900">{{ $widget['label'] ?? 'Pencapaian % per Unit' }}</h3>
        @if ($period !== null)
            <span class="inline-flex items-center rounded-full bg-[#e6f4f6] px-2.5 py-0.5 text-[11px] font-semibold text-[#0b6175]">
                Periode {{ $period }}
            </span>
        @endif
    </div>

    @if ($labels->isEmpty())
        <p class="mt-4 rounded-xl border border-slate-200/70 bg-slate-50 p-4 text-xs text-slate-500">
            Belum ada data pencapaian pada periode ini.
        </p>
    @else
        <div id="{{ $chartId }}" class="mt-4 w-full" style="width: 100%; height: 320px;"></div>
        <script type="application/json" id="{{ $dataId }}">
            @json($pencapaianChartData)
        </script>
        <script>
        (function() {
            let chartInstance = null;
            let attempts = 0;
            function initPencapaianChart() {
                const el = document.getElementById(@json($chartId));
                const dataEl = document.getElementById(@json($dataId));
                if (!el || !dataEl) { return; }
                if (!window.echarts) {
                    if (attempts < 30) {
                        attempts += 1;
                        window.setTimeout(initPencapaianChart, 40);
                    }
                    return;
                }

                let parsed = {};
                try {
                    parsed = JSON.parse(dataEl.textContent);
                } catch (e) {
                    return;
                }

                if (chartInstance) {
                    chartInstance.dispose();
                }
                chartInstance = window.echarts.init(el);
                const rag = parsed.rag || { tercapai_min: 100, hampir_min: 95 };
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

                chartInstance.setOption({
                    animation: true,
                    animationDuration: 650,
                    animationEasing: 'cubicOut',
                    animationDurationUpdate: 400,
                    tooltip: {
                        trigger: 'axis',
                        formatter: (params) => {
                            const p = params[0];
                            return `<div class="text-xs">
                                <strong>${p.name}</strong><br/>
                                Pencapaian: <b>${Number(p.value).toFixed(1)}%</b>
                            </div>`;
                        }
                    },
                    grid: { left: 45, right: 20, top: 25, bottom: 40 },
                    xAxis: {
                        type: 'category',
                        data: parsed.labels || [],
                        axisLabel: { fontSize: 11, color: palette.axisText }
                    },
                    yAxis: {
                        type: 'value',
                        max: 120,
                        axisLabel: { formatter: '{value}%', fontSize: 10, color: palette.axisText },
                        splitLine: { lineStyle: { color: palette.gridLine } }
                    },
                    series: [{
                        name: 'Pencapaian %',
                        type: 'bar',
                        data: parsed.seriesData || [],
                        itemStyle: {
                            color: (params) => {
                                const val = Math.round(Number(params.value || 0) * 10) / 10;
                                if (val >= rag.tercapai_min) return palette.rag.tercapai;
                                if (val >= rag.hampir_min) return palette.rag.waspada;
                                return palette.rag.kritis;
                            },
                            borderRadius: [6, 6, 0, 0]
                        },
                        markLine: {
                            silent: true,
                            symbol: 'none',
                            lineStyle: { color: palette.primary, type: 'dashed', width: 1.5 },
                            label: { formatter: 'Target 100%', position: 'end', fontSize: 10, color: palette.primary },
                            data: [{ yAxis: 100 }]
                        }
                    }],
                });
                new ResizeObserver(() => chartInstance && chartInstance.resize()).observe(el);
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initPencapaianChart);
            } else {
                initPencapaianChart();
            }
        })();
        </script>
    @endif
</div>
