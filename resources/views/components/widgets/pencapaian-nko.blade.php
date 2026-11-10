@props(['widget' => [], 'data' => null])

{{-- Widget bar: pencapaian rata-rata (%) per unit untuk periode aktif. --}}
@php
    $labels = collect($data['labels'] ?? []);
    $period = $data['period'] ?? null;
    $chartSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($widget['key'] ?? 'pencapaian-nko'));
    $chartId = 'pencapaianNko-'.$chartSlug.'-'.($widget['instance'] ?? 0);
    $rag = \App\Support\KpiScoreNormalizer::ragThresholds();
@endphp

<div class="h-full rounded-lg bg-white border border-slate-200 shadow-sm p-6">
    <h3 class="text-lg font-bold text-primary">{{ $widget['label'] ?? 'Pencapaian % per Unit' }}</h3>

    @if ($period !== null)
        <p class="mt-1 text-xs text-gray-500">Periode {{ $period }}.</p>
    @endif

    @if ($labels->isEmpty())
        <p class="mt-4 rounded-lg bg-slate-50 p-5 text-sm text-gray-600">
            Belum ada data pencapaian pada periode ini.
        </p>
    @else
        <div id="{{ $chartId }}" class="mt-4 h-64 w-full sm:h-80"></div>
        <script>
            window.addEventListener('DOMContentLoaded', () => {
                const el = document.getElementById(@json($chartId));
                if (!el || !window.echarts) { return; }
                const chart = window.echarts.init(el);
                const rag = @json($rag);
                chart.setOption({
                    tooltip: { trigger: 'axis' },
                    grid: { left: 40, right: 20, top: 20, bottom: 40 },
                    xAxis: { type: 'category', data: @json($labels->all()) },
                    yAxis: { type: 'value', max: 120 },
                    series: [{
                        name: 'Pencapaian %',
                        type: 'bar',
                        data: @json($data['data'] ?? []),
                        itemStyle: {
                            color: (params) => {
                                const val = Number(params.value || 0);
                                if (val >= rag.tercapai_min) return '#10b981'; // emerald-500
                                if (val >= rag.hampir_min) return '#f59e0b';   // amber-500
                                return '#f43f5e';                             // rose-500
                            },
                            borderRadius: [6, 6, 0, 0]
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
                new ResizeObserver(() => chart.resize()).observe(el);
            });
        </script>
    @endif
</div>
