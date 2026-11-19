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
    $availablePeriods = array_values(array_filter(
        is_array($data['available_periods'] ?? null)
            ? $data['available_periods']
            : ($period !== null && $period !== '' ? [$period] : []),
        fn ($p) => is_string($p) && $p !== ''
    ));
    $ultgByUpt = is_array($data['ultg_by_upt'] ?? null) ? $data['ultg_by_upt'] : [];
    $capacityRatios = is_array($data['capacity_ratios'] ?? null) ? $data['capacity_ratios'] : [];
    $trafoRatios = is_array($capacityRatios['trafo'] ?? null) ? $capacityRatios['trafo'] : [];
    $transmisiRatios = is_array($capacityRatios['transmisi'] ?? null) ? $capacityRatios['transmisi'] : [];
    $trafoOverall = is_array($trafoRatios['overall'] ?? null) ? $trafoRatios['overall'] : [];
    $transmisiOverall = is_array($transmisiRatios['overall'] ?? null) ? $transmisiRatios['overall'] : [];
    $trafoByVoltage = is_array($trafoRatios['by_voltage'] ?? null) ? $trafoRatios['by_voltage'] : [];
    $transmisiByVoltage = is_array($transmisiRatios['by_voltage'] ?? null) ? $transmisiRatios['by_voltage'] : [];

    $chartSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($widget['key'] ?? 'aset-upt-chart'));
    $chartId = 'asetUptChart-'.$chartSlug.'-'.($widget['instance'] ?? 0);
    $donutId = 'asetUptDonut-'.$chartSlug.'-'.($widget['instance'] ?? 0);
    // Palet kategorikal kontras tinggi khusus grafik ECharts agar antar seri mudah dibedakan secara visual
    $palette = ['#0b6175', '#f59e0b', '#2563eb', '#10b981', '#7c3aed', '#e11d48', '#ea580c', '#64748b'];

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

    $formatNullable = function (mixed $val, int $decimals = 1): string {
        if ($val === null || $val === '' || ! is_numeric($val)) {
            return 'Tidak tercatat';
        }
        return number_format((float) $val, $decimals, ',', '.');
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

    $sumNullableSeries = function (\Illuminate\Support\Collection $items): ?float {
        if ($items->isEmpty()) {
            return null;
        }
        $hasNumeric = false;
        $sum = 0.0;
        foreach ($items as $s) {
            foreach ($s['data'] ?? [] as $v) {
                if ($v !== null && is_numeric($v)) {
                    $hasNumeric = true;
                    $sum += (float) $v;
                }
            }
        }
        return $hasNumeric ? $sum : null;
    };

    // Fallback calculation for summary if not supplied in data, preserving null vs 0
    $totalTower = array_key_exists('total_tower', $summary)
        ? ($summary['total_tower'] !== null ? (float) $summary['total_tower'] : null)
        : $sumNullableSeries($towerRaw);
    $totalTransmisi = array_key_exists('total_transmisi', $summary)
        ? ($summary['total_transmisi'] !== null ? (float) $summary['total_transmisi'] : null)
        : $sumNullableSeries($transmisiRaw);
    $totalTrafo = array_key_exists('total_trafo', $summary)
        ? ($summary['total_trafo'] !== null ? (float) $summary['total_trafo'] : null)
        : $sumNullableSeries($trafoMvaRaw->isNotEmpty() ? $trafoMvaRaw : $trafoRaw);
@endphp

<div
    class="space-y-5 min-w-0 max-w-full font-aset"
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
            @if (!empty($availablePeriods))
                <form method="GET" action="{{ route('dashboard') }}" class="flex items-center">
                    <label for="aset-widget-period-select" class="sr-only">Pilih periode historis aset</label>
                    <select
                        id="aset-widget-period-select"
                        name="period"
                        data-auto-submit
                        aria-label="Pilih periode historis aset"
                        class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-2xs focus:border-[#0b6175] focus:outline-hidden"
                    >
                        @foreach ($availablePeriods as $pOption)
                            <option value="{{ $pOption }}" @selected($pOption === $period)>
                                {{ $formatMonthYear($pOption) }}
                            </option>
                        @endforeach
                    </select>
                </form>
            @endif
            @if (\App\Http\Controllers\AssetDetailController::canAccessAssetDetail(auth()->user()))
                <a
                    href="{{ $period !== null ? route('dashboard.asset-detail', ['period' => $period]) : route('dashboard.asset-detail') }}"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-[#0b6175]/20 bg-white px-3 py-1.5 text-xs font-semibold text-[#0b6175] hover:bg-[#e6f4f6]/60 transition shadow-2xs"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <rect x="3" y="6" width="18" height="12" rx="2" />
                        <path d="M7 12h2m6 0h2M12 9v6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    Rincian Trafo & Aset
                </a>
                <a
                    id="aset-widget-fullscreen-link"
                    href="{{ route('dashboard.asset-detail', array_filter(['section' => 'trafo', 'period' => $period, 'fullscreen' => 1], fn ($v) => $v !== null && $v !== '')) }}"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-[#0b6175] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#094e5e] transition shadow-2xs whitespace-nowrap focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-[#16a3b8]"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span>Layar Penuh</span>
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
                    ultgByUpt: @json($ultgByUpt),
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
                        @if ($totalTower === null)
                            <span class="text-sm font-medium text-slate-400">Tidak tercatat</span>
                        @else
                            <span class="ibs-metric-value text-2xl sm:text-3xl" x-data="numberTicker('{{ number_format($totalTower, 0, ',', '.') }}')" x-text="display">
                                {{ number_format($totalTower, 0, ',', '.') }}
                            </span>
                            <span class="text-xs font-medium text-slate-500">Unit</span>
                        @endif
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
                        @if ($totalTransmisi === null)
                            <span class="text-sm font-medium text-slate-400">Tidak tercatat</span>
                        @else
                            <span class="ibs-metric-value text-2xl sm:text-3xl" x-data="numberTicker('{{ number_format($totalTransmisi, 1, ',', '.') }}')" x-text="display">
                                {{ number_format($totalTransmisi, 1, ',', '.') }}
                            </span>
                            <span class="text-xs font-medium text-slate-500">kms</span>
                        @endif
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
                        @if ($totalTrafo === null)
                            <span class="text-sm font-medium text-slate-400">Tidak tercatat</span>
                        @else
                            <span class="ibs-metric-value text-2xl sm:text-3xl" x-data="numberTicker('{{ number_format($totalTrafo, 1, ',', '.') }}')" x-text="display">
                                {{ number_format($totalTrafo, 1, ',', '.') }}
                            </span>
                            <span class="text-xs font-medium text-slate-500">MVA</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Row 1b: Rasio Kapasitas Utilitas (MVA/Unit, MVA/Bank, Proporsi SK vs SU) --}}
        @php
            $avgMvaUnit = $trafoOverall['avg_mva_per_unit'] ?? null;
            $avgMvaBank = $trafoOverall['avg_mva_per_bank'] ?? null;
            $suKms = $transmisiOverall['su_kms'] ?? null;
            $skKms = $transmisiOverall['sk_kms'] ?? null;
            $suPct = $transmisiOverall['su_pct'] ?? null;
            $skPct = $transmisiOverall['sk_pct'] ?? null;
            $suBarWidth = $suPct !== null ? max(0.0, min(100.0, (float) $suPct)) : 0.0;
            $skBarWidth = $skPct !== null ? max(0.0, min(100.0, (float) $skPct)) : 0.0;
        @endphp
        <div id="aset-widget-capacity-ratios" class="ibs-card min-w-0 max-w-full p-4 sm:p-5">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3">
                <div class="flex items-center gap-2">
                    <h4 class="ibs-section-title">Rasio Kapasitas Utilitas</h4>
                    <span class="rounded-full bg-[#e6f4f6] px-2.5 py-0.5 text-[11px] font-semibold text-[#0b6175]">UIT JBT</span>
                </div>
                <span class="text-[11px] font-medium text-slate-500">Efisiensi Trafo &amp; Komposisi Saluran</span>
            </div>

            <div class="mt-3.5 grid grid-cols-1 lg:grid-cols-12 gap-4 items-center">
                <div class="lg:col-span-3 rounded-xl border border-slate-100 bg-slate-50/50 px-3.5 py-3">
                    <div class="text-[11px] font-medium text-slate-500">Rata-rata MVA/Unit Trafo</div>
                    <div class="mt-1 flex items-baseline gap-1.5 font-sans tabular-nums">
                        @if ($avgMvaUnit === null)
                            <span class="text-xs font-medium text-slate-400">Tidak tercatat</span>
                        @else
                            <span class="text-lg font-bold text-slate-900">{{ $formatNullable($avgMvaUnit, 1) }}</span>
                            <span class="text-[11px] font-medium text-slate-500">MVA / Unit</span>
                        @endif
                    </div>
                </div>

                <div class="lg:col-span-3 rounded-xl border border-slate-100 bg-slate-50/50 px-3.5 py-3">
                    <div class="text-[11px] font-medium text-slate-500">Rata-rata MVA/Bank</div>
                    <div class="mt-1 flex items-baseline gap-1.5 font-sans tabular-nums">
                        @if ($avgMvaBank === null)
                            <span class="text-xs font-medium text-slate-400">Tidak tercatat</span>
                        @else
                            <span class="text-lg font-bold text-slate-900">{{ $formatNullable($avgMvaBank, 1) }}</span>
                            <span class="text-[11px] font-medium text-slate-500">MVA / Bank</span>
                        @endif
                    </div>
                </div>

                <div class="lg:col-span-6 rounded-xl border border-slate-100 bg-slate-50/50 px-3.5 py-3">
                    <div class="flex flex-wrap items-center justify-between gap-2 text-[11px]">
                        <span class="font-medium text-slate-600">Proporsi Transmisi SK vs SU</span>
                        <div class="flex flex-wrap items-center gap-3 font-sans tabular-nums">
                            <span class="inline-flex items-center gap-1.5 text-slate-700">
                                <span class="h-2 w-2 rounded-full bg-[#0b6175]"></span>
                                <span>SU:</span>
                                @if ($suPct === null)
                                    <span class="text-slate-400">Tidak tercatat</span>
                                @else
                                    <strong class="text-slate-900">{{ $formatNullable($suPct, 1) }}%</strong>
                                    <span class="text-slate-500">({{ $formatNullable($suKms, 1) }} kms)</span>
                                @endif
                            </span>
                            <span class="inline-flex items-center gap-1.5 text-slate-700">
                                <span class="h-2 w-2 rounded-full bg-[#16a3b8]"></span>
                                <span>SK:</span>
                                @if ($skPct === null)
                                    <span class="text-slate-400">Tidak tercatat</span>
                                @else
                                    <strong class="text-slate-900">{{ $formatNullable($skPct, 1) }}%</strong>
                                    <span class="text-slate-500">({{ $formatNullable($skKms, 1) }} kms)</span>
                                @endif
                            </span>
                        </div>
                    </div>
                    <div class="mt-2 h-2 w-full rounded-full bg-slate-200/70 overflow-hidden flex">
                        @if ($suPct !== null || $skPct !== null)
                            <div class="h-full bg-[#0b6175]" style="width: {{ $suBarWidth }}%;"></div>
                            <div class="h-full bg-[#16a3b8]" style="width: {{ $skBarWidth }}%;"></div>
                        @endif
                    </div>
                </div>
            </div>

            @if (!empty($trafoByVoltage) || !empty($transmisiByVoltage))
                <div class="mt-3 flex flex-wrap items-center gap-2 pt-3 border-t border-slate-100">
                    @foreach ($trafoByVoltage as $vRow)
                        <span class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200/80 bg-white px-2.5 py-1 text-[11px] font-sans tabular-nums text-slate-700">
                            <span class="font-semibold text-[#0b6175]">{{ $vRow['voltage'] ?? '' }}</span>
                            @if (($vRow['avg_mva_per_unit'] ?? null) === null)
                                <span class="text-slate-400">Tidak tercatat</span>
                            @else
                                <span class="font-semibold text-slate-900">{{ $formatNullable($vRow['avg_mva_per_unit'], 1) }}</span>
                                <span class="text-slate-500">MVA/Unit</span>
                            @endif
                        </span>
                    @endforeach
                    @foreach ($transmisiByVoltage as $tRow)
                        <span class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200/80 bg-white px-2.5 py-1 text-[11px] font-sans tabular-nums text-slate-700">
                            <span class="font-semibold text-[#16a3b8]">{{ $tRow['voltage'] ?? '' }}</span>
                            @if (($tRow['su_pct'] ?? null) === null && ($tRow['sk_pct'] ?? null) === null)
                                <span class="text-slate-400">Tidak tercatat</span>
                            @else
                                <span class="text-slate-600">SU {{ $formatNullable($tRow['su_pct'] ?? null, 1) }}%</span>
                                <span class="text-slate-300">|</span>
                                <span class="text-slate-600">SK {{ $formatNullable($tRow['sk_pct'] ?? null, 1) }}%</span>
                            @endif
                        </span>
                    @endforeach
                </div>
            @endif
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
                                        <span :class="item.isRecorded ? 'font-semibold text-slate-900' : 'text-slate-400 font-normal'" x-text="item.formatted"></span>
                                        <span class="text-[11px] text-slate-500 w-10 text-right" x-show="item.isRecorded" x-text="item.pct + '%'"></span>
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

        {{-- Row 3: Compact 8 UPT Matrix + Drill-Down Level ULTG --}}
        @if (!empty($tableRows))
            <div class="ibs-card min-w-0 max-w-full overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <h4 class="ibs-section-title">Matriks Fisik UPT</h4>
                        <span class="sr-only">Rekapitulasi Matriks Fisik per UPT</span>
                        <span class="text-[11px] font-medium text-slate-500">Pilih UPT untuk rincian ULTG</span>
                    </div>
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
                                    $uptName = $row['upt'];
                                    $towerRawVal = array_key_exists('tower', $row) ? $row['tower'] : null;
                                    $transmisiRawVal = array_key_exists('transmisi', $row) ? $row['transmisi'] : null;
                                    $trafoRawVal = array_key_exists('trafo', $row) ? $row['trafo'] : null;
                                    $towerVal = $towerRawVal !== null && is_numeric($towerRawVal) ? (float) $towerRawVal : null;
                                    $transmisiVal = $transmisiRawVal !== null && is_numeric($transmisiRawVal) ? (float) $transmisiRawVal : null;
                                    $trafoVal = $trafoRawVal !== null && is_numeric($trafoRawVal) ? (float) $trafoRawVal : null;
                                    $towerPct = ($towerVal !== null && $totalTower !== null && $totalTower > 0) ? min(100, round(($towerVal / $totalTower) * 100, 1)) : 0;
                                    $transmisiPct = ($transmisiVal !== null && $totalTransmisi !== null && $totalTransmisi > 0) ? min(100, round(($transmisiVal / $totalTransmisi) * 100, 1)) : 0;
                                    $trafoPct = ($trafoVal !== null && $totalTrafo !== null && $totalTrafo > 0) ? min(100, round(($trafoVal / $totalTrafo) * 100, 1)) : 0;
                                    $uptUltgCount = count($ultgByUpt[$uptName] ?? []);
                                @endphp
                                <tr class="hover:bg-slate-50/60 transition" :class='selectedDrillUpt === @json($uptName) ? "bg-[#e6f4f6]/35" : ""'>
                                    <td class="py-2.5 px-5 font-semibold text-slate-800">
                                        <button
                                            type="button"
                                            @click='toggleDrillUpt(@json($uptName))'
                                            :aria-expanded='selectedDrillUpt === @json($uptName) ? "true" : "false"'
                                            class="inline-flex items-center gap-2 text-left font-semibold text-slate-800 hover:text-[#0b6175] focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-[#16a3b8] rounded-md cursor-pointer"
                                        >
                                            <span>{{ $uptName }}</span>
                                            @if ($uptUltgCount > 0)
                                                <span class="rounded-md bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-600">
                                                    {{ $uptUltgCount }} ULTG
                                                </span>
                                            @endif
                                        </button>
                                    </td>
                                    <td class="py-2.5 px-5 text-right font-sans tabular-nums text-slate-700">
                                        @if ($towerVal === null)
                                            <span class="text-xs font-normal text-slate-400">Tidak tercatat</span>
                                        @else
                                            <div class="flex items-center justify-end gap-2.5">
                                                <div class="hidden sm:block w-16 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                                                    <div class="h-full rounded-full bg-[#0b6175]" style="width: {{ $towerPct }}%;"></div>
                                                </div>
                                                <span class="min-w-[3.5rem] text-right">{{ number_format($towerVal, 0, ',', '.') }}</span>
                                            </div>
                                        @endif
                                    </td>
                                    <td class="py-2.5 px-5 text-right font-sans tabular-nums text-slate-700">
                                        @if ($transmisiVal === null)
                                            <span class="text-xs font-normal text-slate-400">Tidak tercatat</span>
                                        @else
                                            <div class="flex items-center justify-end gap-2.5">
                                                <div class="hidden sm:block w-16 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                                                    <div class="h-full rounded-full bg-[#16a3b8]" style="width: {{ $transmisiPct }}%;"></div>
                                                </div>
                                                <span class="min-w-[3.5rem] text-right">{{ number_format($transmisiVal, 1, ',', '.') }}</span>
                                            </div>
                                        @endif
                                    </td>
                                    <td class="py-2.5 px-5 text-right font-sans tabular-nums text-slate-700">
                                        @if ($trafoVal === null)
                                            <span class="text-xs font-normal text-slate-400">Tidak tercatat</span>
                                        @else
                                            <div class="flex items-center justify-end gap-2.5">
                                                <div class="hidden sm:block w-16 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                                                    <div class="h-full rounded-full bg-[#52b2bf]" style="width: {{ $trafoPct }}%;"></div>
                                                </div>
                                                <span class="min-w-[4rem] text-right">{{ number_format($trafoVal, 1, ',', '.') }}</span>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t border-slate-200 bg-slate-50/80 font-semibold text-slate-900">
                            <tr>
                                <td class="py-3 px-5 text-left font-bold text-slate-900">TOTAL UIT JBT</td>
                                <td class="py-3 px-5 text-right font-sans tabular-nums font-bold text-slate-900">
                                    @if ($totalTower === null)
                                        <span class="text-xs font-normal text-slate-400">Tidak tercatat</span>
                                    @else
                                        {{ number_format($totalTower, 0, ',', '.') }}
                                    @endif
                                </td>
                                <td class="py-3 px-5 text-right font-sans tabular-nums font-bold text-slate-900">
                                    @if ($totalTransmisi === null)
                                        <span class="text-xs font-normal text-slate-400">Tidak tercatat</span>
                                    @else
                                        {{ number_format($totalTransmisi, 1, ',', '.') }}
                                    @endif
                                </td>
                                <td class="py-3 px-5 text-right font-sans tabular-nums font-bold text-slate-900">
                                    @if ($totalTrafo === null)
                                        <span class="text-xs font-normal text-slate-400">Tidak tercatat</span>
                                    @else
                                        {{ number_format($totalTrafo, 1, ',', '.') }}
                                    @endif
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                {{-- Drill-Down Level ULTG --}}
                <div id="aset-widget-ultg-drilldown" class="border-t border-slate-200/80 bg-slate-50/40 px-5 py-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-2">
                            <h5 class="text-xs font-bold uppercase tracking-wider text-slate-700">Drill-Down Level ULTG</h5>
                            <template x-if="selectedDrillUpt">
                                <span class="rounded-full bg-[#e6f4f6] px-2.5 py-0.5 text-[11px] font-semibold text-[#0b6175]" x-text="'UPT ' + selectedDrillUpt"></span>
                            </template>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5">
                            @foreach ($tableRows as $row)
                                <button
                                    type="button"
                                    @click='toggleDrillUpt(@json($row['upt']))'
                                    :class='selectedDrillUpt === @json($row['upt'])
                                        ? "bg-[#0b6175] text-white border-[#0b6175]"
                                        : "bg-white text-slate-600 border-slate-200 hover:bg-slate-100"'
                                    class="cursor-pointer rounded-lg border px-2.5 py-1 text-[11px] font-semibold transition focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-[#16a3b8]"
                                >
                                    {{ $row['upt'] }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="mt-3" x-show="selectedDrillUpt !== ''" x-cloak>
                        <template x-if="drillUltgRows.length > 0">
                            <div class="overflow-x-auto rounded-xl border border-slate-200/80 bg-white">
                                <table class="w-full text-xs">
                                    <thead class="bg-slate-50/80 text-[11px] font-semibold uppercase tracking-wider text-slate-500 border-b border-slate-100">
                                        <tr>
                                            <th class="py-2.5 px-4 text-left">ULTG</th>
                                            <th class="py-2.5 px-4 text-right">Tower (Unit)</th>
                                            <th class="py-2.5 px-4 text-right">Transmisi (kms)</th>
                                            <th class="py-2.5 px-4 text-right">Trafo (MVA)</th>
                                            <th class="py-2.5 px-4 text-right">Trafo (Bank / Unit)</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <template x-for="uRow in drillUltgRows" :key="uRow.ultg">
                                            <tr class="hover:bg-slate-50/60">
                                                <td class="py-2 px-4 font-semibold text-slate-800" x-text="uRow.ultg"></td>
                                                <td class="py-2 px-4 text-right font-sans tabular-nums" :class="isUnrecorded(uRow.tower) ? 'text-slate-400' : 'text-slate-700'" x-text="formatNullableNum(uRow.tower, 0)"></td>
                                                <td class="py-2 px-4 text-right font-sans tabular-nums" :class="isUnrecorded(uRow.transmisi) ? 'text-slate-400' : 'text-slate-700'" x-text="formatNullableNum(uRow.transmisi, 1)"></td>
                                                <td class="py-2 px-4 text-right font-sans tabular-nums" :class="isUnrecorded(uRow.trafo_mva) ? 'text-slate-400' : 'text-slate-700'" x-text="formatNullableNum(uRow.trafo_mva, 1)"></td>
                                                <td class="py-2 px-4 text-right font-sans tabular-nums text-slate-700">
                                                    <span :class="isUnrecorded(uRow.trafo_bank) ? 'text-slate-400' : 'text-slate-700'" x-text="formatNullableNum(uRow.trafo_bank, 0)"></span>
                                                    <span class="text-slate-300 mx-1">/</span>
                                                    <span :class="isUnrecorded(uRow.trafo_unit) ? 'text-slate-400' : 'text-slate-700'" x-text="formatNullableNum(uRow.trafo_unit, 0)"></span>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                        <template x-if="drillUltgRows.length === 0">
                            <div class="rounded-xl border border-slate-200/80 bg-white px-4 py-4 text-center text-xs text-slate-500">
                                Belum ada data ULTG tercatat untuk UPT ini.
                            </div>
                        </template>
                    </div>
                    <div class="mt-2 text-xs text-slate-500" x-show="selectedDrillUpt === ''">
                        Pilih salah satu UPT di atas untuk menampilkan sebaran aset per ULTG.
                    </div>
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

                const ultgMap = config.ultgByUpt || {};
                const firstPopulatedUpt = Object.keys(ultgMap).find(k => Array.isArray(ultgMap[k]) && ultgMap[k].length > 0) || '';

                return {
                    chartId: chartId,
                    activeCategory: 'tower',
                    selectedDrillUpt: firstPopulatedUpt,

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

                    get drillUltgRows() {
                        if (!this.selectedDrillUpt) return [];
                        const rows = ultgMap[this.selectedDrillUpt];
                        return Array.isArray(rows) ? rows : [];
                    },

                    toggleDrillUpt(upt) {
                        this.selectedDrillUpt = this.selectedDrillUpt === upt ? '' : upt;
                    },

                    isUnrecorded(val) {
                        return val === null || val === undefined || val === '';
                    },

                    formatNullableNum(val, decimals = 1) {
                        if (this.isUnrecorded(val)) return 'Tidak tercatat';
                        const num = Number(val);
                        if (isNaN(num)) return 'Tidak tercatat';
                        return decimals > 0
                            ? num.toLocaleString('id-ID', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })
                            : num.toLocaleString('id-ID');
                    },

                    get rankedActiveRows() {
                        const rows = config.tableRows || [];
                        const cat = this.activeCategory;
                        const rawTotalMap = {
                            tower: config.totalTower,
                            transmisi: config.totalTransmisi,
                            trafo: config.totalTrafo,
                        };
                        const configTotal = rawTotalMap[cat];
                        const hasConfigTotal = configTotal !== null && configTotal !== undefined && !isNaN(Number(configTotal));
                        const rowSum = rows.reduce((sum, r) => {
                            const rv = r[cat];
                            return sum + (rv !== null && rv !== undefined && rv !== '' ? Number(rv) : 0);
                        }, 0);
                        const denom = hasConfigTotal && Number(configTotal) > 0 ? Number(configTotal) : rowSum;

                        const mapped = rows.map(r => {
                            const rawVal = r[cat];
                            const isRecorded = rawVal !== null && rawVal !== undefined && rawVal !== '';
                            const val = isRecorded ? Number(rawVal) : 0;
                            return {
                                upt: r.upt,
                                isRecorded: isRecorded,
                                value: val,
                                formatted: !isRecorded
                                    ? 'Tidak tercatat'
                                    : (cat === 'tower'
                                        ? val.toLocaleString('id-ID')
                                        : val.toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 })),
                                pct: !isRecorded
                                    ? 'Tidak tercatat'
                                    : (denom > 0 ? ((val / denom) * 100).toFixed(1) : '0.0'),
                            };
                        }).sort((a, b) => {
                            if (a.isRecorded !== b.isRecorded) return a.isRecorded ? -1 : 1;
                            return b.value - a.value;
                        });

                        const maxVal = mapped.length > 0 ? Math.max(...mapped.map(i => i.value), 1) : 1;
                        return mapped.map(item => ({
                            ...item,
                            barPct: item.isRecorded ? Math.min(100, Math.round((item.value / maxVal) * 100)) : 0,
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
                        const chartFont = window.PLN_ASET_FONT || "'Geist', 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif";

                        const option = {
                            textStyle: { fontFamily: chartFont },
                            tooltip: {
                                trigger: 'axis',
                                axisPointer: { type: 'shadow' },
                                formatter: function (params) {
                                    if (!params || !params.length) { return ''; }
                                    const uptName = params[0].name;
                                    let html = '<div class="font-bold text-slate-800 border-b border-slate-100 pb-1 mb-1.5 text-xs">UPT ' + uptName + '</div>';
                                    const recordedItems = params.filter(item => item.value !== undefined && item.value !== null && item.value !== '');
                                    if (recordedItems.length === 0) {
                                        html += '<div class="text-xs text-slate-400 py-0.5">Tidak tercatat</div>';
                                    } else {
                                        html += recordedItems.map(item => {
                                            let unit = ' Unit';
                                            let decimals = 0;
                                            if (item.seriesName.includes('kms')) {
                                                unit = ' kms';
                                                decimals = 1;
                                            } else if (item.seriesName.includes('MVA')) {
                                                unit = ' MVA';
                                                decimals = 1;
                                            }
                                            const numVal = Number(item.value);
                                            const formattedValue = decimals > 0
                                                ? numVal.toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 })
                                                : numVal.toLocaleString('id-ID');
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
                                    let raw = null;
                                    if (activeCategory === 'tower') raw = row.tower;
                                    else if (activeCategory === 'transmisi') raw = row.transmisi;
                                    else if (activeCategory === 'trafo') raw = row.trafo;
                                    const isRecorded = raw !== null && raw !== undefined && raw !== '';
                                    const val = isRecorded ? Number(raw) : 0;
                                    return { name: row.upt, value: val, isRecorded: isRecorded };
                                }).sort((a, b) => b.value - a.value);

                                const donutPalette = [
                                    '#0b6175',
                                    '#f59e0b',
                                    '#2563eb',
                                    '#10b981',
                                    '#7c3aed',
                                    '#e11d48',
                                    '#ea580c',
                                    '#64748b',
                                ];

                                const donutOption = {
                                    animation: true,
                                    animationDuration: 650,
                                    textStyle: { fontFamily: chartFont },
                                    tooltip: {
                                        trigger: 'item',
                                        formatter: function (item) {
                                            const isRecorded = item.data && item.data.isRecorded !== false;
                                            if (!isRecorded || item.value === null || item.value === undefined) {
                                                return '<div class="p-1 text-xs font-sans tabular-nums">'
                                                    + '<span class="font-bold text-slate-800">UPT ' + item.name + '</span><br/>'
                                                    + '<span class="text-slate-400">Tidak tercatat</span>'
                                                    + '</div>';
                                            }
                                            const num = Number(item.value);
                                            const val = activeCategory === 'tower'
                                                ? num.toLocaleString('id-ID')
                                                : num.toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
                                            return '<div class="p-1 text-xs font-sans tabular-nums">'
                                                + '<span class="font-bold text-slate-800">UPT ' + item.name + '</span><br/>'
                                                + '<span class="text-slate-600">' + val + ' ' + unit + ' (' + (item.percent ?? 0) + '%)</span>'
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
