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

    {{-- Snapshot (1 periode): data belum cukup untuk menggambar tren. Menampilkan
         satu titik akan terbaca seperti chart rusak, jadi panel ini jujur
         menyatakan menunggu periode berikutnya. Begitu periode ke-2 masuk,
         blok ini hilang dan chart tren tampil otomatis. --}}
    <div x-show="!loading && !empty && chartPeriodCount <= 1" x-cloak class="mt-6 flex h-80 flex-col items-center justify-center gap-3 text-center sm:h-96 lg:h-[32rem]">
        <svg class="h-10 w-10 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
            <path d="M3 3v18h18" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M7 15l4-4 3 3 5-6" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <p class="text-base font-semibold text-primary">Tren akan tampil setelah ada 2 periode atau lebih</p>
        <p class="text-sm text-gray-500">
            Data terkini: <span class="font-medium text-primary" x-text="chartSubtitle()"></span>
        </p>
        <p class="text-xs text-gray-400">Update data rutin bulanan — periode berikutnya otomatis menjadi titik tren kedua.</p>
    </div>

    <div x-show="!loading && !empty && chartPeriodCount > 1" x-cloak class="mt-4">
        <div id="performanceChart" class="h-80 w-full sm:h-96 lg:h-[32rem]"></div>
    </div>
</div>
