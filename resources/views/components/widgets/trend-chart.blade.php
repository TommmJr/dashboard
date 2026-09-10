@props(['widget' => [], 'data' => null])

{{-- Area chart ECharts --}}
<div class="mt-8 rounded-lg bg-white p-6 shadow">
    <h3 class="text-lg font-bold text-primary">{{ $widget['label'] ?? 'Grafik Tren Kinerja' }}</h3>

    <div x-show="loading" class="mt-6 flex h-80 items-center justify-center text-sm text-gray-500 sm:h-96 lg:h-[32rem]">
        Memuat data chart...
    </div>

    <div x-show="!loading && empty" x-cloak class="mt-6 flex h-80 flex-col items-center justify-center gap-2 text-sm text-gray-500 sm:h-96 lg:h-[32rem]">
        <p>Belum ada data kinerja yang aktif.</p>
        @can('dataset.import')
            <a href="{{ route('datasets.create') }}" class="text-accent hover:underline">Upload dataset pertama</a>
        @endcan
    </div>

    <div x-show="!loading && !empty" x-cloak class="mt-4">
        <div id="performanceChart" class="h-80 w-full sm:h-96 lg:h-[32rem]"></div>
    </div>
</div>
