@props(['widget' => [], 'data' => null])

{{-- KPI cards (kartu putih di atas background abu, ikut mockup) --}}
<div class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-3">
    <div class="rounded-lg bg-white p-5 shadow">
        <p class="text-sm text-gray-600">Rata-rata NKO</p>
        <p class="mt-1 text-2xl font-bold text-primary" x-text="kpi ? formatNumber(kpi.average_nko) : '-'"></p>
    </div>
    <div class="rounded-lg bg-white p-5 shadow">
        <p class="text-sm text-gray-600">Dataset READY</p>
        <p class="mt-1 text-2xl font-bold text-primary" x-text="kpi ? kpi.ready_datasets : '-'"></p>
    </div>
    <div class="rounded-lg bg-white p-5 shadow">
        <p class="text-sm text-gray-600">Baris Data Aktif</p>
        <p class="mt-1 text-2xl font-bold text-primary" x-text="kpi ? kpi.metric_count : '-'"></p>
    </div>
</div>
