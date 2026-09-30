@props(['widget' => [], 'data' => null])

{{-- Area chart ECharts --}}
<div class="rounded-lg bg-white p-6 shadow">
    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <h3 class="text-lg font-bold text-primary">{{ $widget['label'] ?? 'Grafik Tren Kinerja' }}</h3>
        {{-- Subjudul dinamis: jujur soal jumlah periode yang tersedia. --}}
        <p class="text-sm text-slate-500" x-text="chartSubtitle()" x-cloak></p>
    </div>

    <div x-show="loading" class="mt-6 flex h-80 items-center justify-center text-sm text-gray-500 sm:h-96 lg:h-[32rem]">
        Memuat data chart...
    </div>

    <div x-show="!loading && empty" x-cloak class="mt-6 flex h-80 flex-col items-center justify-center gap-2 text-sm text-gray-500 sm:h-96 lg:h-[32rem]">
        <p>Belum ada data kinerja yang aktif.</p>
        @can('dataset.import')
            <a href="{{ route('datasets.create') }}" class="text-accent hover:underline">Upload dataset pertama</a>
        @endcan
    </div>

    {{-- Chart selalu tampil. Bentuknya menyesuaikan jumlah periode: 1 periode =
         bar per unit (Y dari 0, satu warna, urut tertinggi) supaya snapshot
         terbaca jujur; >1 periode = garis tren per unit. Logika bentuk ada di
         renderChart(), bukan di sini. --}}
    <div x-show="!loading && !empty" x-cloak class="mt-4">
        <div id="performanceChart" class="h-80 w-full sm:h-96 lg:h-[32rem]"></div>
    </div>
</div>
