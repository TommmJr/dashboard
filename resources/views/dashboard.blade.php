{{--
    Halaman Dashboard (halaman utama setelah login).
    - Dilindungi middleware 'auth', jadi hanya user yang login yang bisa lihat.
    - Daftar widget ditentukan DashboardController dari
      config/dashboard_layouts.php (per role, difilter Gate di backend),
      lalu di-render via SATU loop x-dynamic-component ke komponen di
      resources/views/components/widgets/.
    - State Alpine performanceDashboard() tetap di wrapper file ini:
      widget kpi-summary & trend-chart berbagi SATU fetch bersama dari
      GET /api/performance-data (sengaja tidak dipecah per widget).
    - Tombol logout memakai POST + @csrf (aman dari CSRF / clickjacking).
--}}
@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<div class="mx-auto max-w-7xl" x-data="performanceDashboard()" x-init="load()">
    <div class="flex items-center gap-3">
        <h1 class="text-2xl font-bold text-primary">Welcome, {{ auth()->user()->name }}</h1>

        @if (auth()->user()->role)
            <span class="inline-flex rounded-full bg-surface px-2.5 py-0.5 text-xs font-medium text-primary">{{ auth()->user()->role->name }}</span>
        @endif
    </div>

    {{-- Widget per role: satu loop generik, urutan & komposisi dari config. --}}
    @foreach ($widgets as $widget)
        <x-dynamic-component :component="$widget['view']" :widget="$widget" :data="$widget['data'] ?? null" />
    @endforeach
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

                    if (!response.ok) {
                        // Session expired or unauthorized — redirect to login.
                        if (response.status === 401 || response.status === 419) {
                            window.location.href = "{{ route('login') }}";
                            return;
                        }
                        throw new Error(`HTTP ${response.status}`);
                    }

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
                    yAxis: { type: 'value', min: payload.y_axis_min ?? 0 },
                    series: [...barSeries, ...lineSeries],
                });

                // Resize chart otomatis kalau ukuran containernya berubah 
                if (this.resizeObserver) {
                    this.resizeObserver.disconnect();
                }
                this.resizeObserver = new ResizeObserver(() => {
                    this.chart && this.chart.resize();
                });
                this.resizeObserver.observe(el);
            },
        };
    }
</script>
@endsection
