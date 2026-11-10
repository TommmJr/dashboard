@props(['widget' => [], 'data' => null])

{{-- Area chart ECharts --}}
<div class="h-full rounded-lg bg-white p-4 sm:p-5 shadow-sm border border-slate-200">
    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <h3 class="text-base font-bold text-primary">{{ $widget['label'] ?? 'Grafik Tren Kinerja' }}</h3>
        {{-- Subjudul dinamis: jujur soal jumlah periode yang tersedia. --}}
        <p class="text-xs text-slate-500" x-text="chartSubtitle()" x-cloak></p>
    </div>

    {{-- Skeleton shimmer loading state --}}
    <div x-show="loading" data-skeleton="trend-chart" class="mt-3 flex h-56 flex-col justify-center gap-3 p-4 sm:h-64 lg:h-72 animate-pulse">
        <div class="h-4 w-1/3 rounded bg-slate-200"></div>
        <div class="flex-1 w-full rounded-lg bg-slate-100 flex items-end gap-2 p-4">
            <div class="w-1/6 h-1/2 bg-slate-200 rounded"></div>
            <div class="w-1/6 h-3/4 bg-slate-200 rounded"></div>
            <div class="w-1/6 h-2/3 bg-slate-200 rounded"></div>
            <div class="w-1/6 h-4/5 bg-slate-200 rounded"></div>
            <div class="w-1/6 h-1/3 bg-slate-200 rounded"></div>
            <div class="w-1/6 h-3/5 bg-slate-200 rounded"></div>
        </div>
        <div class="flex justify-between">
            <div class="h-3 w-16 rounded bg-slate-200"></div>
            <div class="h-3 w-16 rounded bg-slate-200"></div>
            <div class="h-3 w-16 rounded bg-slate-200"></div>
        </div>
    </div>

    <div x-show="!loading && empty" x-cloak class="mt-3 flex h-56 flex-col items-center justify-center gap-2 text-sm text-gray-500 sm:h-64 lg:h-72">
        <p>Belum ada data kinerja yang aktif.</p>
        @can('dataset.import')
            <a href="{{ route('datasets.create') }}" class="text-accent hover:underline">Upload dataset pertama</a>
        @endcan
    </div>

    {{-- Chart selalu tampil. Bentuknya menyesuaikan jumlah periode: 1 periode =
         bar per unit (Y dari 0, satu warna, urut tertinggi) supaya snapshot
         terbaca jujur; >1 periode = garis tren per unit. Logika bentuk ada di
         renderChart(), bukan di sini. --}}
    <div x-show="!loading && !empty" x-cloak class="mt-2">
        <div id="performanceChart" class="h-56 w-full sm:h-64 lg:h-72"></div>
    </div>
</div>
