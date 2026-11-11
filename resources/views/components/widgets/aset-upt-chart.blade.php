@props(['widget' => [], 'data' => null])

{{-- Widget grafik stok aset per UPT (server-side, snapshot periode).
     Data: DashboardController::asetUptChart() — labels UPT, satu series bar
     per atribut (satuan tidak dicampur). Tanpa line/trend karena datanya
     snapshot, bukan time-series. Sejak 2026-11-11 widget dipasang HANYA di
     layout default staf Managemen Aset sebagai demo prototipe (instruksi
     user, bukan sign-off mentor/PIC); badge di header menandai hal ini.
     Daftar series mengikuti atribut apa pun yang ada di data (probe
     workbook: 14) — PROVISIONAL, subset atribut final menunggu data
     aktual dan keputusan MANSET.
     DOM id unik per render (key + counter) agar aman bila widget yang
     sama tampil lebih dari sekali dalam satu halaman. --}}
@php
    $labels = collect($data['labels'] ?? []);
    $series = collect($data['series'] ?? []);
    $period = $data['period'] ?? null;
    $chartSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($widget['key'] ?? 'aset-upt-chart'));
    $chartId = 'asetUptChart-'.$chartSlug.'-'.($widget['instance'] ?? 0);
    $palette = ['#14a2ba', '#125d72', '#efe62f', '#d9d9d9', '#0b7a75', '#f39233', '#7a5195', '#ef5675', '#2f9e44', '#1971c2'];
    $chartSeries = $series->map(fn ($item, $index) => [
        'name' => $item['name'],
        'type' => 'bar',
        'data' => $item['data'],
        'itemStyle' => ['color' => $palette[$index % count($palette)]],
    ])->values()->all();
@endphp

<div class="mt-8 rounded-lg bg-white p-6 shadow">
    <div class="flex flex-wrap items-center gap-2">
        <h3 class="text-lg font-bold text-primary">{{ $widget['label'] ?? 'Stok Aset per UPT' }}</h3>
        <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800">Prototipe Demo (Rekapitulasi UPT)</span>
    </div>

    @if ($period !== null)
        <p class="mt-1 text-xs text-gray-500">Periode {{ $period }} — agregat per UPT dari rekap ULTG.</p>
    @endif

    @if ($labels->isEmpty() || $series->isEmpty())
        <p class="mt-4 rounded-lg bg-surface p-5 text-sm text-gray-600">
            Belum ada data aset. Konversi rekap <code>KIN26 - STOP ONLY</code> lewat
            <code>aset:convert</code> lalu unggah berkasnya lewat menu Upload Dataset (divisi Data Aset).
        </p>
    @else
        <div id="{{ $chartId }}" class="mt-4 h-80 w-full sm:h-96"></div>
        <script>
            window.addEventListener('DOMContentLoaded', () => {
                const el = document.getElementById(@json($chartId));
                if (!el || !window.echarts) { return; }
                const chart = window.echarts.init(el);
                chart.setOption({
                    tooltip: { trigger: 'axis' },
                    legend: { bottom: 0 },
                    grid: { left: 60, right: 20, top: 20, bottom: 60 },
                    xAxis: { type: 'category', data: @json($labels->all()) },
                    yAxis: { type: 'value', min: 0 },
                    series: @json($chartSeries),
                });
                new ResizeObserver(() => chart.resize()).observe(el);
            });
        </script>
    @endif
</div>
