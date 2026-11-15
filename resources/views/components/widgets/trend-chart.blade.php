@props(['widget' => [], 'data' => null])

{{-- Area chart ECharts --}}
<div class="ibs-card h-full p-5">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="ibs-section-title text-base font-bold text-slate-900">{{ $widget['label'] ?? 'Tren NKO' }}</h3>
        <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold text-slate-700 tabular-nums whitespace-nowrap">Target 100</span>
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

    <div x-show="!loading && error !== ''" x-cloak class="mt-3 flex h-56 flex-col items-center justify-center gap-2 text-sm text-rose-700 sm:h-64 lg:h-72" role="alert">
        <p x-text="error"></p>
        <button type="button" @click="load()" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 shadow-2xs">
            Muat Ulang
        </button>
    </div>

    <div x-show="!loading && empty && error === ''" x-cloak class="mt-3 flex h-56 flex-col items-center justify-center gap-2 text-sm text-slate-500 sm:h-64 lg:h-72">
        <p>Belum ada data kinerja yang aktif.</p>
        @can('dataset.import')
            <a href="{{ route('datasets.create') }}" class="text-xs font-semibold text-[#0b6175] hover:underline">Upload dataset pertama</a>
        @endcan
    </div>

    {{-- Chart selalu tampil. Bentuknya menyesuaikan jumlah periode: 1 periode =
         bar per unit (Y dari 0, satu warna, urut tertinggi) supaya snapshot
         terbaca jujur; >1 periode = garis tren per unit. --}}
    <div x-show="!loading && !empty && error === ''" x-cloak class="mt-3">
        <div id="performanceChart" class="h-56 w-full sm:h-64 lg:h-72" style="width: 100%; height: 288px; min-height: 224px;"></div>
    </div>
</div>
