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
     scorecard ringkasan (Tower, Transmisi, Trafo), dan tabel rekapitulasi 8 UPT. --}}
@php
    $labels = collect($data['labels'] ?? []);
    $series = collect($data['series'] ?? []);
    $period = $data['period'] ?? null;
    $periodUit = $data['period_uit'] ?? $period;
    $periodUpt = $data['period_upt'] ?? $period;
    $summary = $data['summary'] ?? [];
    $tableRows = $data['table_rows'] ?? [];
    $seriesByCategory = $data['series_by_category'] ?? [];
    $freshness = is_array($data['freshness'] ?? null) ? $data['freshness'] : null;

    $chartSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($widget['key'] ?? 'aset-upt-chart'));
    $chartId = 'asetUptChart-'.$chartSlug.'-'.($widget['instance'] ?? 0);
    $donutId = 'asetUptDonut-'.$chartSlug.'-'.($widget['instance'] ?? 0);
    // Palet monokromatik Teal/Slate selaras dengan window.PLN_CHART_PALETTE
    $palette = ['#0b6175', '#16a3b8', '#52b2bf', '#bce3e8', '#94a3b8', '#cbd5e1'];

    $formatMonthYear = function (?string $rawDate): string {
        if (! $rawDate) {
            return 'Tidak tercatat';
        }
        try {
            $months = [
                1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
                7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
            ];
            $date = \Carbon\Carbon::parse($rawDate);
            $month = $months[$date->month] ?? $date->format('M');
            return $month.' '.$date->year;
        } catch (\Throwable $e) {
            return 'Tidak tercatat';
        }
    };

    // Map series to format expected by ECharts bar chart with monochromatic palette & rounded top corners
    $formatSeries = function (iterable $items) use ($palette): array {
        return collect($items)->map(fn ($item, $index) => [
            'name' => $item['name'],
            'type' => 'bar',
            'data' => $item['data'],
            'barMaxWidth' => 28,
            'itemStyle' => [
                'color' => $palette[$index % count($palette)],
                'borderRadius' => [6, 6, 0, 0],
            ],
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
    class="space-y-5 min-w-0 max-w-full"
    @if (!$labels->isEmpty() && !$series->isEmpty())
        x-data='asetUptChartWidget(@json($chartId))'
        x-init="init()"
    @endif
>
    {{-- Header Bersih Satu Baris --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex min-w-0 flex-col gap-1.5">
            <div class="flex flex-wrap items-center gap-2.5">
                <h3 class="ibs-section-title text-lg font-bold text-slate-900">Kepemilikan Aset UIT JBT</h3>
                <span class="sr-only">{{ $widget['label'] ?? 'Stok Aset per UPT' }}</span>
                <span class="inline-flex items-center rounded-full bg-slate-100 border border-slate-200/80 px-2.5 py-0.5 text-xs font-medium text-slate-600">
                    Prototipe Demo
                </span>
                <span class="sr-only">Prototipe Demo (Rekapitulasi UPT)</span>
            </div>
            <x-data-freshness-badge id="aset-freshness-indicator" :freshness="$freshness" />
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($period !== null)
                <span class="inline-flex items-center rounded-full bg-[#e6f4f6] px-3 py-1 text-xs font-semibold text-[#0b6175]">
                    {{ $formatMonthYear($period) }}
                </span>
                <span class="inline-flex items-center rounded-full border border-slate-200/80 bg-white px-2.5 py-0.5 text-[11px] font-medium text-slate-600">
                    Tab 1a &amp; Tab 1b
                </span>
                <span class="sr-only">
                    Periode {{ $period }} Sumber: Rekap Induk UIT (Tab 1a) & Distribusi 8 UPT (Tab 1b)
                </span>
            @endif
            @if (\App\Http\Controllers\AssetDetailController::canAccessAssetDetail(auth()->user()))
                <a
                    href="{{ route('dashboard.asset-detail') }}"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-[#0b6175]/20 bg-white px-3 py-1.5 text-xs font-semibold text-[#0b6175] hover:bg-[#e6f4f6]/60 transition shadow-2xs"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <rect x="3" y="6" width="18" height="12" rx="2" />
                        <path d="M7 12h2m6 0h2M12 9v6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    Rincian Trafo & Aset
                </a>
            @endif
        </div>
    </div>

    @if ($labels->isEmpty() || $series->isEmpty())
        <div class="ibs-card p-6 text-center">
            <p class="text-sm font-medium text-slate-600">Belum ada data aset.</p>
        </div>
    @else
        <script @cspNonce>
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

        <span class="sr-only">
            Kepemilikan Aset Induk UIT JBT (Unit Induk Transmisi Jawa Bagian Tengah)
            Rekapitulasi Kepemilikan Induk UIT JBT • Tab 1a
            Distribusi / Sebaran Wilayah Aset UIT pada 8 UPT (Tab 1b)
        </span>

        {{-- Row 1: Unified Metric Banner (3 Metrik Induk UIT JBT dalam 1 .ibs-card) --}}
        <div class="ibs-card min-w-0 max-w-full overflow-hidden">
            <div class="grid grid-cols-1 sm:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x divide-slate-100">
                {{-- Metric 1: Total Tower --}}
                <div class="p-5">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2.5">
                            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-[#e6f4f6] text-[#0b6175]">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path d="M12 2v20M6 8h12M4 14h16M8 22l4-20 4 20" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </div>
                            <span class="ibs-metric-label">Total Tower</span>
                        </div>
                        <span class="rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">
                            Unit
                        </span>
                    </div>
                    <div class="mt-3 flex items-baseline gap-2">
                        <span class="ibs-metric-value text-2xl sm:text-3xl" x-data="numberTicker('{{ number_format($totalTower, 0, ',', '.') }}')" x-text="display">
                            {{ number_format($totalTower, 0, ',', '.') }}
                        </span>
                        <span class="text-xs font-medium text-slate-500">Unit</span>
                    </div>
                </div>

                {{-- Metric 2: Total Jalur Transmisi --}}
                <div class="p-5">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2.5">
                            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-[#e6f4f6] text-[#0b6175]">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path d="M22 12h-4l-3 9L9 3l-3 9H2" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </div>
                            <span class="ibs-metric-label">Total Jalur Transmisi</span>
                        </div>
                        <span class="rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">
                            kms
                        </span>
                    </div>
                    <div class="mt-3 flex items-baseline gap-2">
                        <span class="ibs-metric-value text-2xl sm:text-3xl" x-data="numberTicker('{{ number_format($totalTransmisi, 1, ',', '.') }}')" x-text="display">
                            {{ number_format($totalTransmisi, 1, ',', '.') }}
                        </span>
                        <span class="text-xs font-medium text-slate-500">kms</span>
                    </div>
                </div>

                {{-- Metric 3: Total Kapasitas Trafo --}}
                <div class="p-5">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2.5">
                            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-[#e6f4f6] text-[#0b6175]">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </div>
                            <span class="ibs-metric-label">Total Kapasitas Trafo</span>
                        </div>
                        <span class="rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">
                            MVA
                        </span>
                    </div>
                    <div class="mt-3 flex items-baseline gap-2">
                        <span class="ibs-metric-value text-2xl sm:text-3xl" x-data="numberTicker('{{ number_format($totalTrafo, 1, ',', '.') }}')" x-text="display">
                            {{ number_format($totalTrafo, 1, ',', '.') }}
                        </span>
                        <span class="text-xs font-medium text-slate-500">MVA</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Row 2: Split Visual Canvas (Bar Chart ~65% + Donut & Ranking List ~35%) --}}
        <div class="ibs-card min-w-0 max-w-full p-4 sm:p-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-b border-slate-100 pb-4">
                <div class="flex flex-wrap items-center gap-2.5 min-w-0">
                    <h4 class="ibs-section-title">Sebaran Aset 8 UPT</h4>
                    <span class="rounded-md bg-[#e6f4f6] px-2 py-0.5 text-[11px] font-semibold text-[#0b6175]" x-text="activeUnit"></span>
                </div>

                {{-- Segmented Pill Switcher --}}
                <div class="inline-flex flex-wrap max-w-full items-center rounded-xl bg-slate-100/90 p-1 text-xs self-start sm:self-auto">
                    <button
                        type="button"
                        data-category-filter="tower"
                        @click="setCategory('tower')"
                        :class="activeCategory === 'tower'
                            ? 'bg-white text-[#0b6175] font-semibold shadow-2xs'
                            : 'text-slate-600 hover:text-slate-900 font-medium'"
                        class="pill-tab-transition cursor-pointer rounded-lg px-3 py-1.5"
                    >
                        Tower
                    </button>
                    <button
                        type="button"
                        data-category-filter="transmisi"
                        @click="setCategory('transmisi')"
                        :class="activeCategory === 'transmisi'
                            ? 'bg-white text-[#0b6175] font-semibold shadow-2xs'
                            : 'text-slate-600 hover:text-slate-900 font-medium'"
                        class="pill-tab-transition cursor-pointer rounded-lg px-3 py-1.5"
                    >
                        Transmisi
                    </button>
                    <button
                        type="button"
                        data-category-filter="trafo"
                        @click="setCategory('trafo')"
                        :class="activeCategory === 'trafo'
                            ? 'bg-white text-[#0b6175] font-semibold shadow-2xs'
                            : 'text-slate-600 hover:text-slate-900 font-medium'"
                        class="pill-tab-transition cursor-pointer rounded-lg px-3 py-1.5"
                    >
                        Trafo
                    </button>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-1 lg:grid-cols-12 gap-6 items-stretch min-w-0">
                {{-- Left (~65%): ECharts Bar Canvas --}}
                <div class="lg:col-span-8 min-w-0 max-w-full overflow-hidden flex flex-col justify-between">
                    <div
                        id="{{ $chartId }}"
                        data-chart-id="aset-upt-chart"
                        class="w-full max-w-full overflow-hidden h-96 min-h-[360px]"
                        style="width: 100%; height: 380px; min-height: 360px;"
                    ></div>
                </div>

                {{-- Right (~35%): Donut + Horizontal Ranking Bar List --}}
                <div class="lg:col-span-4 min-w-0 max-w-full flex flex-col justify-between gap-4 border-t lg:border-t-0 lg:border-l border-slate-100 pt-4 lg:pt-0 lg:pl-5">
                    <div class="flex items-center justify-between">
                        <span class="ibs-section-title text-sm">Peringkat UPT</span>
                        <span class="text-[11px] font-semibold text-slate-500">% Pangsa</span>
                    </div>

                    <div
                        id="{{ $donutId }}"
                        data-chart-id="aset-upt-donut"
                        class="w-full max-w-full overflow-hidden"
                        style="width: 100%; height: 185px; min-height: 185px;"
                    ></div>

                    <div class="space-y-2.5">
                        <template x-for="(item, idx) in rankedActiveRows" :key="item.upt">
                            <div class="space-y-1">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-medium text-slate-700 truncate" x-text="item.upt"></span>
                                    <div class="flex items-center gap-2 font-sans tabular-nums">
                                        <span class="font-semibold text-slate-900" x-text="item.formatted"></span>
                                        <span class="text-[11px] text-slate-500 w-10 text-right" x-text="item.pct + '%'"></span>
                                    </div>
                                </div>
                                <div class="h-1.5 w-full rounded-full bg-slate-100 overflow-hidden">
                                    <div
                                        class="h-full rounded-full transition-all duration-300"
                                        :class="idx === 0 ? 'bg-[#0b6175]' : (idx <= 2 ? 'bg-[#16a3b8]' : 'bg-[#bce3e8]')"
                                        :style="`width: ${item.barPct}%`"
                                    ></div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        {{-- Row 3: Compact 8 UPT Matrix --}}
        @if (!empty($tableRows))
            <div class="ibs-card min-w-0 max-w-full overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-4">
                    <h4 class="ibs-section-title">Matriks Fisik UPT</h4>
                    <span class="sr-only">Rekapitulasi Matriks Fisik per UPT</span>
                    <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-600">
                        {{ count($tableRows) }} UPT
                    </span>
                </div>
                <div class="w-full min-w-0 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50/70 text-[11px] font-semibold uppercase tracking-wider text-slate-500 border-b border-slate-100">
                            <tr>
                                <th class="py-3 px-5 text-left">UPT</th>
                                <th class="py-3 px-5 text-right">Tower (Unit)</th>
                                <th class="py-3 px-5 text-right">Transmisi (kms)</th>
                                <th class="py-3 px-5 text-right">Kapasitas Trafo (MVA)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            @foreach ($tableRows as $row)
                                @php
                                    $towerVal = (float) ($row['tower'] ?? 0);
                                    $transmisiVal = (float) ($row['transmisi'] ?? 0);
                                    $trafoVal = (float) ($row['trafo'] ?? 0);
                                    $towerPct = $totalTower > 0 ? min(100, round(($towerVal / $totalTower) * 100, 1)) : 0;
                                    $transmisiPct = $totalTransmisi > 0 ? min(100, round(($transmisiVal / $totalTransmisi) * 100, 1)) : 0;
                                    $trafoPct = $totalTrafo > 0 ? min(100, round(($trafoVal / $totalTrafo) * 100, 1)) : 0;
                                @endphp
                                <tr class="hover:bg-slate-50/60 transition">
                                    <td class="py-2.5 px-5 font-semibold text-slate-800">
                                        {{ $row['upt'] }}
                                    </td>
                                    <td class="py-2.5 px-5 text-right font-sans tabular-nums text-slate-700">
                                        <div class="flex items-center justify-end gap-2.5">
                                            <div class="hidden sm:block w-16 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                                                <div class="h-full rounded-full bg-[#0b6175]" style="width: {{ $towerPct }}%;"></div>
                                            </div>
                                            <span class="min-w-[3.5rem] text-right">{{ number_format($towerVal, 0, ',', '.') }}</span>
                                        </div>
                                    </td>
                                    <td class="py-2.5 px-5 text-right font-sans tabular-nums text-slate-700">
                                        <div class="flex items-center justify-end gap-2.5">
                                            <div class="hidden sm:block w-16 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                                                <div class="h-full rounded-full bg-[#16a3b8]" style="width: {{ $transmisiPct }}%;"></div>
                                            </div>
                                            <span class="min-w-[3.5rem] text-right">{{ number_format($transmisiVal, 1, ',', '.') }}</span>
                                        </div>
                                    </td>
                                    <td class="py-2.5 px-5 text-right font-sans tabular-nums text-slate-700">
                                        <div class="flex items-center justify-end gap-2.5">
                                            <div class="hidden sm:block w-16 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                                                <div class="h-full rounded-full bg-[#52b2bf]" style="width: {{ $trafoPct }}%;"></div>
                                            </div>
                                            <span class="min-w-[4rem] text-right">{{ number_format($trafoVal, 1, ',', '.') }}</span>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t border-slate-200 bg-slate-50/80 font-semibold text-slate-900">
                            <tr>
                                <td class="py-3 px-5 text-left font-bold text-slate-900">TOTAL UIT JBT</td>
                                <td class="py-3 px-5 text-right font-sans tabular-nums font-bold text-slate-900">
                                    {{ number_format($totalTower, 0, ',', '.') }}
                                </td>
                                <td class="py-3 px-5 text-right font-sans tabular-nums font-bold text-slate-900">
                                    {{ number_format($totalTransmisi, 1, ',', '.') }}
                                </td>
                                <td class="py-3 px-5 text-right font-sans tabular-nums font-bold text-slate-900">
                                    {{ number_format($totalTrafo, 1, ',', '.') }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        @endif

        <script @cspNonce>
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

                    get activeUnit() {
                        const unitMap = { tower: 'Unit', transmisi: 'kms', trafo: 'MVA' };
                        return unitMap[this.activeCategory] || 'Unit';
                    },

                    get rankedActiveRows() {
                        const rows = config.tableRows || [];
                        const cat = this.activeCategory;
                        const totalMap = {
                            tower: Number(config.totalTower || 0),
                            transmisi: Number(config.totalTransmisi || 0),
                            trafo: Number(config.totalTrafo || 0),
                        };
                        const denom = totalMap[cat] > 0
                            ? totalMap[cat]
                            : rows.reduce((sum, r) => sum + Number(r[cat] || 0), 0);

                        const mapped = rows.map(r => {
                            const val = Number(r[cat] || 0);
                            return {
                                upt: r.upt,
                                value: val,
                                formatted: cat === 'tower'
                                    ? val.toLocaleString('id-ID')
                                    : val.toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }),
                                pct: denom > 0 ? ((val / denom) * 100).toFixed(1) : '0.0',
                            };
                        }).sort((a, b) => b.value - a.value);

                        const maxVal = mapped.length > 0 ? Math.max(...mapped.map(i => i.value), 1) : 1;
                        return mapped.map(item => ({
                            ...item,
                            barPct: Math.min(100, Math.round((item.value / maxVal) * 100)),
                        }));
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

                        const p = window.PLN_CHART_PALETTE || {
                            primary: '#0b6175',
                            accent: '#16a3b8',
                            softTeal: '#bce3e8',
                            targetSlate: '#cbd5e1',
                            gridLine: '#f1f5f9',
                            axisText: '#64748b',
                        };

                        const currentSeries = this.seriesByCategory[this.activeCategory] || this.seriesByCategory.tower || [];

                        const option = {
                            tooltip: {
                                trigger: 'axis',
                                axisPointer: { type: 'shadow' },
                                formatter: function (params) {
                                    if (!params || !params.length) { return ''; }
                                    const uptName = params[0].name;
                                    let html = '<div class="font-bold text-slate-800 border-b border-slate-100 pb-1 mb-1.5 text-xs">UPT ' + uptName + '</div>';
                                    const activeItems = params.filter(item => item.value !== undefined && item.value !== null && Number(item.value) > 0);
                                    if (activeItems.length === 0) {
                                        html += '<div class="text-xs text-slate-400 py-0.5">Tidak tercatat</div>';
                                    } else {
                                        html += activeItems.map(item => {
                                            let unit = ' Unit';
                                            if (item.seriesName.includes('kms')) {
                                                unit = ' kms';
                                            } else if (item.seriesName.includes('MVA')) {
                                                unit = ' MVA';
                                            }
                                            const formattedValue = Number(item.value).toLocaleString('id-ID');
                                            return '<div class="flex items-center justify-between gap-4 py-0.5 text-xs text-slate-600">'
                                                + '<span class="flex items-center gap-1.5 truncate max-w-[220px]">' + item.marker + '<span>' + item.seriesName + '</span></span>'
                                                + '<span class="font-semibold font-sans tabular-nums text-slate-900">' + formattedValue + unit + '</span>'
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
                                    color: p.axisText
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
                                    lineStyle: { color: p.targetSlate }
                                }
                            },
                            yAxis: {
                                type: 'value',
                                min: 0,
                                name: this.activeUnit,
                                nameTextStyle: {
                                    color: p.axisText,
                                    fontSize: 11,
                                    padding: [0, 0, 4, 0]
                                },
                                axisLabel: {
                                    color: p.axisText,
                                    fontSize: 11
                                },
                                splitLine: {
                                    lineStyle: {
                                        color: p.gridLine,
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
                                const unit = this.activeUnit;

                                const pieData = tableRows.map(row => {
                                    let val = 0;
                                    if (activeCategory === 'tower') val = row.tower || 0;
                                    else if (activeCategory === 'transmisi') val = row.transmisi || 0;
                                    else if (activeCategory === 'trafo') val = row.trafo || 0;
                                    return { name: row.upt, value: val };
                                }).sort((a, b) => b.value - a.value);

                                const donutPalette = [
                                    p.primary,
                                    p.accent,
                                    '#52b2bf',
                                    '#84cdd6',
                                    p.softTeal,
                                    '#64748b',
                                    '#94a3b8',
                                    p.targetSlate,
                                ];

                                const donutOption = {
                                    animation: true,
                                    animationDuration: 650,
                                    tooltip: {
                                        trigger: 'item',
                                        formatter: function (item) {
                                            const val = Number(item.value).toLocaleString('id-ID');
                                            return '<div class="p-1 text-xs font-sans tabular-nums">'
                                                + '<span class="font-bold text-slate-800">UPT ' + item.name + '</span><br/>'
                                                + '<span class="text-slate-600">' + val + ' ' + unit + ' (' + (item.percent || 0) + '%)</span>'
                                                + '</div>';
                                        }
                                    },
                                    legend: { show: false },
                                    color: donutPalette,
                                    series: [{
                                        name: 'Pangsa UPT',
                                        type: 'pie',
                                        radius: ['52%', '78%'],
                                        center: ['50%', '50%'],
                                        avoidLabelOverlap: true,
                                        itemStyle: {
                                            borderRadius: 4,
                                            borderColor: '#ffffff',
                                            borderWidth: 2
                                        },
                                        label: { show: false },
                                        labelLine: { show: false },
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
