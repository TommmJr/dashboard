@props(['widget' => [], 'data' => null])

{{-- Widget bar: pencapaian rata-rata (%) per unit untuk periode aktif. --}}
@php
    $labels = collect($data['labels'] ?? []);
    $period = $data['period'] ?? null;
    $chartSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($widget['key'] ?? 'pencapaian-nko'));
    $chartId = 'pencapaianNko-'.$chartSlug.'-'.($widget['instance'] ?? 0);
    $series = $labels->isEmpty() ? [] : [[
        'name' => 'Pencapaian %', 'type' => 'bar', 'data' => $data['data'] ?? [],
        'itemStyle' => ['color' => '#16a3b8'],
    ]];
@endphp

<div class="rounded-lg bg-white border border-slate-200 shadow-sm p-6">
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
                chart.setOption({
                    tooltip: { trigger: 'axis' },
                    grid: { left: 40, right: 20, top: 20, bottom: 40 },
                    xAxis: { type: 'category', data: @json($labels->all()) },
                    yAxis: { type: 'value', max: 120 },
                    series: @json($series),
                });
                new ResizeObserver(() => chart.resize()).observe(el);
            });
        </script>
    @endif
</div>
