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
<div class="mx-auto max-w-7xl min-w-0" @if ($needsPerformanceState) x-data="performanceDashboard(@js($filters ?? []))" x-init="load()" @endif>
    @if ($isStaffDashboard)
        <div id="dashboard-header" class="mb-4 flex min-w-0 flex-col gap-3 rounded-lg border border-slate-200/80 bg-white px-4 py-3 shadow-[0_1px_2px_0_rgba(0,0,0,0.03)] sm:flex-row sm:items-center sm:justify-between">
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-2">
                    <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-teal-500/20 bg-teal-50 text-primary">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M3 3v18h18" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="m19 9-5 5-4-4-3 3" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <h1 class="text-base font-bold tracking-tight text-primary">Dashboard Divisi</h1>
                </div>
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="inline-flex items-center rounded border border-teal-200/80 bg-teal-50 px-2 py-0.5 font-semibold text-teal-800">
                        {{ auth()->user()?->division?->name ?? 'Divisi' }}
                    </span>
                    <span class="text-slate-300" aria-hidden="true">&bull;</span>
                    @if (in_array(auth()->user()?->division?->slug, ['managemen-aset', 'data-aset'], true))
                        <span class="inline-flex items-center gap-1.5 text-slate-500">
                            <strong class="font-semibold text-slate-800">UIT JBT</strong>
                            <span class="text-slate-300" aria-hidden="true">&bull;</span>
                            <span class="font-medium text-slate-600">8 UPT</span>
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 text-slate-500">
                            <strong class="font-semibold text-slate-800">{{ !empty(config('kpi.visible_units')) ? implode(', ', config('kpi.visible_units')) : 'Semua Unit' }}</strong>
                        </span>
                    @endif
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
        <div id="dashboard-header" class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between animate-entrance">
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight text-primary">
                    {{ $isAdminDashboard ? 'Dashboard Admin' : 'Dashboard' }}
                </h1>
            </div>

            @if ($isAdminDashboard)
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('datasets.index') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200/80 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-2xs transition hover:border-slate-300 hover:bg-slate-50">
                        <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M4 7c0-1.1 3.6-2 8-2s8 .9 8 2-3.6 2-8 2-8-.9-8-2Z"/>
                            <path d="M4 7v10c0 1.1 3.6 2 8 2s8-.9 8-2V7"/>
                            <path d="M4 12c0 1.1 3.6 2 8 2s8-.9 8-2"/>
                        </svg>
                        Dataset
                    </a>
                    <a href="{{ route('audit-logs.index') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200/80 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-2xs transition hover:border-slate-300 hover:bg-slate-50">
                        <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M4 12a8 8 0 1 0 2.3-5.7M4 4v5h5"/>
                            <path d="M12 8v4l2.5 1.5"/>
                        </svg>
                        Audit Log
                    </a>
                    @can('dashboard.update')
                        <a href="{{ route('dashboard.builder') }}" class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white shadow-2xs transition hover:bg-primary/90">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.4-9.4a2 2 0 1 1 2.8 2.8L11.8 15H9v-2.8l8.6-8.6Z"/>
                            </svg>
                            Atur Layout
                        </a>
                    @endcan
                </div>
            @endif

            @if (! $isAdminDashboard)
                {{-- Form GET (bukan fetch): halaman dimuat ulang sehingga widget
                     SERVER-SIDE (peringkat NKO) dan CLIENT-SIDE (chart) memakai rentang
                     filter yang sama. Tanpa reload, peringkat tidak akan ikut terfilter. --}}
                <form id="dashboard-filter" method="GET" action="{{ route('dashboard') }}" class="flex min-w-0 max-w-full flex-wrap items-center gap-2">
                    <div class="flex min-w-0 max-w-full flex-wrap items-center gap-1.5">
                        <label for="start_date" class="sr-only">Dari tanggal</label>
                        <input id="start_date" name="start_date" type="date" value="{{ $filters['start_date'] ?? '' }}" class="h-9 min-w-0 max-w-full rounded border border-slate-200 bg-white px-2.5 text-sm text-primary outline-none focus:border-accent focus:ring-2 focus:ring-accent/20">
                        <span class="text-xs font-medium text-slate-400" aria-hidden="true">s.d.</span>
                        <label for="end_date" class="sr-only">Sampai tanggal</label>
                        <input id="end_date" name="end_date" type="date" value="{{ $filters['end_date'] ?? '' }}" class="h-9 min-w-0 max-w-full rounded border border-slate-200 bg-white px-2.5 text-sm text-primary outline-none focus:border-accent focus:ring-2 focus:ring-accent/20">
                    </div>

                    <div class="relative min-w-0 max-w-full">
                        <label for="unit" class="sr-only">Unit</label>
                        <details class="group relative">
                            <summary id="unit" class="flex h-9 min-w-[130px] max-w-full cursor-pointer list-none items-center justify-between gap-2 rounded border border-slate-200 bg-white px-2.5 text-left text-sm text-primary outline-none transition hover:border-accent focus:border-accent focus:ring-2 focus:ring-accent/20">
                                <span class="truncate" x-text="selectedUnitLabel()">{{ count($filters['units'] ?? []) > 0 ? count($filters['units']).' unit dipilih' : 'Semua unit' }}</span>
                                <svg class="h-4 w-4 shrink-0 text-slate-400 transition group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            </summary>
                            <div class="absolute left-0 z-30 mt-1 max-h-60 w-56 max-w-[calc(100vw-5rem)] overflow-y-auto rounded border border-slate-200 bg-white p-2 shadow-lg sm:left-auto sm:right-0" role="group" aria-label="Pilihan unit">
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
    <div class="grid grid-cols-1 grid-flow-dense gap-4 sm:gap-5 lg:grid-cols-3">
        @if ($isStaffDashboard && count($widgets) === 1 && ($widgets[0]['key'] ?? '') === 'dataset-status')
            <div class="h-full min-w-0 max-w-full rounded-lg border border-slate-200 bg-white p-5 shadow-2xs lg:col-span-2">
                <div class="flex items-center gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-primary">
                        <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5m-4 0h4" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <h2 class="text-base font-bold text-primary">Ruang Kerja Divisi {{ auth()->user()?->division?->name ?? '' }}</h2>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="rounded-lg border border-slate-200/80 bg-slate-50/50 p-3.5">
                        <p class="text-xs font-semibold text-slate-800">Manajemen Dataset</p>
                        <p class="mt-1 text-xs text-slate-500">Riwayat unggah dan status verifikasi.</p>
                        <a href="{{ route('datasets.index') }}" class="mt-2.5 inline-flex items-center gap-1 text-xs font-semibold text-accent transition hover:text-primary">
                            Lihat Arsip Dataset &rarr;
                        </a>
                    </div>
                    <div class="rounded-lg border border-slate-200/80 bg-slate-50/50 p-3.5">
                        <p class="text-xs font-semibold text-slate-800">Unggah Data Baru</p>
                        <p class="mt-1 text-xs text-slate-500">Impor berkas dataset operasional.</p>
                        @can('dataset.import')
                            <a href="{{ route('datasets.create') }}" class="mt-2.5 inline-flex items-center gap-1 text-xs font-semibold text-accent transition hover:text-primary">
                                Unggah Dataset Baru &rarr;
                            </a>
                        @else
                            <span class="mt-2.5 inline-flex text-xs text-slate-400">Hubungi Admin untuk izin impor</span>
                        @endcan
                    </div>
                </div>

                <div class="mt-3.5 flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-xs text-slate-600">
                    <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-slate-400" aria-hidden="true"></span>
                    <span>Visualisasi analitik divisi belum tersedia.</span>
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
                $staggerIndex = (($loop->iteration - 1) % 6) + 1;
            @endphp
            <div class="{{ $widgetClass }} h-full min-w-0 max-w-full animate-entrance stagger-{{ $staggerIndex }}">
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
    let performanceChartInstance = null;

    function performanceDashboard(initial = {}) {
        return {
            loading: true,
            empty: false,
            kpi: null,
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
                    return `Snapshot ${this.chartUnitCount} unit (${this.periodLabels[0] ?? ''}) · Tren muncul setelah 2+ bulan`;
                }

                return `Tren ${this.chartPeriodCount} periode (${this.periodLabels[0]} s.d. ${this.periodLabels[this.periodLabels.length - 1]})`;
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
                    this.error = 'Gagal memuat data kinerja.';
                    this.empty = true;
                } finally {
                    this.loading = false;
                }
            },

            renderChart(payload) {
                const el = document.getElementById('performanceChart');
                if (!el) return;
                if (performanceChartInstance) { performanceChartInstance.dispose(); }
                performanceChartInstance = window.echarts.init(el);

                const tokens = window.PLN_CHART_PALETTE || {
                    primary: '#0b6175',
                    accent: '#16a3b8',
                    softTeal: '#bce3e8',
                    targetSlate: '#cbd5e1',
                    gridLine: '#f1f5f9',
                    axisText: '#64748b',
                    rag: { tercapai: '#10b981', waspada: '#f59e0b', kritis: '#f43f5e' },
                };

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

                    performanceChartInstance.setOption({
                        animation: true,
                        animationDuration: 650,
                        animationEasing: 'cubicOut',
                        animationDurationUpdate: 400,
                        tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
                        grid: { left: 70, right: 20, top: 20, bottom: 40 },
                        xAxis: {
                            type: 'category',
                            data: ranked.map((row) => row.name),
                            axisLine: { lineStyle: { color: tokens.gridLine } },
                            axisLabel: {
                                interval: 0,
                                rotate: ranked.length > 8 ? 45 : 0,
                                color: tokens.axisText,
                                fontSize: 11,
                            },
                        },
                        yAxis: {
                            type: 'value',
                            min: 0,
                            axisLabel: { color: tokens.axisText, fontSize: 11 },
                            splitLine: { lineStyle: { color: tokens.gridLine } },
                        },
                        series: [{
                            type: 'bar',
                            data: ranked.map((row) => row.value),
                            itemStyle: { color: tokens.accent, borderRadius: [6, 6, 0, 0] },
                            label: {
                                show: true,
                                position: 'top',
                                color: tokens.primary,
                                fontSize: 11,
                                formatter: (params) => Number(params.value).toFixed(1),
                            },
                        }],
                    });
                } else {
                    const palette = [tokens.primary, tokens.accent, tokens.softTeal, tokens.targetSlate];
                    const lineSeries = payload.series.map((s, i) => ({
                        name: s.name,
                        type: 'line',
                        smooth: true,
                        data: s.data,
                        itemStyle: { color: palette[i % palette.length] },
                    }));

                    performanceChartInstance.setOption({
                        animation: true,
                        animationDuration: 650,
                        animationEasing: 'cubicOut',
                        animationDurationUpdate: 400,
                        tooltip: { trigger: 'axis' },
                        legend: {
                            bottom: 0,
                            type: 'scroll',
                            textStyle: { color: tokens.axisText, fontSize: 11 },
                        },
                        grid: { left: 60, right: 20, top: 20, bottom: 40 },
                        xAxis: {
                            type: 'category',
                            data: payload.labels,
                            axisLine: { lineStyle: { color: tokens.gridLine } },
                            axisLabel: { color: tokens.axisText, fontSize: 11 },
                        },
                        yAxis: {
                            type: 'value',
                            min: payload.y_axis_min ?? 0,
                            axisLabel: { color: tokens.axisText, fontSize: 11 },
                            splitLine: { lineStyle: { color: tokens.gridLine } },
                        },
                        series: lineSeries,
                    });
                }

                // Resize chart otomatis kalau ukuran containernya berubah 
                if (this.resizeObserver) {
                    this.resizeObserver.disconnect();
                }
                this.resizeObserver = new ResizeObserver(() => {
                    performanceChartInstance && performanceChartInstance.resize();
                });
                this.resizeObserver.observe(el);
            },
        };
    }
</script>
@endsection
