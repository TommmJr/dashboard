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

@section('title')
    {{ auth()->user()?->role?->slug === \App\Models\Role::ADMIN ? 'Dashboard Admin' : 'Dashboard' }}
@endsection

@php
    $isAdminDashboard = auth()->user()?->role?->slug === \App\Models\Role::ADMIN;
    $needsPerformanceState = ! $isAdminDashboard || collect($widgets)->contains(fn (array $w): bool => in_array($w['key'] ?? '', ['kpi-summary', 'trend-chart'], true));
@endphp

@section('content')
<div class="mx-auto max-w-7xl" @if ($needsPerformanceState) x-data="performanceDashboard(@js($filters ?? []))" x-init="load()" @endif>
    @if ($isAdminDashboard)
        <div class="mb-6">
            <p class="text-xs font-medium text-slate-500">Beranda / Dashboard Admin</p>
            <h1 class="mt-2 text-2xl font-bold text-primary">Dashboard Admin</h1>
            <p class="mt-1 text-sm text-slate-500">Ringkasan operasional data dan aktivitas sistem</p>
        </div>
    @else
        <div class="mb-6">
            <p class="text-xs font-medium text-slate-500">Beranda / Dashboard</p>
            <h1 class="mt-2 text-2xl font-bold text-primary">Dashboard</h1>
            <p class="mt-1 text-sm text-slate-500">Ringkasan kinerja dan dataset untuk divisi Anda.</p>
        </div>
    @endif

    @if (! $isAdminDashboard)
        {{-- Form GET (bukan fetch): halaman dimuat ulang sehingga widget
             SERVER-SIDE (peringkat NKO) dan CLIENT-SIDE (chart) memakai rentang
             filter yang sama. Tanpa reload, peringkat tidak akan ikut terfilter. --}}
        <form method="GET" action="{{ route('dashboard') }}" class="mt-6 grid gap-4 border border-slate-200 bg-white p-4 shadow sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1.25fr_auto_auto] lg:items-end">
        <div class="flex flex-col gap-1.5">
            <label for="start_date" class="text-xs font-semibold uppercase tracking-wide text-gray-500">Dari tanggal</label>
            <input id="start_date" name="start_date" type="date" value="{{ $filters['start_date'] ?? '' }}" class="h-10 rounded border border-slate-200 bg-white px-3 text-sm text-primary outline-none focus:border-accent focus:ring-2 focus:ring-accent/20">
        </div>
        <div class="flex flex-col gap-1.5">
            <label for="end_date" class="text-xs font-semibold uppercase tracking-wide text-gray-500">Sampai tanggal</label>
            <input id="end_date" name="end_date" type="date" value="{{ $filters['end_date'] ?? '' }}" class="h-10 rounded border border-slate-200 bg-white px-3 text-sm text-primary outline-none focus:border-accent focus:ring-2 focus:ring-accent/20">
        </div>
        <div class="flex flex-col gap-1.5">
            <label for="unit" class="text-xs font-semibold uppercase tracking-wide text-gray-500">Unit</label>
            <details class="group relative">
                <summary id="unit" class="flex h-10 w-full cursor-pointer list-none items-center justify-between gap-3 rounded border border-slate-200 bg-white px-3 text-left text-sm text-primary outline-none transition hover:border-accent focus:border-accent focus:ring-2 focus:ring-accent/20">
                    <span x-text="selectedUnitLabel()">{{ count($filters['units'] ?? []) > 0 ? count($filters['units']).' unit dipilih' : 'Semua unit' }}</span>
                    <svg class="h-4 w-4 shrink-0 text-gray-500 transition group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round" /></svg>
                </summary>
                <div class="absolute left-0 right-0 z-20 mt-2 max-h-60 overflow-y-auto rounded border border-slate-200 bg-white p-2 shadow-lg" role="group" aria-label="Pilihan unit">
                    @forelse ($availableUnits ?? [] as $unitName)
                        <label class="flex cursor-pointer items-center gap-2 rounded px-2 py-2 text-sm text-primary hover:bg-surface">
                            <input type="checkbox" name="unit[]" value="{{ $unitName }}" @checked(in_array($unitName, $filters['units'] ?? [], true)) class="h-4 w-4 rounded border-slate-200 text-accent focus:ring-accent/30">
                            <span>{{ $unitName }}</span>
                        </label>
                    @empty
                        <p class="px-2 py-2 text-sm text-gray-500">Belum ada unit.</p>
                    @endforelse
                </div>
            </details>
        </div>
        <button type="submit" class="inline-flex h-10 items-center justify-center gap-2 rounded bg-primary px-4 text-sm font-semibold text-white transition hover:bg-accent disabled:cursor-not-allowed disabled:opacity-60">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 4h18M6 12h12M10 20h4" stroke-linecap="round" /></svg>
            Terapkan
        </button>
        <a href="{{ route('dashboard') }}" class="inline-flex h-10 items-center justify-center gap-2 rounded border border-slate-200 px-4 text-sm font-semibold text-primary transition hover:border-accent hover:text-accent">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7M3 4v5h5" stroke-linecap="round" stroke-linejoin="round" /></svg>
            Reset
        </a>
        </form>
        <p x-show="error" x-cloak class="mt-2 text-sm font-medium text-red-700" x-text="error" role="alert"></p>
    @endif

    {{-- Widget per role: satu loop generik, urutan & komposisi dari config. --}}
    <div class="grid gap-6 lg:grid-cols-3">
        @foreach ($widgets as $widget)
            @php
                $widgetClass = match ((int) ($widget['cols'] ?? 3)) {
                    1 => 'lg:col-span-1',
                    2 => 'lg:col-span-2',
                    default => 'lg:col-span-3',
                };
            @endphp
            <div class="{{ $widgetClass }}">
                <x-dynamic-component :component="$widget['view']" :widget="$widget" :data="$widget['data'] ?? null" />
            </div>
        @endforeach
    </div>
</div>

<script>
    function performanceDashboard(initial = {}) {
        return {
            loading: true,
            empty: false,
            kpi: null,
            chart: null,
            error: '',
            units: [],
            periodLabels: [],
            chartPeriodCount: 0,
            chartUnitCount: 0,
            filters: {
                start_date: initial.start_date ?? '',
                end_date: initial.end_date ?? '',
                units: initial.units ?? [],
            },

            formatNumber(value) {
                return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(value);
            },

            // ponytail: subjudul mengikuti jumlah periode yang BENAR-BENAR ada.
            // 1 periode = snapshot (bukan tren); >1 periode = tren. Batas ini
            // cukup sampai agregasi bulanan riil tersedia. Unit tanpa data di
            // periode yang tampil TIDAK dihitung sebagai unit di snapshot.
            chartSubtitle() {
                if (this.loading || this.empty) {
                    return '';
                }

                if (this.chartPeriodCount <= 1) {
                    return `Snapshot ${this.chartUnitCount} unit — ${this.periodLabels[0] ?? ''}`;
                }

                return `Tren ${this.chartPeriodCount} periode — ${this.periodLabels[0]} s.d. ${this.periodLabels[this.periodLabels.length - 1]}`;
            },

            hasFilters() {
                return Object.values(this.filters).some((value) => Array.isArray(value) ? value.length > 0 : value !== '');
            },

            selectedUnitLabel() {
                if (this.filters.units.length === 0) {
                    return 'Semua unit';
                }

                return `${this.filters.units.length} unit dipilih`;
            },

            async applyFilters() {
                if (this.filters.start_date !== '' && this.filters.end_date !== '' && this.filters.start_date > this.filters.end_date) {
                    this.error = 'Tanggal akhir harus sama atau setelah tanggal mulai.';
                    return;
                }

                await this.load();
            },

            async resetFilters() {
                this.filters = { start_date: '', end_date: '', units: [] };
                await this.load();
            },

            async load() {
                this.loading = true;
                this.error = '';

                try {
                    const url = new URL("{{ route('performance.data') }}", window.location.origin);
                    Object.entries(this.filters).forEach(([key, value]) => {
                        if (Array.isArray(value)) {
                            value.forEach((item) => url.searchParams.append('unit[]', item));
                        } else if (value !== '') {
                            url.searchParams.set(key, value);
                        }
                    });

                    const response = await fetch(url, {
                        headers: { 'Accept': 'application/json' },
                    });

                    if (!response.ok) {
                        // Session expired or unauthorized — redirect to login.
                        if (response.status === 401 || response.status === 419) {
                            window.location.href = "{{ route('login') }}";
                            return;
                        }
                        if (response.status === 422) {
                            this.error = 'Filter tanggal atau unit tidak valid.';
                            this.empty = true;
                            return;
                        }
                        throw new Error(`HTTP ${response.status}`);
                    }

                    const payload = await response.json();

                    if (!this.hasFilters()) {
                        this.units = payload.series.map((series) => series.name);
                    }
                    this.kpi = payload.kpi;
                    this.empty = payload.labels.length === 0;
                    this.periodLabels = payload.labels;
                    this.chartPeriodCount = payload.labels.length;
                    this.chartUnitCount = payload.series.length;

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
                const el = document.getElementById('performanceChart');
                if (!el) return;
                if (this.chart) { this.chart.dispose(); }
                this.chart = window.echarts.init(el);

                // Dua bentuk, dipilih oleh jumlah periode:
                // - 1 periode  = bar per unit, SATU warna, diurutkan tertinggi,
                //   sumbu Y dari 0. Snapshot dengan nilai hampir seragam jadi
                //   terbaca jujur (tanpa legend 15 unit & tanpa bar terpotong).
                // - >1 periode = garis per unit (tren), warna dari palet.
                if (payload.labels.length <= 1) {
                    const ranked = payload.series
                        .filter((s) => s.data[0] !== null && s.data[0] !== undefined)
                        .map((s) => ({ name: s.name, value: Number(s.data[0]) }))
                        .sort((a, b) => b.value - a.value);

                    this.chart.setOption({
                        tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
                        grid: { left: 70, right: 20, top: 20, bottom: 40 },
                        xAxis: {
                            type: 'category',
                            data: ranked.map((row) => row.name),
                            axisLabel: { interval: 0, rotate: ranked.length > 8 ? 45 : 0 },
                        },
                        yAxis: { type: 'value', min: 0 },
                        series: [{
                            type: 'bar',
                            data: ranked.map((row) => row.value),
                            itemStyle: { color: '#16a3b8', borderRadius: [4, 4, 0, 0] },
                            label: {
                                show: true,
                                position: 'top',
                                color: '#0b6175',
                                fontSize: 11,
                                formatter: (params) => Number(params.value).toFixed(1),
                            },
                        }],
                    });
                } else {
                    const palette = ['#16a3b8', '#0b6175', '#f1dc3a', '#d8e3e7'];
                    const lineSeries = payload.series.map((s, i) => ({
                        name: s.name,
                        type: 'line',
                        smooth: true,
                        data: s.data,
                        itemStyle: { color: palette[i % palette.length] },
                    }));

                    this.chart.setOption({
                        tooltip: { trigger: 'axis' },
                        legend: { bottom: 0, type: 'scroll' },
                        grid: { left: 60, right: 20, top: 20, bottom: 40 },
                        xAxis: { type: 'category', data: payload.labels },
                        yAxis: { type: 'value', min: payload.y_axis_min ?? 0 },
                        series: lineSeries,
                    });
                }

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
