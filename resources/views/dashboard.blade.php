{{--
    Halaman Dashboard (halaman utama setelah login).
    - Dilindungi middleware 'auth', jadi hanya user yang login yang bisa lihat.
    - Chart ECharts (bar + line) + KPI card diisi data riil dari
      GET /api/performance-data (scoping per divisi ditegakkan backend;
      dataset ARCHIVED tidak dihitung).
    - Tombol logout memakai POST + @csrf (aman dari CSRF / clickjacking).
--}}
@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<nav class="bg-white shadow">
    <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3">
        <span class="font-semibold text-gray-900">Dashboard</span>
        <div class="flex items-center gap-4">
            <span class="text-sm text-gray-600">{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}" onsubmit="return confirm('Yakin ingin logout?');">
                @csrf
                <button type="submit" class="text-sm text-gray-600 hover:text-gray-900">Log out</button>
            </form>
        </div>
    </div>
</nav>

<div class="mx-auto max-w-7xl px-4 py-8" x-data="performanceDashboard()" x-init="load()">
    <div class="flex items-center gap-3">
        <h1 class="text-2xl font-bold text-primary">Welcome, {{ auth()->user()->name }}</h1>
        @if (auth()->user()->role)
            <span class="inline-flex rounded-full bg-surface px-2.5 py-0.5 text-xs font-medium text-primary">{{ auth()->user()->role->name }}</span>
        @endif
    </div>

    <div class="mt-6 flex flex-wrap gap-3">
        @can('user.view')
            <a href="{{ route('users.index') }}" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">Manage users</a>
        @endcan
        @can('dataset.view')
            <a href="{{ route('datasets.index') }}" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-accent">Datasets</a>
        @endcan
        @can('dataset.import')
            <a href="{{ route('datasets.create') }}" class="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white hover:bg-primary">Upload data</a>
        @endcan
        @can('dashboard.update')
            <span class="rounded-lg bg-muted px-4 py-2 text-sm font-medium text-gray-500">Edit dashboard (menyusul, Tahap 6)</span>
        @endcan
    </div>

    {{-- KPI cards --}}
    <div class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-lg bg-surface p-5 shadow">
            <p class="text-sm text-gray-600">Total Nilai Kinerja</p>
            <p class="mt-1 text-2xl font-bold text-primary" x-text="kpi ? formatNumber(kpi.total_value) : '-'"></p>
        </div>
        <div class="rounded-lg bg-surface p-5 shadow">
            <p class="text-sm text-gray-600">Dataset READY</p>
            <p class="mt-1 text-2xl font-bold text-primary" x-text="kpi ? kpi.ready_datasets : '-'"></p>
        </div>
        <div class="rounded-lg bg-surface p-5 shadow">
            <p class="text-sm text-gray-600">Baris Data Aktif</p>
            <p class="mt-1 text-2xl font-bold text-primary" x-text="kpi ? kpi.metric_count : '-'"></p>
        </div>
    </div>

    {{-- Area chart ECharts --}}
    <div class="mt-8 rounded-lg bg-white p-6 shadow">
        <h3 class="text-lg font-bold text-primary">Performance Trends</h3>

        <div x-show="loading" class="mt-6 flex h-72 items-center justify-center text-sm text-gray-500">
            Memuat data chart...
        </div>

        <div x-show="!loading && empty" x-cloak class="mt-6 flex h-72 flex-col items-center justify-center gap-2 text-sm text-gray-500">
            <p>Belum ada data kinerja yang aktif.</p>
            @can('dataset.import')
                <a href="{{ route('datasets.create') }}" class="text-accent hover:underline">Upload dataset pertama</a>
            @endcan
        </div>

        <div x-show="!loading && !empty" x-cloak class="mt-4">
            <div id="performanceChart" class="h-80 w-full"></div>
        </div>
    </div>
</div>

<script>
    function performanceDashboard() {
        return {
            loading: true,
            empty: false,
            kpi: null,
            chart: null,

            formatNumber(value) {
                return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(value);
            },

            async load() {
                try {
                    const response = await fetch("{{ route('performance.data') }}", {
                        headers: { 'Accept': 'application/json' },
                    });
                    const payload = await response.json();

                    this.kpi = payload.kpi;
                    this.empty = payload.labels.length === 0;

                    if (!this.empty) {
                        this.$nextTick(() => this.renderChart(payload));
                    }
                } catch (e) {
                    console.error('Chart loading error:', e);
                    this.empty = true;
                } finally {
                    this.loading = false;
                }
            },

            renderChart(payload) {
                const palette = ['#14a2ba', '#125d72', '#efe62f', '#d9d9d9'];
                const el = document.getElementById('performanceChart');
                if (this.chart) { this.chart.dispose(); }
                this.chart = window.echarts.init(el);

                // Setiap divisi: satu bar series; series pertama juga
                // digambar ulang sebagai line untuk menunjukkan trend.
                const barSeries = payload.series.map((s, i) => ({
                    name: s.name,
                    type: 'bar',
                    data: s.data,
                    itemStyle: { color: palette[i % palette.length] },
                }));
                const lineSeries = payload.series.slice(0, 1).map((s) => ({
                    name: s.name + ' (trend)',
                    type: 'line',
                    smooth: true,
                    data: s.data,
                    itemStyle: { color: '#125d72' },
                }));

                this.chart.setOption({
                    tooltip: { trigger: 'axis' },
                    legend: { bottom: 0 },
                    grid: { left: 60, right: 20, top: 20, bottom: 40 },
                    xAxis: { type: 'category', data: payload.labels },
                    yAxis: { type: 'value' },
                    series: [...barSeries, ...lineSeries],
                });

                window.addEventListener('resize', () => this.chart && this.chart.resize());
            },
        };
    }
</script>
@endsection
