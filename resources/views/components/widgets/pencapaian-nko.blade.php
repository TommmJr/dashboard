@props(['widget' => [], 'data' => null])

{{-- Widget bar: pencapaian rata-rata (%) per unit untuk periode aktif. --}}
@php
    $labels = collect($data['labels'] ?? []);
    $period = $data['period'] ?? null;
    $chartSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($widget['key'] ?? 'pencapaian-nko'));
    $chartId = 'pencapaianNko-'.$chartSlug.'-'.($widget['instance'] ?? 0);
    $dataId = 'data-'.$chartId;
    $rag = \App\Support\KpiScoreNormalizer::ragThresholds();
@endphp

<div class="h-full rounded-xl bg-white border border-slate-200/80 shadow-xs p-4 sm:p-5 transition-all duration-200 ease-out hover:shadow-md">
    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <h3 class="text-base font-bold text-primary">{{ $widget['label'] ?? 'Pencapaian % per Unit' }}</h3>
        @if ($period !== null)
            <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold text-slate-600">
                Periode {{ $period }}
            </span>
        @endif
    </div>

    @if ($labels->isEmpty())
        <p class="mt-4 rounded-lg bg-slate-50 p-4 text-xs text-slate-500">
            Belum ada data pencapaian pada periode ini.
        </p>
    @else
        <div id="{{ $chartId }}" class="mt-4 w-full" style="width: 100%; height: 320px;"></div>
        <script type="application/json" id="{{ $dataId }}">
        {
            "labels": {{ Js::from($labels->all()) }},
            "seriesData": {{ Js::from($data['data'] ?? []) }},
            "rag": {{ Js::from($rag) }}
        }
        </script>
        <script>
        (function() {
            let chartInstance = null;
            function initPencapaianChart() {
                const el = document.getElementById(@json($chartId));
                const dataEl = document.getElementById(@json($dataId));
                if (!el || !dataEl || !window.echarts) { return; }

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
                        axisLabel: { fontSize: 11, color: '#475569' }
                    },
                    yAxis: {
                        type: 'value',
                        max: 120,
                        axisLabel: { formatter: '{value}%', fontSize: 10 },
                        splitLine: { lineStyle: { color: '#f1f5f9' } }
                    },
                    series: [{
                        name: 'Pencapaian %',
                        type: 'bar',
                        data: parsed.seriesData || [],
                        itemStyle: {
                            color: (params) => {
                                const val = Number(params.value || 0);
                                if (val >= rag.tercapai_min) return '#10b981'; // emerald-500
                                if (val >= rag.hampir_min) return '#f59e0b';   // amber-500
                                return '#f43f5e';                             // rose-500
                            },
                            borderRadius: [4, 4, 0, 0]
                        },
                        markLine: {
                            silent: true,
                            symbol: 'none',
                            lineStyle: { color: '#0b6175', type: 'dashed', width: 1.5 },
                            label: { formatter: 'Target 100%', position: 'end', fontSize: 10, color: '#0b6175' },
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
