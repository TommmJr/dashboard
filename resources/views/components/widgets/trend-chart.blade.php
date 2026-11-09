@props(['widget' => [], 'data' => null])

{{-- Area chart ECharts --}}
<div class="h-full rounded-lg bg-white p-4 sm:p-5 shadow-sm border border-slate-200">
    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <h3 class="text-base font-bold text-primary">{{ $widget['label'] ?? 'Grafik Tren Kinerja' }}</h3>
        {{-- Subjudul dinamis: jujur soal jumlah periode yang tersedia. --}}
        <p class="text-xs text-slate-500" x-text="chartSubtitle()" x-cloak></p>
    </div>

    <div x-show="loading" class="mt-3 flex h-56 items-center justify-center text-sm text-gray-500 sm:h-64 lg:h-72">
        Memuat data chart...
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
