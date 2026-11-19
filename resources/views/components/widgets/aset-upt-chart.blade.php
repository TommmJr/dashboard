@props(['widget' => [], 'data' => null])

@php
    $currentUser = auth()->user();
    // Guard otorisasi fail-closed: jika user login dan bukan Admin/Privileged
    $canViewAsetWidget = $currentUser === null
        || $currentUser->hasRole(\App\Models\Role::ADMIN)
        || $currentUser->hasRole(\App\Models\Role::MANAGEMENT)
        || $currentUser->hasRole(\App\Models\Role::EDITOR_DASHBOARD)
        || ($currentUser->hasRole(\App\Models\Role::STAFF_DIVISI) && in_array($currentUser->division?->slug, ['managemen-aset', 'data-aset'], true));
@endphp

@if ($canViewAsetWidget)
@php
    $labels = collect($data['labels'] ?? []);
    $series = collect($data['series'] ?? []);
    $period = $data['period'] ?? null;
    $summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
    $tableRows = is_array($data['table_rows'] ?? null) ? $data['table_rows'] : [];
    $seriesByCategory = is_array($data['series_by_category'] ?? null) ? $data['series_by_category'] : [];
    $freshness = is_array($data['freshness'] ?? null) ? $data['freshness'] : null;
    $availablePeriods = array_values(array_filter(
        is_array($data['available_periods'] ?? null)
            ? $data['available_periods']
            : ($period !== null && $period !== '' ? [$period] : []),
        fn ($p) => is_string($p) && $p !== ''
    ));
    $capacityRatios = is_array($data['capacity_ratios'] ?? null) ? $data['capacity_ratios'] : [];
    $trafoOverall = is_array($capacityRatios['trafo']['overall'] ?? null) ? $capacityRatios['trafo']['overall'] : [];

    $chartSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($widget['key'] ?? 'aset-upt-chart'));
    $chartId = 'asetUptChart-'.$chartSlug.'-'.($widget['instance'] ?? 0);

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

    // Map series menggunakan warna primer PLN_CHART_PALETTE (#0b6175) dan sudut membulat
    $formatSeries = function (iterable $items): array {
        return collect($items)->map(fn ($item) => [
            'name' => $item['name'],
            'type' => 'bar',
            'data' => $item['data'],
            'barMaxWidth' => 34,
            'itemStyle' => [
                'color' => '#0b6175',
                'borderRadius' => [6, 6, 0, 0],
            ],
        ])->values()->all();
    };

    $towerRaw = collect(! empty($seriesByCategory['tower'])
        ? $seriesByCategory['tower']
        : $series->filter(fn ($s) => str_starts_with($s['name'] ?? '', 'Tower '))->values());
    $transmisiRaw = collect(! empty($seriesByCategory['transmisi'])
        ? $seriesByCategory['transmisi']
        : $series->filter(fn ($s) => str_starts_with($s['name'] ?? '', 'Transmisi '))->values());
    $trafoRaw = collect(! empty($seriesByCategory['trafo'])
        ? $seriesByCategory['trafo']
        : $series->filter(fn ($s) => str_starts_with($s['name'] ?? '', 'Trafo '))->values());

    $chartSeriesTower = $formatSeries($towerRaw);
    $chartSeriesTransmisi = $formatSeries($transmisiRaw);
    $trafoMvaRaw = $trafoRaw->filter(fn ($s) => str_contains($s['name'] ?? '', 'MVA'))->values();
    $effectiveTrafoRaw = $trafoMvaRaw->isNotEmpty() ? $trafoMvaRaw : $trafoRaw;
    $chartSeriesTrafo = $formatSeries($effectiveTrafoRaw);
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

    $totalTower = array_key_exists('total_tower', $summary)
        ? ($summary['total_tower'] !== null ? (float) $summary['total_tower'] : null)
        : $sumNullableSeries($towerRaw);
    $totalTransmisi = array_key_exists('total_transmisi', $summary)
        ? ($summary['total_transmisi'] !== null ? (float) $summary['total_transmisi'] : null)
        : $sumNullableSeries($transmisiRaw);
    $totalTrafo = array_key_exists('total_trafo', $summary)
        ? ($summary['total_trafo'] !== null ? (float) $summary['total_trafo'] : null)
        : $sumNullableSeries($effectiveTrafoRaw);

    $trafoBank = array_key_exists('trafo_bank', $summary) && $summary['trafo_bank'] !== null
        ? (float) $summary['trafo_bank']
        : (isset($trafoOverall['bank']) && is_numeric($trafoOverall['bank']) ? (float) $trafoOverall['bank'] : null);
    $trafoUnit = array_key_exists('trafo_unit', $summary) && $summary['trafo_unit'] !== null
        ? (float) $summary['trafo_unit']
        : (isset($trafoOverall['unit']) && is_numeric($trafoOverall['unit']) ? (float) $trafoOverall['unit'] : null);

    // Fallback tableRows dari series bila widget dirender dalam unit test tanpa table_rows
    $effectiveTableRows = $tableRows;
    if (empty($effectiveTableRows) && $labels->isNotEmpty()) {
        $sumAtIndex = function (\Illuminate\Support\Collection $catSeries, int $idx): ?float {
            if ($catSeries->isEmpty()) {
                return null;
            }
            $hasVal = false;
            $acc = 0.0;
            foreach ($catSeries as $s) {
                $val = $s['data'][$idx] ?? null;
                if ($val !== null && is_numeric($val)) {
                    $hasVal = true;
                    $acc += (float) $val;
                }
            }

            return $hasVal ? $acc : null;
        };

        foreach ($labels->values() as $idx => $uptLabel) {
            $effectiveTableRows[] = [
                'upt' => (string) $uptLabel,
                'tower' => $sumAtIndex($towerRaw, $idx),
                'transmisi' => $sumAtIndex($transmisiRaw, $idx),
                'trafo' => $sumAtIndex($effectiveTrafoRaw, $idx),
            ];
        }
    }

    // Susun peringkat SSR per kategori terhadap Total Induk UIT JBT
    $buildRanking = function (string $catKey, ?float $indukTotal, int $decimals) use ($effectiveTableRows): array {
        $rows = collect($effectiveTableRows)->map(function (array $row) use ($catKey): array {
            $raw = $row[$catKey] ?? null;
            $isRecorded = $raw !== null && $raw !== '' && is_numeric($raw);
            $val = $isRecorded ? (float) $raw : null;

            return [
                'upt' => (string) ($row['upt'] ?? ''),
                'is_recorded' => $isRecorded,
                'value' => $val,
            ];
        })->sort(function (array $a, array $b): int {
            if ($a['is_recorded'] !== $b['is_recorded']) {
                return $a['is_recorded'] ? -1 : 1;
            }
            if (($b['value'] ?? 0.0) !== ($a['value'] ?? 0.0)) {
                return ($b['value'] ?? 0.0) <=> ($a['value'] ?? 0.0);
            }

            return strcmp($a['upt'], $b['upt']);
        })->values();

        $rowSum = (float) $rows->sum(fn (array $r): float => $r['value'] ?? 0.0);
        $denom = ($indukTotal !== null && $indukTotal > 0) ? $indukTotal : $rowSum;
        $maxVal = max((float) ($rows->max('value') ?? 0.0), 1.0);

        return $rows->map(function (array $item, int $idx) use ($denom, $maxVal, $decimals): array {
            $isRec = $item['is_recorded'];
            $val = $item['value'] ?? 0.0;
            $shareNum = ($isRec && $denom > 0) ? round(($val / $denom) * 100, 1) : null;
            $barWidth = ($isRec && $maxVal > 0) ? max(2, min(100, (int) round(($val / $maxVal) * 100))) : 0;

            return [
                'rank' => $idx + 1,
                'upt' => $item['upt'],
                'is_recorded' => $isRec,
                'value' => $item['value'],
                'formatted' => $isRec ? number_format($val, $decimals, ',', '.') : 'Tidak tercatat',
                'share_pct' => $shareNum !== null ? number_format($shareNum, 1, ',', '.').'%' : 'Tidak tercatat',
                'share_num' => $shareNum,
                'bar_width' => $barWidth,
            ];
        })->all();
    };

    $rankingsByCategory = [
        'trafo' => $buildRanking('trafo', $totalTrafo, 1),
        'transmisi' => $buildRanking('transmisi', $totalTransmisi, 1),
        'tower' => $buildRanking('tower', $totalTower, 0),
    ];

    $defaultCategory = ! empty($chartSeriesTrafo)
        ? 'trafo'
        : (! empty($chartSeriesTransmisi) ? 'transmisi' : 'tower');

    // Nama sumber dataset yang bersih dan bebas jargon
    $hasDatasetMeta = (bool) ($freshness['has_dataset'] ?? false);
    $rawSourceName = is_string($freshness['source_name'] ?? null) && $freshness['source_name'] !== ''
        ? $freshness['source_name']
        : null;
    $sourceName = $rawSourceName
        ?? (($hasDatasetMeta || ($period !== null && !$labels->isEmpty()))
            ? \App\Services\AssetSheetsSyncService::fallbackDatasetName()
            : null);

    $canAccessDetail = \App\Http\Controllers\AssetDetailController::canAccessAssetDetail($currentUser);
@endphp

<div
    class="space-y-4 min-w-0 max-w-full font-aset"
    @if (! $labels->isEmpty() && ! $series->isEmpty())
        x-data='asetUptChartWidget(@json($chartId))'
        x-init="init()"
    @endif
>
    {{-- Lapis 1: Header Ringkas + Pita 3 Angka Otoritatif Induk UIT JBT --}}
    <div class="ibs-card min-w-0 max-w-full overflow-hidden">
        <div id="aset-executive-header" class="px-5 py-3.5 sm:px-6 sm:py-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0 flex flex-wrap items-center gap-2.5">
                    <h2 class="ibs-section-title text-lg font-bold text-slate-900">Ringkasan Aset UIT JBT</h2>
                    <span class="sr-only">{{ $widget['label'] ?? 'Stok Aset per UPT' }} ({!! 'Unit Induk Transmisi Jawa Bagian Tengah' !!})</span>
                    @if ($period !== null)
                        <span class="sr-only">Periode {{ $period }} ({{ $formatMonthYear($period) }})</span>
                    @endif
                    <span class="inline-flex items-center rounded-md border border-slate-200/80 bg-slate-50 px-2.5 py-0.5 text-[11px] font-semibold text-slate-600">
                        Total Induk UIT JBT
                    </span>
                </div>

                <div class="flex flex-wrap items-center gap-2 sm:shrink-0">
                    @if (! empty($availablePeriods))
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

                    @if ($canAccessDetail)
                        <a
                            id="aset-widget-fullscreen-link"
                            href="{{ route('dashboard.asset-detail', array_filter(['section' => 'trafo', 'period' => $period, 'fullscreen' => 1], fn ($v) => $v !== null && $v !== '')) }}"
                            class="inline-flex w-full sm:w-auto items-center justify-center gap-1.5 rounded-xl bg-[#0b6175] px-3.5 py-2 text-xs font-semibold text-white hover:bg-[#094e5e] transition shadow-2xs whitespace-nowrap focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-[#16a3b8]"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            <span>Buka Layar Penuh</span>
                        </a>
                    @endif
                </div>
            </div>
        </div>

        @if (! $labels->isEmpty() && ! $series->isEmpty())
            <div id="aset-hero-strip" class="border-t border-slate-100 bg-white">
                <div class="grid grid-cols-1 sm:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x divide-slate-100">
                    {{-- Metrik 1: Kapasitas Trafo --}}
                    <div class="px-5 py-4 sm:px-6">
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-[#e6f4f6] text-[#0b6175]">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </div>
                                <span class="ibs-metric-label">Kapasitas Trafo</span>
                            </div>
                            <span class="rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">
                                MVA
                            </span>
                        </div>
                        <div class="mt-2.5 flex flex-wrap items-baseline gap-2">
                            @if ($totalTrafo === null)
                                <span class="text-sm font-medium text-slate-400">Tidak tercatat</span>
                            @else
                                <span class="ibs-metric-value text-2xl tabular-nums" x-data="numberTicker('{{ number_format($totalTrafo, 1, ',', '.') }}')" x-text="display">
                                    {{ number_format($totalTrafo, 1, ',', '.') }}
                                </span>
                                <span class="text-xs font-semibold text-slate-500">MVA</span>
                                @if ($trafoBank !== null || $trafoUnit !== null)
                                    <span class="inline-flex items-center rounded-md bg-[#e6f4f6]/80 px-2 py-0.5 text-[11px] font-semibold text-[#0b6175] tabular-nums">
                                        {{ $trafoBank !== null ? number_format($trafoBank, 0, ',', '.').' Bank' : 'Tidak tercatat' }}
                                        /
                                        {{ $trafoUnit !== null ? number_format($trafoUnit, 0, ',', '.').' Unit' : 'Tidak tercatat' }}
                                    </span>
                                @endif
                            @endif
                        </div>
                    </div>

                    {{-- Metrik 2: Jaringan Transmisi --}}
                    <div class="px-5 py-4 sm:px-6">
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-[#e6f4f6] text-[#0b6175]">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path d="M22 12h-4l-3 9L9 3l-3 9H2" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </div>
                                <span class="ibs-metric-label">Jaringan Transmisi</span>
                            </div>
                            <span class="rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">
                                kms
                            </span>
                        </div>
                        <div class="mt-2.5 flex items-baseline gap-2">
                            @if ($totalTransmisi === null)
                                <span class="text-sm font-medium text-slate-400">Tidak tercatat</span>
                            @else
                                <span class="ibs-metric-value text-2xl tabular-nums" x-data="numberTicker('{{ number_format($totalTransmisi, 1, ',', '.') }}')" x-text="display">
                                    {{ number_format($totalTransmisi, 1, ',', '.') }}
                                </span>
                                <span class="text-xs font-semibold text-slate-500">kms</span>
                            @endif
                        </div>
                    </div>

                    {{-- Metrik 3: Konstruksi Tower --}}
                    <div class="px-5 py-4 sm:px-6">
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-[#e6f4f6] text-[#0b6175]">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path d="M12 2v20M6 8h12M4 14h16M8 22l4-20 4 20" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </div>
                                <span class="ibs-metric-label">Konstruksi Tower</span>
                            </div>
                            <span class="rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">
                                Unit
                            </span>
                        </div>
                        <div class="mt-2.5 flex items-baseline gap-2">
                            @if ($totalTower === null)
                                <span class="text-sm font-medium text-slate-400">Tidak tercatat</span>
                            @else
                                <span class="ibs-metric-value text-2xl tabular-nums" x-data="numberTicker('{{ number_format($totalTower, 0, ',', '.') }}')" x-text="display">
                                    {{ number_format($totalTower, 0, ',', '.') }}
                                </span>
                                <span class="text-xs font-semibold text-slate-500">Unit</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if ($sourceName !== null || is_array($freshness))
            <div id="aset-metadata-footer" class="border-t border-slate-100 bg-slate-50/60 px-5 py-2.5 sm:px-6 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between text-xs text-slate-600">
                <div class="min-w-0 flex flex-wrap items-center gap-2">
                    @if ($sourceName !== null)
                        <span id="aset-source-name" class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-600">
                            <svg class="h-3.5 w-3.5 shrink-0 text-[#0b6175]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke-linecap="round" stroke-linejoin="round" />
                                <path d="M14 2v6h6M8 13h8M8 17h5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            <span>Sumber data: <strong class="font-semibold text-slate-800">{{ $sourceName }}</strong></span>
                        </span>
                    @endif
                </div>

                <x-data-freshness-badge id="aset-freshness-indicator" :freshness="$freshness" class="sm:justify-end" />
            </div>
        @endif
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
                    defaultCategory: @json($defaultCategory),
                    labels: @json($labels->all()),
                    tableRows: @json($effectiveTableRows),
                    rankingsByCategory: @json($rankingsByCategory),
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

        {{-- Lapis 2: 1 Grafik Batang Utama Selebar 100% --}}
        <div id="aset-upt-chart-card" class="ibs-card min-w-0 max-w-full p-5 sm:px-6">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between border-b border-slate-100 pb-3.5">
                <div class="min-w-0 flex flex-wrap items-center gap-x-2.5 gap-y-1">
                    <h3 class="ibs-section-title text-base font-bold text-slate-900">Sebaran 8 Wilayah UPT</h3>
                    <span class="rounded-md bg-[#e6f4f6] px-2 py-0.5 text-[11px] font-semibold text-[#0b6175]" x-text="activeUnit"></span>
                    <span class="text-xs text-slate-500">Unit Pelaksana Transmisi (terurut menurun)</span>
                </div>

                {{-- Pemilih Kategori Satu Baris --}}
                <div class="grid grid-cols-3 sm:inline-flex sm:flex-nowrap w-full sm:w-auto shrink-0 max-w-full items-center gap-1 rounded-xl bg-slate-100/90 p-1 text-[11px] sm:text-xs self-start lg:self-auto" role="tablist" aria-label="Pilih kategori aset">
                    <button
                        type="button"
                        role="tab"
                        data-category-filter="trafo"
                        @click="setCategory('trafo')"
                        :aria-selected="activeCategory === 'trafo' ? 'true' : 'false'"
                        :class="activeCategory === 'trafo'
                            ? 'bg-white text-[#0b6175] font-semibold shadow-2xs'
                            : 'text-slate-600 hover:text-slate-900 font-medium'"
                        class="pill-tab-transition cursor-pointer whitespace-nowrap text-center rounded-lg px-2 sm:px-3 py-1.5 focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-[#16a3b8]"
                    >
                        <span class="sm:hidden">Trafo</span>
                        <span class="hidden sm:inline">Kapasitas Trafo (MVA)</span>
                    </button>
                    <button
                        type="button"
                        role="tab"
                        data-category-filter="transmisi"
                        @click="setCategory('transmisi')"
                        :aria-selected="activeCategory === 'transmisi' ? 'true' : 'false'"
                        :class="activeCategory === 'transmisi'
                            ? 'bg-white text-[#0b6175] font-semibold shadow-2xs'
                            : 'text-slate-600 hover:text-slate-900 font-medium'"
                        class="pill-tab-transition cursor-pointer whitespace-nowrap text-center rounded-lg px-2 sm:px-3 py-1.5 focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-[#16a3b8]"
                    >
                        <span class="sm:hidden">Transmisi</span>
                        <span class="hidden sm:inline">Jaringan Transmisi (kms)</span>
                    </button>
                    <button
                        type="button"
                        role="tab"
                        data-category-filter="tower"
                        @click="setCategory('tower')"
                        :aria-selected="activeCategory === 'tower' ? 'true' : 'false'"
                        :class="activeCategory === 'tower'
                            ? 'bg-white text-[#0b6175] font-semibold shadow-2xs'
                            : 'text-slate-600 hover:text-slate-900 font-medium'"
                        class="pill-tab-transition cursor-pointer whitespace-nowrap text-center rounded-lg px-2 sm:px-3 py-1.5 focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-[#16a3b8]"
                    >
                        <span class="sm:hidden">Tower</span>
                        <span class="hidden sm:inline">Sebaran Tower (Unit)</span>
                    </button>
                </div>
            </div>

            <div class="mt-3.5 min-w-0 max-w-full overflow-hidden relative">
                <div
                    x-show="chartState === 'loading'"
                    class="absolute inset-0 z-10 flex items-center justify-center rounded-xl bg-slate-50/80 animate-pulse"
                    aria-hidden="true"
                >
                    <span class="text-xs font-medium text-slate-500">Memuat visualisasi sebaran UPT...</span>
                </div>

                <div
                    x-show="chartState === 'error'"
                    x-cloak
                    class="flex h-[360px] min-h-[360px] flex-col items-center justify-center gap-2 rounded-xl border border-slate-200 bg-slate-50 p-6 text-center"
                >
                    <p class="text-xs font-semibold text-slate-700">Grafik belum dapat dimuat pada peramban ini.</p>
                    <button
                        type="button"
                        @click="retryChart()"
                        class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100"
                    >
                        Muat Ulang Grafik
                    </button>
                </div>

                <div
                    id="{{ $chartId }}"
                    data-chart-id="aset-upt-chart"
                    x-show="chartState !== 'error'"
                    class="w-full max-w-full overflow-hidden h-[360px] min-h-[360px]"
                    style="width: 100%; height: 360px; min-height: 360px;"
                ></div>
            </div>
        </div>

        <script @cspNonce>
        if (typeof window.asetUptChartWidget !== 'function') {
            window.asetUptChartWidget = function(params) {
                const chartId = typeof params === 'string' ? params : (params ? params.chartId : '');
                const config = (typeof params === 'object' && params !== null && params.labels)
                    ? params
                    : ((window.__asetUptConfigs && window.__asetUptConfigs[chartId]) || {});

                // Instance ECharts dan observer disimpan di closure non-reaktif
                let chartInstance = null;
                let resizeObserver = null;
                let currentOrderedUpts = [];
                let lastCompactViewport = null;

                const extractCleanVoltageLabel = function (rawSeriesName) {
                    const text = String(rawSeriesName || '');
                    const match = text.match(/(\d+(?:\/\d+)?\s*KV)/i);
                    if (match) {
                        const v = match[1].toUpperCase().replace(/\s+/g, ' ');
                        if (text.includes('SK')) return v + ' (SK)';
                        if (text.includes('SU')) return v + ' (SU)';
                        return v;
                    }
                    return text.split('|')[0].trim();
                };

                return {
                    chartId: chartId,
                    activeCategory: config.defaultCategory || 'trafo',
                    chartState: 'loading',

                    get activeUnit() {
                        const unitMap = { trafo: 'MVA', transmisi: 'kms', tower: 'Unit' };
                        return unitMap[this.activeCategory] || 'MVA';
                    },

                    init() {
                        this.$nextTick(() => {
                            this.initChart(0);
                        });
                    },

                    setCategory(cat) {
                        this.activeCategory = cat;
                        this.updateChart();
                    },

                    retryChart() {
                        this.chartState = 'loading';
                        this.initChart(0);
                    },

                    initChart(attempt) {
                        const el = document.getElementById(this.chartId);
                        if (!el) {
                            this.chartState = 'ready';
                            return;
                        }

                        if (!window.echarts && !window.initSafeEChart) {
                            if (attempt > 40) {
                                this.chartState = 'error';
                                return;
                            }
                            setTimeout(() => {
                                this.initChart(attempt + 1);
                            }, 50);
                            return;
                        }

                        try {
                            if (!chartInstance) {
                                if (window.initSafeEChart) {
                                    chartInstance = window.initSafeEChart(el);
                                } else if (window.echarts) {
                                    chartInstance = window.echarts.getInstanceByDom(el) || window.echarts.init(el);
                                }
                            }

                            if (!chartInstance) {
                                this.chartState = 'error';
                                return;
                            }

                            if (typeof ResizeObserver === 'function' && !resizeObserver) {
                                resizeObserver = new ResizeObserver(() => {
                                    if (!chartInstance) return;
                                    const nowCompact = window.innerWidth < 640;
                                    if (lastCompactViewport !== null && nowCompact !== lastCompactViewport) {
                                        this.updateChart();
                                    }
                                    chartInstance.resize();
                                });
                                resizeObserver.observe(el);
                            }

                            this.updateChart();
                            this.chartState = 'ready';
                        } catch (e) {
                            this.chartState = 'error';
                        }
                    },

                    buildVoltageBreakdownForUpt(cat, uptName) {
                        const labels = config.labels || [];
                        const labelIdx = labels.indexOf(uptName);
                        if (labelIdx === -1) return [];
                        const catSeries = (config.seriesByCategory && config.seriesByCategory[cat]) || [];
                        const items = [];
                        for (let i = 0; i < catSeries.length; i++) {
                            const s = catSeries[i];
                            const rawVal = s && Array.isArray(s.data) ? s.data[labelIdx] : null;
                            if (rawVal !== null && rawVal !== undefined && !isNaN(Number(rawVal)) && Number(rawVal) > 0) {
                                items.push({
                                    label: extractCleanVoltageLabel(s.name),
                                    value: Number(rawVal),
                                });
                            }
                        }
                        return items;
                    },

                    updateChart() {
                        const el = document.getElementById(this.chartId);
                        if (!el) return;

                        const chart = chartInstance || (window.initSafeEChart ? window.initSafeEChart(el) : (window.echarts ? (window.echarts.getInstanceByDom(el) || window.echarts.init(el)) : null));
                        if (!chart) return;
                        chartInstance = chart;

                        const p = window.PLN_CHART_PALETTE || {
                            primary: '#0b6175',
                            accent: '#16a3b8',
                            softTeal: '#e6f4f6',
                            targetSlate: '#cbd5e1',
                            gridLine: '#f1f5f9',
                            axisText: '#64748b',
                        };

                        const cat = this.activeCategory;
                        const unit = this.activeUnit;
                        const decimals = cat === 'tower' ? 0 : 1;
                        const rankedRows = (config.rankingsByCategory && config.rankingsByCategory[cat]) || [];
                        const isCompactViewport = window.innerWidth < 640;
                        lastCompactViewport = isCompactViewport;

                        const ordered = isCompactViewport ? [...rankedRows].reverse() : rankedRows;
                        currentOrderedUpts = ordered.map(r => r.upt);

                        const self = this;

                        const barData = ordered.map(r => {
                            return {
                                name: r.upt,
                                value: r.is_recorded && r.value !== null ? Number(r.value) : 0,
                                isRecorded: Boolean(r.is_recorded),
                                formatted: r.formatted,
                                sharePct: r.share_pct,
                                itemStyle: {
                                    color: !r.is_recorded ? '#e2e8f0' : p.primary,
                                    borderRadius: isCompactViewport ? [0, 6, 6, 0] : [6, 6, 0, 0],
                                },
                            };
                        });

                        const chartFont = window.PLN_ASET_FONT || "'Geist', 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif";

                        const categoryAxis = {
                            type: 'category',
                            data: currentOrderedUpts,
                            axisLabel: {
                                interval: 0,
                                color: '#334155',
                                fontSize: 11,
                                fontWeight: 600,
                            },
                            axisLine: {
                                lineStyle: { color: p.targetSlate },
                            },
                            axisTick: { show: false },
                        };

                        const valueAxis = {
                            type: 'value',
                            min: 0,
                            name: isCompactViewport ? '' : unit,
                            splitNumber: isCompactViewport ? 3 : 5,
                            nameTextStyle: {
                                color: p.axisText,
                                fontSize: 11,
                                fontWeight: 600,
                                padding: [0, 0, 4, 0],
                            },
                            axisLabel: {
                                show: !isCompactViewport,
                                hideOverlap: true,
                                color: p.axisText,
                                fontSize: 11,
                            },
                            splitLine: {
                                lineStyle: {
                                    color: p.gridLine,
                                    type: 'dashed',
                                },
                            },
                        };

                        const option = {
                            textStyle: { fontFamily: chartFont },
                            tooltip: {
                                trigger: 'axis',
                                axisPointer: { type: 'shadow' },
                                formatter: function (params) {
                                    if (!params || !params.length) return '';
                                    const item = params[0];
                                    const dataObj = item.data || {};
                                    const uptName = item.name;
                                    let html = '<div class="font-bold text-slate-900 border-b border-slate-100 pb-1.5 mb-1.5 text-xs">UPT ' + uptName + '</div>';

                                    if (!dataObj.isRecorded) {
                                        html += '<div class="text-xs text-slate-400 py-0.5">Tidak tercatat</div>';
                                        return '<div class="p-1 min-w-[210px]">' + html + '</div>';
                                    }

                                    const totalFormatted = decimals > 0
                                        ? Number(dataObj.value).toLocaleString('id-ID', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })
                                        : Number(dataObj.value).toLocaleString('id-ID');

                                    html += '<div class="flex items-center justify-between gap-4 py-0.5 text-xs">'
                                        + '<span class="text-slate-600">Total Wilayah</span>'
                                        + '<span class="font-bold tabular-nums text-slate-900">' + totalFormatted + ' ' + unit + ' (' + dataObj.sharePct + ')</span>'
                                        + '</div>';

                                    const breakdown = self.buildVoltageBreakdownForUpt(cat, uptName);
                                    if (breakdown.length > 0) {
                                        html += '<div class="mt-1.5 pt-1.5 border-t border-slate-100 space-y-0.5">';
                                        for (let i = 0; i < breakdown.length; i++) {
                                            const b = breakdown[i];
                                            const bVal = decimals > 0
                                                ? b.value.toLocaleString('id-ID', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })
                                                : b.value.toLocaleString('id-ID');
                                            html += '<div class="flex items-center justify-between gap-4 text-[11px] text-slate-600">'
                                                + '<span>' + b.label + '</span>'
                                                + '<span class="font-semibold tabular-nums text-slate-800">' + bVal + ' ' + unit + '</span>'
                                                + '</div>';
                                        }
                                        html += '</div>';
                                    }

                                    return '<div class="p-1 min-w-[220px] max-w-xs">' + html + '</div>';
                                }
                            },
                            legend: { show: false },
                            grid: {
                                top: 36,
                                bottom: 24,
                                left: '2%',
                                right: isCompactViewport ? '16%' : '3%',
                                containLabel: true
                            },
                            xAxis: isCompactViewport ? valueAxis : categoryAxis,
                            yAxis: isCompactViewport ? categoryAxis : valueAxis,
                            animation: true,
                            animationDuration: 450,
                            animationEasing: 'cubicOut',
                            series: [{
                                name: unit,
                                type: 'bar',
                                barMaxWidth: 44,
                                data: barData,
                                label: {
                                    show: true,
                                    position: isCompactViewport ? 'right' : 'top',
                                    distance: 6,
                                    color: '#0f172a',
                                    fontSize: isCompactViewport ? 10 : 11,
                                    fontWeight: 600,
                                    fontFamily: chartFont,
                                    formatter: function (params) {
                                        const d = params && params.data ? params.data : {};
                                        if (!d.isRecorded) {
                                            return 'Tidak tercatat';
                                        }
                                        if (d.formatted) {
                                            return d.formatted;
                                        }
                                        return decimals > 0
                                            ? Number(d.value || 0).toLocaleString('id-ID', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })
                                            : Number(d.value || 0).toLocaleString('id-ID');
                                    },
                                },
                                emphasis: {
                                    focus: 'self',
                                    itemStyle: {
                                        color: p.accent,
                                    },
                                },
                            }],
                        };

                        chart.setOption(option, true);
                    }
                };
            };
        }
        </script>
    @endif
</div>
@endif
