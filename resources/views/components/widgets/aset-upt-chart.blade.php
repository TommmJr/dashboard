@props(['widget' => [], 'data' => null])

@php
    $currentUser = auth()->user();
    // Guard otorisasi fail-closed: jika user login dan bukan Admin/Privileged
    // serta bukan staf divisi Managemen Aset (managemen-aset / data-aset),
    // kartu tidak dirender sama sekali (return void / empty string).
    $canViewAsetWidget = $currentUser === null
        || $currentUser->hasRole(\App\Models\Role::ADMIN)
        || $currentUser->hasRole(\App\Models\Role::MANAGEMENT)
        || $currentUser->hasRole(\App\Models\Role::EDITOR_DASHBOARD)
        || ($currentUser->hasRole(\App\Models\Role::STAFF_DIVISI) && in_array($currentUser->division?->slug, ['managemen-aset', 'data-aset'], true));
@endphp

@if ($canViewAsetWidget)
{{-- Widget grafik stok aset per UPT (server-side, snapshot periode).
     Data: DashboardController::asetUptChart() — labels UPT, series bar per atribut,
     scorecard ringkasan (Tower, Transmisi, Trafo), dan tabel rekapitulasi 8 UPT.
     Sejak 2026-11-11 widget dipasang HANYA di layout default staf Managemen Aset
     sebagai demo prototipe (instruksi user, bukan sign-off mentor/PIC). --}}
@php
    $labels = collect($data['labels'] ?? []);
    $series = collect($data['series'] ?? []);
    $period = $data['period'] ?? null;
    $periodUit = $data['period_uit'] ?? $period;
    $periodUpt = $data['period_upt'] ?? $period;
    $summary = $data['summary'] ?? [];
    $tableRows = $data['table_rows'] ?? [];
    $seriesByCategory = $data['series_by_category'] ?? [];

    $chartSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($widget['key'] ?? 'aset-upt-chart'));
    $chartId = 'asetUptChart-'.$chartSlug.'-'.($widget['instance'] ?? 0);
    $donutId = 'asetUptDonut-'.$chartSlug.'-'.($widget['instance'] ?? 0);
    $palette = ['#14a2ba', '#125d72', '#efe62f', '#d9d9d9', '#0b7a75', '#f39233', '#7a5195', '#ef5675', '#2f9e44', '#1971c2'];

    $formatPeriodLabel = function (?string $rawDate): string {
        if (! $rawDate) {
            return '-';
        }
        try {
            $months = [
                1 => 'JAN', 2 => 'FEB', 3 => 'MAR', 4 => 'APR', 5 => 'MEI', 6 => 'JUN',
                7 => 'JUL', 8 => 'AGU', 9 => 'SEP', 10 => 'OKT', 11 => 'NOV', 12 => 'DES',
            ];
            $date = \Carbon\Carbon::parse($rawDate);
            $month = $months[$date->month] ?? strtoupper($date->format('M'));
            return 's.d. '.$month.' '.$date->year;
        } catch (\Throwable $e) {
            return $rawDate;
        }
    };

    // Map series to format expected by ECharts bar chart with palette colors
    $formatSeries = function (iterable $items) use ($palette): array {
        return collect($items)->map(fn ($item, $index) => [
            'name' => $item['name'],
            'type' => 'bar',
            'data' => $item['data'],
            'itemStyle' => ['color' => $palette[$index % count($palette)]],
        ])->values()->all();
    };

    // Filter categories with fallback to string matching if backend array is not pre-split
    $towerRaw = collect(!empty($seriesByCategory['tower'])
        ? $seriesByCategory['tower']
        : $series->filter(fn ($s) => str_starts_with($s['name'] ?? '', 'Tower '))->values());
    $transmisiRaw = collect(!empty($seriesByCategory['transmisi'])
        ? $seriesByCategory['transmisi']
        : $series->filter(fn ($s) => str_starts_with($s['name'] ?? '', 'Transmisi '))->values());
    $trafoRaw = collect(!empty($seriesByCategory['trafo'])
        ? $seriesByCategory['trafo']
        : $series->filter(fn ($s) => str_starts_with($s['name'] ?? '', 'Trafo '))->values());

    $chartSeriesTower = $formatSeries($towerRaw);
    $chartSeriesTransmisi = $formatSeries($transmisiRaw);
    $trafoMvaRaw = $trafoRaw->filter(fn ($s) => str_contains($s['name'] ?? '', 'MVA'))->values();
    $chartSeriesTrafo = $formatSeries($trafoMvaRaw->isNotEmpty() ? $trafoMvaRaw : $trafoRaw);
    $chartSeriesAll = $formatSeries($series);

    // Fallback calculation for summary if not supplied in data
    $totalTower = $summary['total_tower'] ?? $towerRaw->sum(fn ($s) => array_sum($s['data'] ?? []));
    $totalTransmisi = $summary['total_transmisi'] ?? $transmisiRaw->sum(fn ($s) => array_sum($s['data'] ?? []));
    $totalTrafo = $summary['total_trafo'] ?? ($trafoMvaRaw->isNotEmpty() ? $trafoMvaRaw->sum(fn ($s) => array_sum($s['data'] ?? [])) : $trafoRaw->sum(fn ($s) => array_sum($s['data'] ?? [])));
@endphp

<div
    class="mt-8 rounded-lg bg-white border border-slate-200 p-6 shadow-sm"
    @if (!$labels->isEmpty() && !$series->isEmpty())
        x-data='asetUptChartWidget(@json($chartId))'
        x-init="init()"
    @endif
>
    {{-- Header Widget: Judul, Badge, Subtitle & Tombol Filter Kategori --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-b border-slate-100 pb-4">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="text-lg font-bold text-primary">{{ $widget['label'] ?? 'Stok Aset per UPT' }}</h3>
                <span class="inline-flex items-center rounded-full bg-slate-100 border border-slate-200 px-2.5 py-0.5 text-xs font-semibold text-slate-700">
                    Prototipe Demo (Rekapitulasi UPT)
                </span>
                @if (\App\Http\Controllers\AssetDetailController::canAccessAssetDetail(auth()->user()))
                    <a
                        href="{{ route('dashboard.asset-detail') }}"
                        class="inline-flex items-center gap-1.5 rounded-md border border-primary/20 bg-primary/5 px-2.5 py-1 text-xs font-semibold text-primary hover:bg-primary/10 transition"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <rect x="3" y="6" width="18" height="12" rx="2" />
                            <path d="M7 12h2m6 0h2M12 9v6" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        Buka Rincian Trafo & Aset
                    </a>
                @endif
            </div>
            @if ($period !== null)
                <p class="mt-1 text-xs text-slate-500 font-normal">
                    Periode {{ $period }} — UIT: {{ $formatPeriodLabel($periodUit) }} (tab 1a) | UPT: {{ $formatPeriodLabel($periodUpt) }} (tab 1b), belum diverifikasi PIC MANSET
                </p>
            @endif
        </div>

        @if (!$labels->isEmpty() && !$series->isEmpty())
            {{-- Tombol Filter Kategori --}}
            <div class="flex flex-wrap items-center gap-1.5 text-xs">
                <button
                    type="button"
                    data-category-filter="tower"
                    @click="setCategory('tower')"
                    :class="activeCategory === 'tower'
                        ? 'border-slate-800 text-slate-900 font-bold ring-1 ring-slate-800/15 shadow-2xs'
                        : 'border-slate-200 text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50 hover:text-slate-800'"
                    class="inline-flex items-center gap-1.5 cursor-pointer rounded-md border bg-white px-2.5 py-1 transition"
                >
                    Tower
                </button>
                <button
                    type="button"
                    data-category-filter="transmisi"
                    @click="setCategory('transmisi')"
                    :class="activeCategory === 'transmisi'
                        ? 'border-slate-800 text-slate-900 font-bold ring-1 ring-slate-800/15 shadow-2xs'
                        : 'border-slate-200 text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50 hover:text-slate-800'"
                    class="inline-flex items-center gap-1.5 cursor-pointer rounded-md border bg-white px-2.5 py-1 transition"
                >
                    Transmisi
                </button>
                <button
                    type="button"
                    data-category-filter="trafo"
                    @click="setCategory('trafo')"
                    :class="activeCategory === 'trafo'
                        ? 'border-slate-800 text-slate-900 font-bold ring-1 ring-slate-800/15 shadow-2xs'
                        : 'border-slate-200 text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50 hover:text-slate-800'"
                    class="inline-flex items-center gap-1.5 cursor-pointer rounded-md border bg-white px-2.5 py-1 transition"
                >
                    Trafo
                </button>
            </div>
        @endif
    </div>

    @if ($labels->isEmpty() || $series->isEmpty())
        <p class="mt-4 rounded-lg bg-surface p-5 text-sm text-gray-600">
            Belum ada data aset. Konversi rekap <code>KIN26 - STOP ONLY</code> lewat
            <code>aset:convert</code> lalu unggah berkasnya lewat menu Upload Dataset (divisi Data Aset).
        </p>
    @else
        <script>
            (function () {
                window.__asetUptConfigs = window.__asetUptConfigs || {};
                window.__asetUptConfigs[@json($chartId)] = {
                    chartId: @json($chartId),
                    donutId: @json($donutId),
                    labels: @json($labels->all()),
                    tableRows: @json($tableRows),
                    totalTower: @json($totalTower),
                    totalTransmisi: @json($totalTransmisi),
                    totalTrafo: @json($totalTrafo),
                    seriesByCategory: {
                        all: @json($chartSeriesAll),
                        tower: @json($chartSeriesTower),
                        transmisi: @json($chartSeriesTransmisi),
                        trafo: @json($chartSeriesTrafo),
                    }
                };
            })();
        </script>

        {{-- Bagian Atas: 3 Scorecard Fisik Aset Netral --}}
        <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
            {{-- Card 1: Total Tower --}}
            <div class="relative overflow-hidden rounded-xl border border-slate-200/80 bg-white p-4 sm:p-5 shadow-xs">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Total Tower</p>
                    <span class="inline-flex items-center rounded-md bg-teal-50 px-2 py-0.5 text-[11px] font-bold text-teal-700 border border-teal-200/60">
                        Unit
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-1.5">
                    <span class="text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900 font-sans" x-data="numberTicker('{{ number_format($totalTower, 0, ',', '.') }}')" x-text="display">
                        {{ number_format($totalTower, 0, ',', '.') }}
                    </span>
                    <span class="text-xs font-medium text-slate-500">Unit</span>
                </div>
                <div class="mt-3 flex items-center gap-2">
                    <div class="h-1.5 flex-1 rounded-full bg-slate-100 overflow-hidden">
                        <div class="h-full rounded-full bg-teal-600" style="width: 100%;"></div>
                    </div>
                    <span class="text-[10px] font-semibold text-slate-400">Total Register UIT JBT</span>
                </div>
            </div>

            {{-- Card 2: Total Jalur Transmisi --}}
            <div class="relative overflow-hidden rounded-xl border border-slate-200/80 bg-white p-4 sm:p-5 shadow-xs">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Total Jalur Transmisi</p>
                    <span class="inline-flex items-center rounded-md bg-sky-50 px-2 py-0.5 text-[11px] font-bold text-sky-700 border border-sky-200/60">
                        kms
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-1.5">
                    <span class="text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900 font-sans" x-data="numberTicker('{{ number_format($totalTransmisi, 1, ',', '.') }}')" x-text="display">
                        {{ number_format($totalTransmisi, 1, ',', '.') }}
                    </span>
                    <span class="text-xs font-medium text-slate-500">kms</span>
                </div>
                <div class="mt-3 flex items-center gap-2">
                    <div class="h-1.5 flex-1 rounded-full bg-slate-100 overflow-hidden">
                        <div class="h-full rounded-full bg-sky-600" style="width: 100%;"></div>
                    </div>
                    <span class="text-[10px] font-semibold text-slate-400">Total Register UIT JBT</span>
                </div>
            </div>

            {{-- Card 3: Total Kapasitas Trafo --}}
            <div class="relative overflow-hidden rounded-xl border border-slate-200/80 bg-white p-4 sm:p-5 shadow-xs">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Total Kapasitas Trafo</p>
                    <span class="inline-flex items-center rounded-md bg-indigo-50 px-2 py-0.5 text-[11px] font-bold text-indigo-700 border border-indigo-200/60">
                        MVA
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-1.5">
                    <span class="text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900 font-sans" x-data="numberTicker('{{ number_format($totalTrafo, 1, ',', '.') }}')" x-text="display">
                        {{ number_format($totalTrafo, 1, ',', '.') }}
                    </span>
                    <span class="text-xs font-medium text-slate-500">MVA</span>
                </div>
                <div class="mt-3 flex items-center gap-2">
                    <div class="h-1.5 flex-1 rounded-full bg-slate-100 overflow-hidden">
                        <div class="h-full rounded-full bg-indigo-600" style="width: 100%;"></div>
                    </div>
                    <span class="text-[10px] font-semibold text-slate-400">Total Register UIT JBT</span>
                </div>
            </div>
        </div>

        {{-- Dual-Chart ECharts Layout: Bar Comparison (Left, 2 cols) + Donut Proportion (Right, 1 col) --}}
        <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6 items-stretch">
            <div class="lg:col-span-2 rounded-xl border border-slate-200 bg-white p-4">
                <div class="flex items-center justify-between mb-3 border-b border-slate-100 pb-2.5">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-slate-700">Perbandingan 8 UPT</h4>
                    <span class="text-[11px] text-slate-400 font-mono" x-text="activeCategory.toUpperCase()"></span>
                </div>
                <div
                    id="{{ $chartId }}"
                    data-chart-id="aset-upt-chart"
                    class="w-full h-96 min-h-[360px]"
                    style="width: 100%; height: 380px; min-height: 360px;"
                ></div>
            </div>

            <div class="lg:col-span-1 rounded-xl border border-slate-200 bg-white p-4 flex flex-col justify-between">
                <div class="flex items-center justify-between mb-3 border-b border-slate-100 pb-2.5">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-slate-700">Proporsi per UPT</h4>
                    <span class="text-[11px] text-slate-400 font-semibold">% UIT JBT</span>
                </div>
                <div
                    id="{{ $donutId }}"
                    data-chart-id="aset-upt-donut"
                    class="w-full h-96 min-h-[360px]"
                    style="width: 100%; height: 380px; min-height: 360px;"
                ></div>
            </div>
        </div>

        {{-- Bagian Bawah: Tabel Rekapitulasi 8 UPT --}}
        @if (!empty($tableRows))
            <div class="mt-6">
                <div class="flex items-center justify-between mb-2">
                    <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                        Rekapitulasi Matriks Fisik per UPT
                    </h4>
                    <span class="text-xs text-slate-400">{{ count($tableRows) }} Unit Pelaksana Transmisi</span>
                </div>
                <div class="overflow-x-auto rounded-lg border border-slate-200">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50/80 text-xs font-semibold uppercase tracking-wider text-slate-600 border-b border-slate-200">
                            <tr>
                                <th class="py-2.5 px-4 text-left">UPT</th>
                                <th class="py-2.5 px-4 text-right">Tower (Unit)</th>
                                <th class="py-2.5 px-4 text-right">Transmisi (kms)</th>
                                <th class="py-2.5 px-4 text-right">Kapasitas Trafo (MVA)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            @foreach ($tableRows as $row)
                                @php
                                    $towerPct = $totalTower > 0 ? min(100, round((($row['tower'] ?? 0) / $totalTower) * 100, 1)) : 0;
                                    $transmisiPct = $totalTransmisi > 0 ? min(100, round((($row['transmisi'] ?? 0) / $totalTransmisi) * 100, 1)) : 0;
                                    $trafoPct = $totalTrafo > 0 ? min(100, round((($row['trafo'] ?? 0) / $totalTrafo) * 100, 1)) : 0;
                                @endphp
                                <tr class="hover:bg-slate-50/60 transition">
                                    <td class="py-2.5 px-4 font-semibold text-slate-800">
                                        {{ $row['upt'] }}
                                    </td>
                                    <td class="py-2.5 px-4 text-right font-mono text-slate-700">
                                        <div class="flex items-center justify-end gap-2.5">
                                            <div class="hidden sm:block w-14 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                                                <div class="h-full rounded-full bg-teal-600/70" style="width: {{ $towerPct }}%;"></div>
                                            </div>
                                            <span>{{ number_format($row['tower'] ?? 0, 0, ',', '.') }}</span>
                                        </div>
                                    </td>
                                    <td class="py-2.5 px-4 text-right font-mono text-slate-700">
                                        <div class="flex items-center justify-end gap-2.5">
                                            <div class="hidden sm:block w-14 h-1.5 rounded-full bg-sky-600/70" style="width: {{ $transmisiPct }}%;"></div>
                                        </div>
                                        <span>{{ number_format($row['transmisi'] ?? 0, 1, ',', '.') }}</span>
                                    </div>
                                    <td class="py-2.5 px-4 text-right font-mono text-slate-700">
                                        <div class="flex items-center justify-end gap-2.5">
                                            <div class="hidden sm:block w-14 h-1.5 rounded-full bg-indigo-600/70" style="width: {{ $trafoPct }}%;"></div>
                                        </div>
                                        <span>{{ number_format($row['trafo'] ?? 0, 1, ',', '.') }}</span>
                                    </div>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t-2 border-slate-200 bg-slate-50/70 font-semibold text-slate-900">
                            <tr>
                                <td class="py-2.5 px-4 text-left font-bold text-slate-900">TOTAL UIT JBT</td>
                                <td class="py-2.5 px-4 text-right font-mono font-bold text-slate-900">
                                    {{ number_format($totalTower, 0, ',', '.') }}
                                </td>
                                <td class="py-2.5 px-4 text-right font-mono font-bold text-slate-900">
                                    {{ number_format($totalTransmisi, 1, ',', '.') }}
                                </td>
                                <td class="py-2.5 px-4 text-right font-mono font-bold text-slate-900">
                                    {{ number_format($totalTrafo, 1, ',', '.') }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        @endif

        <script>
        if (typeof window.asetUptChartWidget !== 'function') {
            window.asetUptChartWidget = function(params) {
                const chartId = typeof params === 'string' ? params : (params ? params.chartId : '');
                const config = (typeof params === 'object' && params !== null && params.labels)
                    ? params
                    : ((window.__asetUptConfigs && window.__asetUptConfigs[chartId]) || {});

                // Simpan chart instance di luar objek reactive Alpine v3 untuk mencegah
                // Proxy Alpine membekukan atau merusak rendering internal Apache ECharts.
                let chartInstance = null;
                let donutInstance = null;

                return {
                    chartId: chartId,
                    activeCategory: 'tower',

                    get labels() {
                        return config.labels || [];
                    },

                    get seriesByCategory() {
                        return config.seriesByCategory || {};
                    },

                    init() {
                        this.$nextTick(() => {
                            this.initChart();
                        });
                    },

                    setCategory(cat) {
                        this.activeCategory = cat;
                        this.updateChart();
                    },

                    initChart() {
                        const el = document.getElementById(this.chartId);
                        const donutEl = document.getElementById(config.donutId || (this.chartId + '-donut'));
                        if (!el) { return; }

                        // Retry jika window.echarts belum siap saat Alpine init berjalan
                        if (!window.echarts && !window.initSafeEChart) {
                            setTimeout(() => {
                                this.initChart();
                            }, 50);
                            return;
                        }

                        if (!chartInstance) {
                            if (window.initSafeEChart) {
                                chartInstance = window.initSafeEChart(el);
                            } else if (window.echarts) {
                                chartInstance = window.echarts.getInstanceByDom(el) || window.echarts.init(el);
                            }
                        }

                        if (donutEl && !donutInstance) {
                            if (window.initSafeEChart) {
                                donutInstance = window.initSafeEChart(donutEl);
                            } else if (window.echarts) {
                                donutInstance = window.echarts.getInstanceByDom(donutEl) || window.echarts.init(donutEl);
                            }
                        }

                        this.updateChart();
                    },

                    updateChart() {
                        const el = document.getElementById(this.chartId);
                        if (!el) { return; }

                        const chart = chartInstance || (window.initSafeEChart ? window.initSafeEChart(el) : (window.echarts ? (window.echarts.getInstanceByDom(el) || window.echarts.init(el)) : null));
                        if (!chart) { return; }
                        chartInstance = chart;

                        const currentSeries = this.seriesByCategory[this.activeCategory] || this.seriesByCategory.tower || [];

                        const option = {
                            tooltip: {
                                trigger: 'axis',
                                axisPointer: { type: 'shadow' },
                                formatter: function (params) {
                                    if (!params || !params.length) { return ''; }
                                    const uptName = params[0].name;
                                    let html = '<div class="font-bold text-slate-800 border-b border-slate-200 pb-1 mb-1.5 text-xs">UPT ' + uptName + '</div>';
                                    const activeItems = params.filter(p => p.value !== undefined && p.value !== null && Number(p.value) > 0);
                                    if (activeItems.length === 0) {
                                        html += '<div class="text-xs text-slate-400 py-0.5">Tidak ada aset pada UPT ini</div>';
                                    } else {
                                        html += activeItems.map(p => {
                                            let unit = ' Unit';
                                            if (p.seriesName.includes('kms')) {
                                                unit = ' kms';
                                            } else if (p.seriesName.includes('MVA')) {
                                                unit = ' MVA';
                                            }
                                            const formattedValue = Number(p.value).toLocaleString('id-ID');
                                            return '<div class="flex items-center justify-between gap-4 py-0.5 text-xs text-slate-600">'
                                                + '<span class="flex items-center gap-1.5 truncate max-w-[220px]">' + p.marker + '<span>' + p.seriesName + '</span></span>'
                                                + '<span class="font-semibold font-mono text-slate-900">' + formattedValue + unit + '</span>'
                                                + '</div>';
                                        }).join('');
                                    }
                                    return '<div class="p-1 min-w-[220px] max-w-sm">' + html + '</div>';
                                }
                            },
                            legend: {
                                type: 'scroll',
                                top: 5,
                                pageButtonPosition: 'end',
                                textStyle: {
                                    fontSize: 11,
                                    color: '#475569'
                                }
                            },
                            grid: {
                                top: 48,
                                bottom: 45,
                                left: '3%',
                                right: '4%',
                                containLabel: true
                            },
                            xAxis: {
                                type: 'category',
                                data: this.labels,
                                axisLabel: {
                                    color: '#475569',
                                    fontSize: 11,
                                    fontWeight: 600
                                },
                                axisLine: {
                                    lineStyle: { color: '#cbd5e1' }
                                }
                            },
                            yAxis: {
                                type: 'value',
                                min: 0,
                                name: this.activeCategory === 'tower' ? 'Unit' : (this.activeCategory === 'transmisi' ? 'kms' : 'MVA'),
                                nameTextStyle: {
                                    color: '#64748b',
                                    fontSize: 11,
                                    padding: [0, 0, 4, 0]
                                },
                                axisLabel: {
                                    color: '#64748b',
                                    fontSize: 11
                                },
                                splitLine: {
                                    lineStyle: {
                                        color: '#f1f5f9',
                                        type: 'dashed'
                                    }
                                }
                            },
                            animation: true,
                            animationDuration: 650,
                            animationEasing: 'cubicOut',
                            animationDurationUpdate: 400,
                            series: currentSeries
                        };

                        chart.setOption(option, true);

                        // Render Donut Proportion Chart
                        const donutEl = document.getElementById(config.donutId || (this.chartId + '-donut'));
                        if (donutEl) {
                            const donut = donutInstance || (window.initSafeEChart ? window.initSafeEChart(donutEl) : (window.echarts ? (window.echarts.getInstanceByDom(donutEl) || window.echarts.init(donutEl)) : null));
                            if (donut) {
                                donutInstance = donut;
                                const tableRows = config.tableRows || [];
                                const activeCategory = this.activeCategory;
                                const unitMap = { tower: 'Unit', transmisi: 'kms', trafo: 'MVA' };
                                const unit = unitMap[activeCategory] || '';

                                const pieData = tableRows.map(row => {
                                    let val = 0;
                                    if (activeCategory === 'tower') val = row.tower || 0;
                                    else if (activeCategory === 'transmisi') val = row.transmisi || 0;
                                    else if (activeCategory === 'trafo') val = row.trafo || 0;
                                    return { name: row.upt, value: val };
                                });

                                const donutPalette = ['#0f766e', '#0284c7', '#6366f1', '#0d9488', '#38bdf8', '#818cf8', '#475569', '#64748b'];

                                const donutOption = {
                                    animation: true,
                                    animationDuration: 650,
                                    tooltip: {
                                        trigger: 'item',
                                        formatter: function (p) {
                                            const val = Number(p.value).toLocaleString('id-ID');
                                            return '<div class="p-1 text-xs">'
                                                + '<span class="font-bold text-slate-800">UPT ' + p.name + '</span><br/>'
                                                + '<span class="text-slate-600">' + val + ' ' + unit + ' (' + (p.percent || 0) + '%)</span>'
                                                + '</div>';
                                        }
                                    },
                                    legend: {
                                        show: false
                                    },
                                    color: donutPalette,
                                    series: [{
                                        name: 'Pangsa UPT',
                                        type: 'pie',
                                        radius: ['44%', '70%'],
                                        center: ['50%', '50%'],
                                        avoidLabelOverlap: true,
                                        itemStyle: {
                                            borderRadius: 4,
                                            borderColor: '#ffffff',
                                            borderWidth: 2
                                        },
                                        label: {
                                            show: true,
                                            position: 'outside',
                                            formatter: '{b}\n{d}%',
                                            fontSize: 10,
                                            color: '#475569'
                                        },
                                        labelLine: {
                                            show: true,
                                            length: 8,
                                            length2: 8
                                        },
                                        data: pieData
                                    }]
                                };

                                donut.setOption(donutOption, true);
                            }
                        }
                    }
                };
            };
        }
        </script>
    @endif
</div>
@endif
