@props(['widget' => [], 'data' => null])

{{-- Widget grafik stok aset per UPT (server-side, snapshot periode).
     Data: DashboardController::asetUptChart() — labels UPT, series bar per atribut,
     scorecard ringkasan (Tower, Transmisi, Trafo), dan tabel rekapitulasi 8 UPT.
     Sejak 2026-11-11 widget dipasang HANYA di layout default staf Managemen Aset
     sebagai demo prototipe (instruksi user, bukan sign-off mentor/PIC). --}}
@php
    $labels = collect($data['labels'] ?? []);
    $series = collect($data['series'] ?? []);
    $period = $data['period'] ?? null;
    $summary = $data['summary'] ?? [];
    $tableRows = $data['table_rows'] ?? [];
    $seriesByCategory = $data['series_by_category'] ?? [];

    $chartSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($widget['key'] ?? 'aset-upt-chart'));
    $chartId = 'asetUptChart-'.$chartSlug.'-'.($widget['instance'] ?? 0);
    $palette = ['#14a2ba', '#125d72', '#efe62f', '#d9d9d9', '#0b7a75', '#f39233', '#7a5195', '#ef5675', '#2f9e44', '#1971c2'];

    // Map series to format expected by ECharts bar chart with palette colors
    $formatSeries = function (iterable $items) use ($palette): array {
        return collect($items)->map(fn ($item, $index) => [
            'name' => $item['name'],
            'type' => 'bar',
            'data' => $item['data'],
            'itemStyle' => ['color' => $palette[$index % count($palette)]],
        ])->values()->all();
    };

    $chartSeriesAll = $formatSeries($series);

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
    $chartSeriesTrafo = $formatSeries($trafoRaw);

    // Fallback calculation for summary if not supplied in data
    $totalTower = $summary['total_tower'] ?? $towerRaw->sum(fn ($s) => array_sum($s['data'] ?? []));
    $totalTransmisi = $summary['total_transmisi'] ?? $transmisiRaw->sum(fn ($s) => array_sum($s['data'] ?? []));
    $trafoMvaRaw = $trafoRaw->filter(fn ($s) => str_contains($s['name'] ?? '', 'MVA'));
    $totalTrafo = $summary['total_trafo'] ?? ($trafoMvaRaw->isNotEmpty() ? $trafoMvaRaw->sum(fn ($s) => array_sum($s['data'] ?? [])) : $trafoRaw->sum(fn ($s) => array_sum($s['data'] ?? [])));
@endphp

<div
    class="mt-8 rounded-lg bg-white border border-slate-200 p-6 shadow-sm"
    @if (!$labels->isEmpty() && !$series->isEmpty())
        x-data="asetUptChartWidget(@json($chartId))"
        x-init="init()"
    @endif
>
    {{-- Header Widget: Judul, Badge, Subtitle & Tombol Filter Kategori --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-b border-slate-100 pb-4">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="text-lg font-bold text-primary">{{ $widget['label'] ?? 'Stok Aset per UPT' }}</h3>
                <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800">
                    Prototipe Demo (Rekapitulasi UPT)
                </span>
            </div>
            @if ($period !== null)
                <p class="mt-1 text-xs text-slate-500">Periode {{ $period }} — agregat per UPT dari rekap ULTG.</p>
            @endif
        </div>

        @if (!$labels->isEmpty() && !$series->isEmpty())
            {{-- Tombol Filter Kategori --}}
            <div class="flex flex-wrap items-center gap-1.5 text-xs">
                <button
                    type="button"
                    data-category-filter="all"
                    @click="setCategory('all')"
                    :class="activeCategory === 'all'
                        ? 'border-slate-800 text-slate-900 font-bold ring-1 ring-slate-800/15 shadow-2xs'
                        : 'border-slate-200 text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50 hover:text-slate-800'"
                    class="inline-flex items-center gap-1.5 cursor-pointer rounded-md border bg-white px-2.5 py-1 transition"
                >
                    Semua
                </button>
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
                    labels: @json($labels->all()),
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
            <div class="relative overflow-hidden rounded-xl border border-slate-200/80 bg-white p-4 sm:p-5 shadow-[0_1px_3px_0_rgba(0,0,0,0.04)]">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Total Tower</p>
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-slate-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M12 2v20M6 8l12 0M4 14l16 0M8 22l4-20 4 20" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                </div>
                <div class="mt-2 flex items-baseline gap-1.5">
                    <span class="text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900 font-sans">
                        {{ number_format($totalTower, 0, ',', '.') }}
                    </span>
                    <span class="text-xs font-medium text-slate-500">Unit</span>
                </div>
                <p class="mt-2 text-[11px] text-slate-500">Akumulasi seluruh UPT (500 kV, 150 kV, 70 kV, 30 kV)</p>
            </div>

            {{-- Card 2: Total Jalur Transmisi --}}
            <div class="relative overflow-hidden rounded-xl border border-slate-200/80 bg-white p-4 sm:p-5 shadow-[0_1px_3px_0_rgba(0,0,0,0.04)]">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Total Jalur Transmisi</p>
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-slate-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M22 12h-4l-3 9L9 3l-3 9H2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                </div>
                <div class="mt-2 flex items-baseline gap-1.5">
                    <span class="text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900 font-sans">
                        {{ number_format($totalTransmisi, 1, ',', '.') }}
                    </span>
                    <span class="text-xs font-medium text-slate-500">kms</span>
                </div>
                <p class="mt-2 text-[11px] text-slate-500">Panjang jalur transmisi terpasang (SUTET, SUTT, SKTT)</p>
            </div>

            {{-- Card 3: Total Kapasitas Trafo --}}
            <div class="relative overflow-hidden rounded-xl border border-slate-200/80 bg-white p-4 sm:p-5 shadow-[0_1px_3px_0_rgba(0,0,0,0.04)]">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Total Kapasitas Trafo</p>
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-slate-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <rect x="3" y="6" width="18" height="12" rx="2"/>
                            <path d="M7 12h2m6 0h2M12 9v6" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                </div>
                <div class="mt-2 flex items-baseline gap-1.5">
                    <span class="text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900 font-sans">
                        {{ number_format($totalTrafo, 1, ',', '.') }}
                    </span>
                    <span class="text-xs font-medium text-slate-500">MVA</span>
                </div>
                <p class="mt-2 text-[11px] text-slate-500">Total daya tampung trafo daya gardu induk</p>
            </div>
        </div>

        {{-- Kanvas ECharts --}}
        <div
            id="{{ $chartId }}"
            data-chart-id="aset-upt-chart"
            class="mt-6 h-80 min-h-[360px] w-full sm:h-96"
            x-init="$nextTick(() => initChart())"
        ></div>

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
                                <tr class="hover:bg-slate-50/60 transition">
                                    <td class="py-2 px-4 font-semibold text-slate-800">
                                        {{ $row['upt'] }}
                                    </td>
                                    <td class="py-2 px-4 text-right font-mono text-slate-700">
                                        {{ number_format($row['tower'] ?? 0, 0, ',', '.') }}
                                    </td>
                                    <td class="py-2 px-4 text-right font-mono text-slate-700">
                                        {{ number_format($row['transmisi'] ?? 0, 1, ',', '.') }}
                                    </td>
                                    <td class="py-2 px-4 text-right font-mono text-slate-700">
                                        {{ number_format($row['trafo'] ?? 0, 1, ',', '.') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t-2 border-slate-200 bg-slate-50/70 font-semibold text-slate-900">
                            <tr>
                                <td class="py-2.5 px-4 text-left">Total Rekapitulasi</td>
                                <td class="py-2.5 px-4 text-right font-mono text-slate-900">
                                    {{ number_format($totalTower, 0, ',', '.') }}
                                </td>
                                <td class="py-2.5 px-4 text-right font-mono text-slate-900">
                                    {{ number_format($totalTransmisi, 1, ',', '.') }}
                                </td>
                                <td class="py-2.5 px-4 text-right font-mono text-slate-900">
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

                return {
                    chartId: chartId,
                    activeCategory: 'all',
                    chart: null,

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
                        if (!el || !window.echarts) { return; }

                        if (this.chart) {
                            this.updateChart();
                            return;
                        }

                        const existing = window.echarts.getInstanceByDom(el);
                        if (existing) { existing.dispose(); }

                        this.chart = window.echarts.init(el);
                        this.updateChart();

                        new ResizeObserver(() => this.chart && this.chart.resize()).observe(el);
                    },

                    updateChart() {
                        if (!this.chart) { return; }

                        const currentSeries = this.seriesByCategory[this.activeCategory] || this.seriesByCategory.all || [];

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
                                top: 45,
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
                            series: currentSeries
                        };

                        this.chart.setOption(option, true);
                    }
                };
            };
        }
        </script>
    @endif
</div>
