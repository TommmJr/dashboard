@props(['widget' => [], 'data' => null])

{{-- Widget donut: distribusi NKO per kategori pada periode aktif. --}}
@php
    $period = $data['period'] ?? null;
    $chartSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($widget['key'] ?? 'nko-donut'));
    $chartId = 'nkoDonut-'.$chartSlug.'-'.($widget['instance'] ?? 0);
    $chartData = $data['data'] ?? [];
@endphp

<div class="rounded-lg bg-white border border-slate-200 shadow-sm p-6">
    <h3 class="text-lg font-bold text-primary">{{ $widget['label'] ?? 'Distribusi NKO' }}</h3>

    @if ($period !== null)
        <p class="mt-1 text-xs text-gray-500">Periode {{ $period }}.</p>
    @endif

    @if (empty($chartData))
        <p class="mt-4 rounded-lg bg-slate-50 p-5 text-sm text-gray-600">
            Belum ada data NKO pada periode ini.
        </p>
    @else
        <div id="{{ $chartId }}" class="mt-4 h-64 w-full sm:h-80"></div>
        <script>
            window.addEventListener('DOMContentLoaded', () => {
                const el = document.getElementById(@json($chartId));
                if (!el || !window.echarts) { return; }
                const chart = window.echarts.init(el);
                chart.setOption({
                    tooltip: { trigger: 'item', formatter: '{b}: {c} ({d}%)' },
                    legend: { bottom: 0 },
                    series: [{
                        type: 'pie',
                        radius: ['45%', '70%'],
                        data: @json($chartData),
                        itemStyle: { borderRadius: 6 },
                    }],
                });
                new ResizeObserver(() => chart.resize()).observe(el);
            });
        </script>
    @endif
</div>
