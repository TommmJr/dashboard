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
    $isStaffDashboard = auth()->user()?->role?->slug === \App\Models\Role::STAFF_DIVISI;
    $needsPerformanceState = ! $isAdminDashboard || collect($widgets)->contains(fn (array $w): bool => in_array($w['key'] ?? '', ['kpi-summary', 'trend-chart'], true));
@endphp

@section('content')
<div class="mx-auto max-w-7xl" @if ($needsPerformanceState) x-data="performanceDashboard(@js($filters ?? []))" x-init="load()" @endif>
    @if ($isStaffDashboard)
        <div id="dashboard-header" class="mb-4 flex flex-col gap-3 rounded-lg border border-slate-200/80 bg-white px-4 py-3 shadow-[0_1px_2px_0_rgba(0,0,0,0.03)] sm:flex-row sm:items-center sm:justify-between">
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-2">
                    <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-teal-500/20 bg-teal-50 text-primary">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M3 3v18h18" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="m19 9-5 5-4-4-3 3" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <h1 class="text-base font-bold tracking-tight text-primary">Dashboard Kinerja Divisi</h1>
                </div>
                <div class="flex items-center gap-2 text-xs">
                    <span class="inline-flex items-center rounded border border-teal-200/80 bg-teal-50 px-2 py-0.5 font-semibold text-teal-800">
                        {{ auth()->user()?->division?->name ?? 'Divisi' }}
                    </span>
                    <span class="text-slate-300" aria-hidden="true">&bull;</span>
                    <span class="inline-flex items-center gap-1 text-slate-500">
                        <span>Cakupan Unit:</span>
                        <strong class="font-semibold text-slate-800">{{ !empty(config('kpi.visible_units')) ? implode(', ', config('kpi.visible_units')) : 'Semua Unit' }}</strong>
                    </span>
                </div>
            </div>

            @can('dataset.import')
                <div class="flex shrink-0 items-center">
                    <a href="{{ route('datasets.create') }}" class="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-white shadow-2xs transition hover:bg-primary/90 focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M12 5v14M5 12h14" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        Unggah Dataset
                    </a>
                </div>
            @endcan
        </div>
    @else
        <div id="dashboard-header" class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-primary">
                    {{ $isAdminDashboard ? 'Dashboard Admin' : 'Dashboard' }}
                </h1>
            </div>

            @if (! $isAdminDashboard)
                {{-- Form GET (bukan fetch): halaman dimuat ulang sehingga widget
                     SERVER-SIDE (peringkat NKO) dan CLIENT-SIDE (chart) memakai rentang
                     filter yang sama. Tanpa reload, peringkat tidak akan ikut terfilter. --}}
                <form id="dashboard-filter" method="GET" action="{{ route('dashboard') }}" class="flex flex-wrap items-center gap-2">
                    <div class="flex items-center gap-1.5">
                        <label for="start_date" class="sr-only">Dari tanggal</label>
                        <input id="start_date" name="start_date" type="date" value="{{ $filters['start_date'] ?? '' }}" class="h-9 rounded border border-slate-200 bg-white px-2.5 text-sm text-primary outline-none focus:border-accent focus:ring-2 focus:ring-accent/20">
                        <span class="text-xs text-slate-400" aria-hidden="true">&ndash;</span>
                        <label for="end_date" class="sr-only">Sampai tanggal</label>
                        <input id="end_date" name="end_date" type="date" value="{{ $filters['end_date'] ?? '' }}" class="h-9 rounded border border-slate-200 bg-white px-2.5 text-sm text-primary outline-none focus:border-accent focus:ring-2 focus:ring-accent/20">
                    </div>

                    <div class="relative">
                        <label for="unit" class="sr-only">Unit</label>
                        <details class="group relative">
                            <summary id="unit" class="flex h-9 min-w-[130px] cursor-pointer list-none items-center justify-between gap-2 rounded border border-slate-200 bg-white px-2.5 text-left text-sm text-primary outline-none transition hover:border-accent focus:border-accent focus:ring-2 focus:ring-accent/20">
                                <span class="truncate" x-text="selectedUnitLabel()">{{ count($filters['units'] ?? []) > 0 ? count($filters['units']).' unit dipilih' : 'Semua unit' }}</span>
                                <svg class="h-4 w-4 shrink-0 text-slate-400 transition group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            </summary>
                            <div class="absolute right-0 z-30 mt-1 max-h-60 w-56 overflow-y-auto rounded border border-slate-200 bg-white p-2 shadow-lg" role="group" aria-label="Pilihan unit">
                                @forelse ($availableUnits ?? [] as $unitName)
                                    <label class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm text-primary hover:bg-surface">
                                        <input type="checkbox" name="unit[]" value="{{ $unitName }}" @checked(in_array($unitName, $filters['units'] ?? [], true)) class="h-4 w-4 rounded border-slate-200 text-accent focus:ring-accent/30">
                                        <span class="truncate">{{ $unitName }}</span>
                                    </label>
                                @empty
                                    <p class="px-2 py-1.5 text-sm text-slate-500">Belum ada unit.</p>
                                @endforelse
                            </div>
                        </details>
                    </div>

                    <div class="flex items-center gap-1.5">
                        <button type="submit"
                                title="Terapkan Filter"
                                aria-label="Terapkan"
                                class="inline-flex h-9 w-9 items-center justify-center rounded bg-accent text-white transition hover:bg-primary focus:outline-none focus:ring-2 focus:ring-accent/30 disabled:cursor-not-allowed disabled:opacity-60">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 4h18M6 12h12M10 20h4" stroke-linecap="round" /></svg>
                            <span class="sr-only">Terapkan</span>
                        </button>
                        <a href="{{ route('dashboard') }}"
                           title="Reset Filter"
                           aria-label="Reset"
                           class="inline-flex h-9 w-9 items-center justify-center rounded border border-slate-200 bg-white text-slate-600 transition hover:border-accent hover:text-accent focus:outline-none focus:ring-2 focus:ring-accent/30">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7M3 4v5h5" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            <span class="sr-only">Reset</span>
                        </a>
                    </div>
                </form>
            @endif
        </div>
    @endif

    @if (! $isAdminDashboard && ! $isStaffDashboard)
        <p x-show="error" x-cloak class="-mt-4 mb-4 text-sm font-medium text-red-700" x-text="error" role="alert"></p>
    @endif

    {{-- Widget per role: satu loop generik, urutan & komposisi dari config. --}}
    <div class="grid grid-flow-dense gap-4 sm:gap-5 lg:grid-cols-3">
        @if ($isStaffDashboard && count($widgets) === 1 && ($widgets[0]['key'] ?? '') === 'dataset-status')
            <div class="h-full rounded-lg border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-teal-100 bg-teal-50 text-teal-700">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5m-4 0h4" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-lg font-bold text-primary">Ruang Kerja Divisi {{ auth()->user()?->division?->name ?? '' }}</h2>
                        <p class="text-xs text-slate-500">Pusat kendali operasional integrasi dan pengelolaan data divisi.</p>
                    </div>
                </div>

                <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="rounded-lg border border-slate-100 bg-slate-50/60 p-4">
                        <p class="text-xs font-semibold text-slate-700">Manajemen Dataset</p>
                        <p class="mt-1 text-xs text-slate-500">Kelola dan pantau dataset berkala milik divisi Anda, termasuk riwayat unggah dan status verifikasi.</p>
                        <a href="{{ route('datasets.index') }}" class="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-accent transition hover:text-primary">
                            Lihat Arsip Dataset &rarr;
                        </a>
                    </div>
                    <div class="rounded-lg border border-slate-100 bg-slate-50/60 p-4">
                        <p class="text-xs font-semibold text-slate-700">Unggah Data Baru</p>
                        <p class="mt-1 text-xs text-slate-500">Kirimkan berkas dataset operasional terbaru untuk diproses secara terstruktur ke dalam sistem.</p>
                        @can('dataset.import')
                            <a href="{{ route('datasets.create') }}" class="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-accent transition hover:text-primary">
                                Unggah Dataset Baru &rarr;
                            </a>
                        @else
                            <span class="mt-3 inline-flex text-xs text-slate-400">Hubungi Admin untuk izin impor</span>
                        @endcan
                    </div>
                </div>

                <div class="mt-4 rounded-lg border border-blue-100 bg-blue-50/40 p-3.5 text-xs text-slate-600">
                    <p class="flex items-center gap-2">
                        <svg class="h-4 w-4 shrink-0 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M12 16v-4M12 8h.01"/>
                        </svg>
                        <span>Widget visualisasi analitik khusus untuk divisi ini sedang dalam penyiapan data terstruktur oleh tim pengelola.</span>
                    </p>
                </div>
            </div>
        @endif

        @foreach ($widgets as $widget)
            @php
                $isSingleStaffWidget = $isStaffDashboard && count($widgets) === 1 && ($widget['key'] ?? '') === 'dataset-status';
                $hasKpiStaffLayout = $isStaffDashboard && collect($widgets)->contains(fn (array $w): bool => in_array($w['key'] ?? '', ['kpi-summary', 'kinerja-detail', 'nko-ranking'], true));
                $widgetClass = match (true) {
                    $isSingleStaffWidget => 'lg:col-span-1',
                    $isStaffDashboard && ($widget['key'] ?? '') === 'nko-ranking' => 'lg:col-span-2',
                    $hasKpiStaffLayout && ($widget['key'] ?? '') === 'dataset-status' => 'lg:col-span-1',
                    default => match ((int) ($widget['cols'] ?? 3)) {
                        1 => 'lg:col-span-1',
                        2 => 'lg:col-span-2',
                        default => 'lg:col-span-3',
                    },
                };
            @endphp
            <div class="{{ $widgetClass }} h-full">
                @if (($widget['key'] ?? '') === 'aset-upt-chart')
                    @include('components.widgets.aset-upt-chart', ['widget' => $widget, 'data' => $widget['data'] ?? $widgetData['aset-upt-chart'] ?? null])
                @else
                    <x-dynamic-component :component="$widget['view']" :widget="$widget" :data="$widget['data'] ?? null" />
                @endif
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
                    return `Snapshot ${this.chartUnitCount} unit — ${this.periodLabels[0] ?? ''} · Tren muncul setelah 2+ bulan`;
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
