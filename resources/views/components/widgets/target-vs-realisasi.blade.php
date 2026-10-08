@props(['widget' => [], 'data' => null])

{{-- Widget bar grouped: Target vs Realisasi per unit (periode aktif).
    Data: DashboardController::targetVsRealisasi() — per unit, satu seri
    Target, satu seri Realisasi (angka diakumulasi per baris '| target' /
    '| realisasi'). Tampil default untuk role yang memakai layout
    staff_divisi. --}}
@php
    $labels = collect($data['labels'] ?? []);
    $period = $data['period'] ?? null;
    $chartSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($widget['key'] ?? 'target-vs-realisasi'));
    $chartId = 'targetVsRealisasi-'.$chartSlug.'-'.($widget['instance'] ?? 0);
    $series = [];
    if (! empty($data['labels'])) {
        $series = [
            ['name' => 'Target', 'type' => 'bar', 'data' => $data['target'] ?? [], 'itemStyle' => ['color' => '#d8e3e7']],
            ['name' => 'Realisasi', 'type' => 'bar', 'data' => $data['realisasi'] ?? [], 'itemStyle' => ['color' => '#16a3b8']],
        ];
    }
@endphp

<div class="rounded-lg bg-white border border-slate-200 shadow-sm p-6">
    <h3 class="text-lg font-bold text-primary">{{ $widget['label'] ?? 'Target vs Realisasi' }}</h3>

    @if ($period !== null)
        <p class="mt-1 text-xs text-gray-500">Periode {{ $period }} — total per unit dari seluruh indikator.</p>
    @endif

    @if ($labels->isEmpty())
        <p class="mt-4 rounded-lg bg-slate-50 p-5 text-sm text-gray-600">
            Belum ada data target/realisasi pada periode ini.
        </p>
    @else
        <div id="{{ $chartId }}" class="mt-4 h-80 w-full sm:h-96"></div>
        <script>
            window.addEventListener('DOMContentLoaded', () => {
                const el = document.getElementById(@json($chartId));
                if (!el || !window.echarts) { return; }
                const chart = window.echarts.init(el);
                // Display-only: ECharts default tooltip memakai addCommas yang
                // MEMPERTAHANKAN ekor float (mis. 322,247.70999999996 dari
                // penjumlahan float R8). valueFormatter membulatkan ke maksimal
                // 2 desimal TANPA menyentuh data/payload/agregasi. Format ribuan
                // (koma) dipertahankan seperti default; null/NaN tetap '-'.
                const fmtTooltipNumber = (value) => (typeof value === 'number' && Number.isFinite(value))
                    ? value.toLocaleString('id-ID', { maximumFractionDigits: 2 })
                    : (value === null || value === undefined || typeof value === 'number') ? '-' : value;
                const fmtTooltipValue = (value) => Array.isArray(value)
                    ? value.map(fmtTooltipNumber)
                    : fmtTooltipNumber(value);
                chart.setOption({
                    tooltip: {
                        trigger: 'axis',
                        axisPointer: { type: 'shadow' },
                        valueFormatter: fmtTooltipValue,
                    },
                    legend: { bottom: 0 },
                    grid: { left: 60, right: 20, top: 20, bottom: 60 },
                    xAxis: { type: 'category', data: @json($labels->all()) },
                    yAxis: { type: 'value' },
                    series: @json($series),
                });
                new ResizeObserver(() => chart.resize()).observe(el);
            });
        </script>
    @endif
</div>
