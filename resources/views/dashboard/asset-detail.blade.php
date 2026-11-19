@extends('layouts.app')

@section('title', 'Rincian Trafo & Stok Aset')

@section('content')
@php
    $section = $section ?? 'trafo';
    $ownership = $ownership ?? ($data['ownership'] ?? [
        'parent_code' => 'UIT JBT',
        'parent_name' => 'Unit Induk Transmisi Jawa Bagian Tengah (UIT JBT)',
        'parent_source' => 'Rekap Induk UIT (Tab 1a)',
        'distribution_scope' => 'Distribusi Wilayah 8 UPT Kanonis (Tab 1b)',
        'has_uit_totals' => false,
    ]);
    $canonicalUpts = $data['canonical_upts'] ?? [];
    $summary = $data['summary_totals'] ?? [];
    $totalTower = array_key_exists('total_tower', $summary) && $summary['total_tower'] !== null ? (float) $summary['total_tower'] : null;
    $totalTransmisi = array_key_exists('total_transmisi', $summary) && $summary['total_transmisi'] !== null ? (float) $summary['total_transmisi'] : null;
    $totalTrafoMva = array_key_exists('total_trafo_mva', $summary) && $summary['total_trafo_mva'] !== null ? (float) $summary['total_trafo_mva'] : null;
    $totalTrafoBank = array_key_exists('total_trafo_bank', $summary) && $summary['total_trafo_bank'] !== null ? (float) $summary['total_trafo_bank'] : null;
    $totalTrafoUnit = array_key_exists('total_trafo_unit', $summary) && $summary['total_trafo_unit'] !== null ? (float) $summary['total_trafo_unit'] : null;
    $trafoGroups = $data['trafo_groups'] ?? [];
    $transmisiGroups = $data['transmisi_groups'] ?? [];
    $towerGroups = $data['tower_groups'] ?? [];
    $bayGroups = $data['bay_groups'] ?? [];
    $chartConfig = $data['chart_config'] ?? [];
    $interactiveMatrix = $data['interactive_matrix'] ?? [];
    $freshness = is_array($data['freshness'] ?? null) ? $data['freshness'] : null;

    $selectedPeriod = !empty($data['period']) ? (string) $data['period'] : null;
    $availablePeriods = array_values(array_filter(
        is_array($data['available_periods'] ?? null)
            ? $data['available_periods']
            : ($selectedPeriod !== null ? [$selectedPeriod] : []),
        fn ($p) => is_string($p) && $p !== ''
    ));

    $ultgByUpt = is_array($data['ultg_by_upt'] ?? null) ? $data['ultg_by_upt'] : [];
    $capacityRatios = is_array($data['capacity_ratios'] ?? null) ? $data['capacity_ratios'] : [];
    $trafoRatios = is_array($capacityRatios['trafo'] ?? null) ? $capacityRatios['trafo'] : [];
    $transmisiRatios = is_array($capacityRatios['transmisi'] ?? null) ? $capacityRatios['transmisi'] : [];
    $trafoOverall = is_array($trafoRatios['overall'] ?? null) ? $trafoRatios['overall'] : [];
    $transmisiOverall = is_array($transmisiRatios['overall'] ?? null) ? $transmisiRatios['overall'] : [];
    $trafoRatioByVoltage = is_array($trafoRatios['by_voltage'] ?? null) ? $trafoRatios['by_voltage'] : [];
    $transmisiRatioByVoltage = is_array($transmisiRatios['by_voltage'] ?? null) ? $transmisiRatios['by_voltage'] : [];
    $trafoRatioByUpt = is_array($trafoRatios['by_upt'] ?? null) ? $trafoRatios['by_upt'] : [];
    $transmisiRatioByUpt = is_array($transmisiRatios['by_upt'] ?? null) ? $transmisiRatios['by_upt'] : [];

    $formatPeriodLabel = function (?string $rawDate): string {
        if (! $rawDate) {
            return 'Tidak tercatat';
        }
        try {
            $months = [
                1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
                7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
            ];
            $dt = \Carbon\Carbon::parse($rawDate);
            return ($months[$dt->month] ?? $dt->format('M')).' '.$dt->year;
        } catch (\Throwable $e) {
            return 'Tidak tercatat';
        }
    };

    $formattedPeriod = $formatPeriodLabel($selectedPeriod);

    $formatNullable = function (mixed $val, int $decimals = 1): string {
        if ($val === null || $val === '' || ! is_numeric($val)) {
            return 'Tidak tercatat';
        }
        return number_format((float) $val, $decimals, ',', '.');
    };

    $sumNullable = function (iterable $values, int $precision = 1): ?float {
        $hasValue = false;
        $sum = 0.0;
        foreach ($values as $v) {
            if ($v !== null && $v !== '' && is_numeric($v)) {
                $hasValue = true;
                $sum += (float) $v;
            }
        }
        return $hasValue ? round($sum, $precision) : null;
    };

    $sectionRouteParams = fn (string $sec): array => $selectedPeriod !== null
        ? ['section' => $sec, 'period' => $selectedPeriod]
        : ['section' => $sec];

    // Helper kalkulasi daya MVA per UPT (null-preserving)
    $mvaByUpt = [];
    foreach ($canonicalUpts as $upt) {
        $vals = [];
        foreach ($trafoGroups as $tg) {
            $vals[] = $tg['by_upt'][$upt]['mva'] ?? null;
        }
        $mvaByUpt[$upt] = $sumNullable($vals, 1);
    }
    $rankedMva = $mvaByUpt;
    uasort($rankedMva, fn ($a, $b) => ($b ?? -INF) <=> ($a ?? -INF));

    // Helper kalkulasi fisik trafo (null-preserving)
    $bankByUpt = [];
    $unitByUpt = [];
    foreach ($canonicalUpts as $upt) {
        $bankVals = [];
        $unitVals = [];
        foreach ($trafoGroups as $tg) {
            $bankVals[] = $tg['by_upt'][$upt]['bank'] ?? null;
            $unitVals[] = $tg['by_upt'][$upt]['unit'] ?? null;
        }
        $bankByUpt[$upt] = $sumNullable($bankVals, 0);
        $unitByUpt[$upt] = $sumNullable($unitVals, 0);
    }

    // Helper kalkulasi transmisi (null-preserving)
    $transmisiByUpt = [];
    foreach ($canonicalUpts as $upt) {
        $suVals = [];
        $skVals = [];
        foreach ($transmisiGroups as $tr) {
            $suVals[] = $tr['by_upt'][$upt]['su_kms'] ?? null;
            $skVals[] = $tr['by_upt'][$upt]['sk_kms'] ?? null;
        }
        $su = $sumNullable($suVals, 1);
        $sk = $sumNullable($skVals, 1);
        $transmisiByUpt[$upt] = [
            'su_kms' => $su,
            'sk_kms' => $sk,
            'total_kms' => ($su === null && $sk === null) ? null : round(($su ?? 0.0) + ($sk ?? 0.0), 1),
        ];
    }

    // Helper kalkulasi tower (null-preserving)
    $towerByUpt = [];
    foreach ($canonicalUpts as $upt) {
        $totVals = [];
        foreach ($towerGroups as $tw) {
            $totVals[] = $tw['by_upt'][$upt]['total_unit'] ?? null;
        }
        $towerByUpt[$upt] = $sumNullable($totVals, 0);
    }

    $fullscreenStudioPayload = [
        'period' => $selectedPeriod,
        'formatted_period' => $formattedPeriod,
        'ownership' => $ownership,
        'summary_totals' => [
            'total_trafo_mva' => $totalTrafoMva,
            'total_trafo_bank' => $totalTrafoBank,
            'total_trafo_unit' => $totalTrafoUnit,
            'total_transmisi' => $totalTransmisi,
            'total_tower' => $totalTower,
        ],
        'canonical_upts' => $canonicalUpts,
        'trafo_groups' => $trafoGroups,
        'transmisi_groups' => $transmisiGroups,
        'tower_groups' => $towerGroups,
        'ultg_by_upt' => $ultgByUpt,
        'capacity_ratios' => $capacityRatios,
        'interactive_matrix' => $interactiveMatrix,
        'mva_by_upt' => $mvaByUpt,
        'bank_by_upt' => $bankByUpt,
        'unit_by_upt' => $unitByUpt,
        'transmisi_by_upt' => $transmisiByUpt,
        'tower_by_upt' => $towerByUpt,
    ];
@endphp

<div class="space-y-5 min-w-0 max-w-full">
    <x-asset-fullscreen-studio :payload="$fullscreenStudioPayload" :initial-section="$section" />

    {{-- Header Halaman Ringkas --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex min-w-0 flex-col gap-1.5">
            <div class="flex flex-wrap items-center gap-2.5">
                <h1 class="text-2xl font-bold tracking-tight text-slate-900">Rincian Trafo & Stok Aset</h1>
                <span class="inline-flex items-center rounded-full bg-slate-100 border border-slate-200/80 px-2.5 py-0.5 text-xs font-medium text-slate-600">
                    Prototipe Demo
                </span>
                <span class="inline-flex items-center rounded-full bg-[#e6f4f6] px-2.5 py-0.5 text-xs font-semibold text-[#0b6175]">
                    UIT JBT
                </span>
                @if ($selectedPeriod !== null)
                    <span class="inline-flex items-center rounded-full bg-white border border-slate-200/80 px-2.5 py-0.5 text-xs font-medium text-slate-600">
                        {{ $formattedPeriod }}
                    </span>
                    <span class="inline-flex items-center rounded-full border border-slate-200/80 bg-white px-2.5 py-0.5 text-xs font-medium text-slate-600">
                        Tab 1a &amp; Tab 1b
                    </span>
                @endif
            </div>
            <x-data-freshness-badge id="asset-detail-freshness-indicator" :freshness="$freshness" />
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if (!empty($availablePeriods))
                <form method="GET" action="{{ route('dashboard.asset-detail', ['section' => $section]) }}" class="flex items-center">
                    <label for="asset-detail-period-select" class="sr-only">Pilih periode historis aset</label>
                    <select
                        id="asset-detail-period-select"
                        name="period"
                        data-auto-submit
                        aria-label="Pilih periode historis aset"
                        class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 shadow-2xs focus:border-[#0b6175] focus:outline-hidden"
                    >
                        @foreach ($availablePeriods as $pOption)
                            <option value="{{ $pOption }}" @selected($pOption === $selectedPeriod)>
                                {{ $formatPeriodLabel($pOption) }}
                            </option>
                        @endforeach
                    </select>
                </form>
            @endif
            <a href="{{ $selectedPeriod !== null ? route('dashboard', ['period' => $selectedPeriod]) : route('dashboard') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition shadow-2xs">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M19 12H5M12 19l-7-7 7-7" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                Kembali ke Beranda
            </a>
            <button
                type="button"
                id="asset-detail-fullscreen-btn"
                @click="$dispatch('open-asset-fullscreen', { tab: @js($section) })"
                class="inline-flex items-center gap-1.5 rounded-xl bg-[#0b6175] px-4 py-2 text-xs font-semibold text-white shadow-xs hover:bg-[#094e5e] transition cursor-pointer whitespace-nowrap focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-[#16a3b8]"
                title="Buka Mode Layar Penuh Eksplorasi Aset UIT JBT"
            >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <span>Layar Penuh</span>
            </button>
        </div>
    </div>

    {{-- Kontrak Teks Aksesibilitas & Audit Hirarki --}}
    <span class="sr-only">
        Kepemilikan Aset UIT JBT
        {{ $ownership['parent_name'] }}
        Rekapitulasi Kepemilikan Aset Induk UIT JBT (Tab 1a)
        Distribusi Wilayah Aset UIT JBT
        Distribusi Wilayah Aset UIT JBT pada 8 UPT (Tab 1b)
        {{ $ownership['parent_source'] }}
        {{ $ownership['distribution_scope'] }}
        @if ($selectedPeriod !== null) Periode {{ $selectedPeriod }} @endif
    </span>

    {{-- Toolbar Tunggal: 4 Navigasi Seksi --}}
    <div class="ibs-card min-w-0 max-w-full p-3.5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-1.5">
                <a href="{{ route('dashboard.asset-detail', $sectionRouteParams('trafo')) }}"
                   class="pill-tab-transition inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-semibold {{ $section === 'trafo' ? 'bg-[#0b6175] text-white shadow-2xs' : 'bg-slate-100/80 text-slate-700 hover:bg-slate-200/70' }}">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <rect x="3" y="6" width="18" height="12" rx="2" />
                        <path d="M7 12h2m6 0h2M12 9v6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    Bedah Trafo & Tegangan
                </a>
                <a href="{{ route('dashboard.asset-detail', $sectionRouteParams('mva')) }}"
                   class="pill-tab-transition inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-semibold {{ $section === 'mva' ? 'bg-[#0b6175] text-white shadow-2xs' : 'bg-slate-100/80 text-slate-700 hover:bg-slate-200/70' }}">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    Distribusi Daya (MVA)
                </a>
                <a href="{{ route('dashboard.asset-detail', $sectionRouteParams('transmisi')) }}"
                   class="pill-tab-transition inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-semibold {{ $section === 'transmisi' ? 'bg-[#0b6175] text-white shadow-2xs' : 'bg-slate-100/80 text-slate-700 hover:bg-slate-200/70' }}">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M22 12h-4l-3 9L9 3l-3 9H2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    Jaringan Transmisi (kms)
                </a>
                <a href="{{ route('dashboard.asset-detail', $sectionRouteParams('tower')) }}"
                   class="pill-tab-transition inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-semibold {{ $section === 'tower' ? 'bg-[#0b6175] text-white shadow-2xs' : 'bg-slate-100/80 text-slate-700 hover:bg-slate-200/70' }}">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M12 2v20M6 8h12M4 14h16M8 22l4-20 4 20" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    Sebaran Tower (Unit)
                </a>
            </div>
        </div>
    </div>

    {{-- Unified Summary Strip (4 Metrik Induk UIT JBT dalam 1 .ibs-card) --}}
    <div class="ibs-card min-w-0 max-w-full overflow-hidden">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 divide-y sm:divide-y-0 sm:divide-x divide-slate-100">
            {{-- Col 1: Total Kapasitas Trafo --}}
            <div class="p-5">
                <div class="flex items-center justify-between gap-2">
                    <span class="ibs-metric-label">Total Kapasitas Trafo</span>
                    <span class="rounded-md bg-[#e6f4f6] px-2 py-0.5 text-[11px] font-semibold text-[#0b6175]">MVA</span>
                </div>
                <div class="mt-2.5 flex items-baseline gap-1.5">
                    @if ($totalTrafoMva === null)
                        <span class="text-sm font-medium text-slate-400">Tidak tercatat</span>
                    @else
                        <span class="ibs-metric-value text-2xl sm:text-3xl" x-data="numberTicker('{{ number_format($totalTrafoMva, 1, ',', '.') }}')" x-text="display">
                            {{ number_format($totalTrafoMva, 1, ',', '.') }}
                        </span>
                        <span class="text-xs font-medium text-slate-500">MVA</span>
                    @endif
                </div>
            </div>

            {{-- Col 2: Fisik Trafo Terpasang --}}
            <div class="p-5">
                <div class="flex items-center justify-between gap-2">
                    <span class="ibs-metric-label">Fisik Trafo Terpasang</span>
                    <span class="rounded-md bg-[#e6f4f6] px-2 py-0.5 text-[11px] font-semibold text-[#0b6175]">Bank / Unit</span>
                </div>
                <div class="mt-2.5 flex flex-wrap items-baseline gap-1.5">
                    @if ($totalTrafoBank === null && $totalTrafoUnit === null)
                        <span class="text-sm font-medium text-slate-400">Tidak tercatat</span>
                    @else
                        @if ($totalTrafoBank === null)
                            <span class="text-xs font-medium text-slate-400">Tidak tercatat</span>
                        @else
                            <span class="ibs-metric-value text-2xl" x-data="numberTicker('{{ number_format($totalTrafoBank, 0, ',', '.') }}')" x-text="display">
                                {{ number_format($totalTrafoBank, 0, ',', '.') }}
                            </span>
                            <span class="text-xs font-medium text-slate-500">Bank</span>
                        @endif
                        <span class="text-slate-300">/</span>
                        @if ($totalTrafoUnit === null)
                            <span class="text-xs font-medium text-slate-400">Tidak tercatat</span>
                        @else
                            <span class="ibs-metric-value text-2xl" x-data="numberTicker('{{ number_format($totalTrafoUnit, 0, ',', '.') }}')" x-text="display">
                                {{ number_format($totalTrafoUnit, 0, ',', '.') }}
                            </span>
                            <span class="text-xs font-medium text-slate-500">Unit</span>
                        @endif
                    @endif
                </div>
            </div>

            {{-- Col 3: Panjang Transmisi --}}
            <div class="p-5">
                <div class="flex items-center justify-between gap-2">
                    <span class="ibs-metric-label">Panjang Transmisi</span>
                    <span class="rounded-md bg-[#e6f4f6] px-2 py-0.5 text-[11px] font-semibold text-[#0b6175]">kms</span>
                </div>
                <div class="mt-2.5 flex items-baseline gap-1.5">
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

            {{-- Col 4: Total Tower --}}
            <div class="p-5">
                <div class="flex items-center justify-between gap-2">
                    <span class="ibs-metric-label">Total Tower</span>
                    <span class="rounded-md bg-[#e6f4f6] px-2 py-0.5 text-[11px] font-semibold text-[#0b6175]">Unit</span>
                </div>
                <div class="mt-2.5 flex items-baseline gap-1.5">
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
        </div>
    </div>

    {{-- Panel Rasio Kapasitas Peralatan --}}
    @php
        $ovAvgUnit = $trafoOverall['avg_mva_per_unit'] ?? null;
        $ovAvgBank = $trafoOverall['avg_mva_per_bank'] ?? null;
        $ovTrafoMva = $trafoOverall['mva'] ?? null;
        $ovTrafoUnit = $trafoOverall['unit'] ?? null;
        $ovTrafoBank = $trafoOverall['bank'] ?? null;
        $ovSuKms = $transmisiOverall['su_kms'] ?? null;
        $ovSkKms = $transmisiOverall['sk_kms'] ?? null;
        $ovTotKms = $transmisiOverall['total_kms'] ?? null;
        $ovSuPct = $transmisiOverall['su_pct'] ?? null;
        $ovSkPct = $transmisiOverall['sk_pct'] ?? null;
        $ovSuWidth = $ovSuPct !== null ? max(0.0, min(100.0, (float) $ovSuPct)) : 0.0;
        $ovSkWidth = $ovSkPct !== null ? max(0.0, min(100.0, (float) $ovSkPct)) : 0.0;
    @endphp
    <div id="asset-capacity-ratios-panel" class="ibs-card min-w-0 max-w-full p-4 sm:p-5" x-data="{ ratioTab: 'voltage' }">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-b border-slate-100 pb-3.5">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="ibs-section-title text-base font-bold text-slate-900">Rasio Kapasitas Peralatan</h2>
                <span class="rounded-full bg-[#e6f4f6] px-2.5 py-0.5 text-[11px] font-semibold text-[#0b6175]">UIT JBT</span>
            </div>
            <div class="inline-flex items-center rounded-xl bg-slate-100/90 p-1 text-xs self-start sm:self-auto">
                <button
                    type="button"
                    @click="ratioTab = 'voltage'"
                    :class="ratioTab === 'voltage' ? 'bg-white text-[#0b6175] font-semibold shadow-2xs' : 'text-slate-600 hover:text-slate-900 font-medium'"
                    class="pill-tab-transition cursor-pointer rounded-lg px-3 py-1"
                >
                    Per Kelas Tegangan
                </button>
                <button
                    type="button"
                    @click="ratioTab = 'upt'"
                    :class="ratioTab === 'upt' ? 'bg-white text-[#0b6175] font-semibold shadow-2xs' : 'text-slate-600 hover:text-slate-900 font-medium'"
                    class="pill-tab-transition cursor-pointer rounded-lg px-3 py-1"
                >
                    Per UPT (8 UPT)
                </button>
            </div>
        </div>

        {{-- Ringkasan UIT JBT --}}
        <div class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="rounded-xl border border-slate-200/80 bg-slate-50/50 p-4">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-xs font-semibold text-slate-700">Rata-rata MVA/Unit &amp; MVA/Bank Trafo</span>
                    <span class="rounded-md bg-white border border-slate-200/80 px-2 py-0.5 text-[11px] font-semibold text-slate-600">Induk UIT JBT</span>
                </div>
                <div class="mt-3 grid grid-cols-2 gap-3 font-sans tabular-nums">
                    <div>
                        <div class="text-[11px] text-slate-500">MVA per Unit</div>
                        @if ($ovAvgUnit === null)
                            <div class="mt-0.5 text-sm font-normal text-slate-400">Tidak tercatat</div>
                        @else
                            <div class="mt-0.5 text-xl font-bold text-slate-900">{{ $formatNullable($ovAvgUnit, 1) }} <span class="text-xs font-medium text-slate-500">MVA/Unit</span></div>
                        @endif
                        <div class="mt-0.5 text-[11px] text-slate-500">
                            Basis: {{ $ovTrafoMva === null ? 'Tidak tercatat' : $formatNullable($ovTrafoMva, 1).' MVA' }} / {{ $ovTrafoUnit === null ? 'Tidak tercatat' : $formatNullable($ovTrafoUnit, 0).' Unit' }}
                        </div>
                    </div>
                    <div>
                        <div class="text-[11px] text-slate-500">MVA per Bank</div>
                        @if ($ovAvgBank === null)
                            <div class="mt-0.5 text-sm font-normal text-slate-400">Tidak tercatat</div>
                        @else
                            <div class="mt-0.5 text-xl font-bold text-slate-900">{{ $formatNullable($ovAvgBank, 1) }} <span class="text-xs font-medium text-slate-500">MVA/Bank</span></div>
                        @endif
                        <div class="mt-0.5 text-[11px] text-slate-500">
                            Basis: {{ $ovTrafoBank === null ? 'Tidak tercatat' : $formatNullable($ovTrafoBank, 0).' Bank' }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-slate-200/80 bg-slate-50/50 p-4 flex flex-col justify-between">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-xs font-semibold text-slate-700">Proporsi Kabel SK vs Udara SU</span>
                    <span class="rounded-md bg-white border border-slate-200/80 px-2 py-0.5 text-[11px] font-semibold font-sans tabular-nums text-slate-600">
                        {{ $ovTotKms === null ? 'Tidak tercatat' : $formatNullable($ovTotKms, 1).' kms' }}
                    </span>
                </div>
                <div class="mt-3 space-y-2.5">
                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs font-sans tabular-nums">
                        <span class="inline-flex items-center gap-1.5 text-slate-700">
                            <span class="h-2.5 w-2.5 rounded-full bg-[#0b6175]"></span>
                            <span>Saluran Udara (SU):</span>
                            @if ($ovSuPct === null)
                                <span class="text-slate-400">Tidak tercatat</span>
                            @else
                                <strong class="text-slate-900">{{ $formatNullable($ovSuPct, 1) }}%</strong>
                                <span class="text-slate-500">({{ $formatNullable($ovSuKms, 1) }} kms)</span>
                            @endif
                        </span>
                        <span class="inline-flex items-center gap-1.5 text-slate-700">
                            <span class="h-2.5 w-2.5 rounded-full bg-[#16a3b8]"></span>
                            <span>Saluran Kabel (SK):</span>
                            @if ($ovSkPct === null)
                                <span class="text-slate-400">Tidak tercatat</span>
                            @else
                                <strong class="text-slate-900">{{ $formatNullable($ovSkPct, 1) }}%</strong>
                                <span class="text-slate-500">({{ $formatNullable($ovSkKms, 1) }} kms)</span>
                            @endif
                        </span>
                    </div>
                    <div class="h-2.5 w-full rounded-full bg-slate-200/70 overflow-hidden flex">
                        @if ($ovSuPct !== null || $ovSkPct !== null)
                            <div class="h-full bg-[#0b6175]" style="width: {{ $ovSuWidth }}%;"></div>
                            <div class="h-full bg-[#16a3b8]" style="width: {{ $ovSkWidth }}%;"></div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Tab 1: Per Kelas Tegangan --}}
        <div class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-4" x-show="ratioTab === 'voltage'">
            <div class="rounded-xl border border-slate-200/80 overflow-hidden">
                <div class="bg-slate-50/70 px-4 py-2.5 border-b border-slate-200/80 text-xs font-semibold text-slate-700">
                    Rasio Kapasitas Trafo per Tegangan
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-white text-slate-400 uppercase tracking-wider font-semibold border-b border-slate-100">
                            <tr>
                                <th class="py-2 px-3 text-left">Tegangan</th>
                                <th class="py-2 px-3 text-right">MVA</th>
                                <th class="py-2 px-3 text-right">Unit</th>
                                <th class="py-2 px-3 text-right">MVA/Unit</th>
                                <th class="py-2 px-3 text-right">MVA/Bank</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            @forelse ($trafoRatioByVoltage as $vItem)
                                <tr class="hover:bg-slate-50/50">
                                    <td class="py-2 px-3 font-semibold text-slate-800">{{ $vItem['voltage'] ?? $vItem['label'] ?? '' }}</td>
                                    <td class="py-2 px-3 text-right font-sans tabular-nums text-slate-700">
                                        @if (($vItem['mva'] ?? null) === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ $formatNullable($vItem['mva'], 1) }} @endif
                                    </td>
                                    <td class="py-2 px-3 text-right font-sans tabular-nums text-slate-700">
                                        @if (($vItem['unit'] ?? null) === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ $formatNullable($vItem['unit'], 0) }} @endif
                                    </td>
                                    <td class="py-2 px-3 text-right font-sans tabular-nums font-semibold text-[#0b6175]">
                                        @if (($vItem['avg_mva_per_unit'] ?? null) === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ $formatNullable($vItem['avg_mva_per_unit'], 1) }} @endif
                                    </td>
                                    <td class="py-2 px-3 text-right font-sans tabular-nums font-semibold text-slate-800">
                                        @if (($vItem['avg_mva_per_bank'] ?? null) === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ $formatNullable($vItem['avg_mva_per_bank'], 1) }} @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-4 px-3 text-center text-slate-400">Belum ada data rasio trafo per tegangan.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-xl border border-slate-200/80 overflow-hidden">
                <div class="bg-slate-50/70 px-4 py-2.5 border-b border-slate-200/80 text-xs font-semibold text-slate-700">
                    Proporsi SU vs SK per Tegangan
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-white text-slate-400 uppercase tracking-wider font-semibold border-b border-slate-100">
                            <tr>
                                <th class="py-2 px-3 text-left">Tegangan</th>
                                <th class="py-2 px-3 text-right">SU (kms)</th>
                                <th class="py-2 px-3 text-right">SK (kms)</th>
                                <th class="py-2 px-3 text-right">Proporsi SU / SK</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            @forelse ($transmisiRatioByVoltage as $tItem)
                                @php
                                    $rSuPct = $tItem['su_pct'] ?? null;
                                    $rSkPct = $tItem['sk_pct'] ?? null;
                                @endphp
                                <tr class="hover:bg-slate-50/50">
                                    <td class="py-2 px-3 font-semibold text-slate-800">{{ $tItem['voltage'] ?? $tItem['label'] ?? '' }}</td>
                                    <td class="py-2 px-3 text-right font-sans tabular-nums text-slate-700">
                                        @if (($tItem['su_kms'] ?? null) === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ $formatNullable($tItem['su_kms'], 1) }} @endif
                                    </td>
                                    <td class="py-2 px-3 text-right font-sans tabular-nums text-slate-700">
                                        @if (($tItem['sk_kms'] ?? null) === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ $formatNullable($tItem['sk_kms'], 1) }} @endif
                                    </td>
                                    <td class="py-2 px-3 text-right font-sans tabular-nums">
                                        @if ($rSuPct === null && $rSkPct === null)
                                            <span class="text-slate-400 font-normal">Tidak tercatat</span>
                                        @else
                                            <div class="flex items-center justify-end gap-2">
                                                <div class="hidden sm:flex w-16 h-1.5 rounded-full bg-slate-200/70 overflow-hidden">
                                                    <div class="h-full bg-[#0b6175]" style="width: {{ max(0.0, min(100.0, (float) ($rSuPct ?? 0))) }}%;"></div>
                                                    <div class="h-full bg-[#16a3b8]" style="width: {{ max(0.0, min(100.0, (float) ($rSkPct ?? 0))) }}%;"></div>
                                                </div>
                                                <span class="text-slate-700">{{ $formatNullable($rSuPct, 1) }}% / {{ $formatNullable($rSkPct, 1) }}%</span>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="py-4 px-3 text-center text-slate-400">Belum ada data rasio transmisi per tegangan.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Tab 2: Per UPT --}}
        <div class="mt-4 overflow-x-auto rounded-xl border border-slate-200/80" x-show="ratioTab === 'upt'" x-cloak>
            <table class="w-full text-xs">
                <thead class="bg-slate-50/70 text-slate-500 uppercase tracking-wider font-semibold border-b border-slate-200/80">
                    <tr>
                        <th class="py-2.5 px-4 text-left">UPT</th>
                        <th class="py-2.5 px-4 text-right">Trafo (MVA)</th>
                        <th class="py-2.5 px-4 text-right">Trafo (Unit)</th>
                        <th class="py-2.5 px-4 text-right">MVA / Unit</th>
                        <th class="py-2.5 px-4 text-right">SU (kms)</th>
                        <th class="py-2.5 px-4 text-right">SK (kms)</th>
                        <th class="py-2.5 px-4 text-right">Proporsi SU / SK</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @forelse ($canonicalUpts as $upt)
                        @php
                            $uTrafo = $trafoRatioByUpt[$upt] ?? [];
                            $uTrans = $transmisiRatioByUpt[$upt] ?? [];
                            $uSuPct = $uTrans['su_pct'] ?? null;
                            $uSkPct = $uTrans['sk_pct'] ?? null;
                        @endphp
                        <tr class="hover:bg-slate-50/60">
                            <td class="py-2 px-4 font-semibold text-slate-800">{{ $upt }}</td>
                            <td class="py-2 px-4 text-right font-sans tabular-nums text-slate-700">
                                @if (($uTrafo['mva'] ?? null) === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ $formatNullable($uTrafo['mva'], 1) }} @endif
                            </td>
                            <td class="py-2 px-4 text-right font-sans tabular-nums text-slate-700">
                                @if (($uTrafo['unit'] ?? null) === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ $formatNullable($uTrafo['unit'], 0) }} @endif
                            </td>
                            <td class="py-2 px-4 text-right font-sans tabular-nums font-semibold text-[#0b6175]">
                                @if (($uTrafo['avg_mva_per_unit'] ?? null) === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ $formatNullable($uTrafo['avg_mva_per_unit'], 1) }} @endif
                            </td>
                            <td class="py-2 px-4 text-right font-sans tabular-nums text-slate-700">
                                @if (($uTrans['su_kms'] ?? null) === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ $formatNullable($uTrans['su_kms'], 1) }} @endif
                            </td>
                            <td class="py-2 px-4 text-right font-sans tabular-nums text-slate-700">
                                @if (($uTrans['sk_kms'] ?? null) === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ $formatNullable($uTrans['sk_kms'], 1) }} @endif
                            </td>
                            <td class="py-2 px-4 text-right font-sans tabular-nums">
                                @if ($uSuPct === null && $uSkPct === null)
                                    <span class="text-slate-400 font-normal">Tidak tercatat</span>
                                @else
                                    <div class="flex items-center justify-end gap-2">
                                        <div class="hidden sm:flex w-16 h-1.5 rounded-full bg-slate-200/70 overflow-hidden">
                                            <div class="h-full bg-[#0b6175]" style="width: {{ max(0.0, min(100.0, (float) ($uSuPct ?? 0))) }}%;"></div>
                                            <div class="h-full bg-[#16a3b8]" style="width: {{ max(0.0, min(100.0, (float) ($uSkPct ?? 0))) }}%;"></div>
                                        </div>
                                        <span class="text-slate-700">{{ $formatNullable($uSuPct, 1) }}% / {{ $formatNullable($uSkPct, 1) }}%</span>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-4 px-4 text-center text-slate-400">Belum ada data rasio per UPT.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Panel Drill-Down Level ULTG per UPT --}}
    <div
        id="asset-ultg-drilldown-panel"
        class="ibs-card min-w-0 max-w-full p-4 sm:p-5"
        x-data='assetUltgDrilldown(@json($ultgByUpt), @json($canonicalUpts))'
    >
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-b border-slate-100 pb-3.5">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="ibs-section-title text-base font-bold text-slate-900">Drill-Down Level ULTG per UPT</h2>
                <span class="rounded-full bg-[#e6f4f6] px-2.5 py-0.5 text-[11px] font-semibold text-[#0b6175]" x-show="selectedUpt !== ''" x-text="`UPT ${selectedUpt} (${activeUltgRows.length} ULTG)`"></span>
            </div>
            <span class="text-[11px] font-medium text-slate-500">Pilih UPT untuk meninjau rincian per ULTG &amp; atribut</span>
        </div>

        {{-- 8 Canonical UPT Selector Tabs --}}
        <div class="mt-3.5 flex flex-wrap items-center gap-1.5">
            @foreach ($canonicalUpts as $uptTab)
                @php $ultgTabCount = count($ultgByUpt[$uptTab] ?? []); @endphp
                <button
                    type="button"
                    @click='selectUpt(@json($uptTab))'
                    :class='selectedUpt === @json($uptTab)
                        ? "bg-[#0b6175] text-white border-[#0b6175] shadow-2xs"
                        : "bg-white text-slate-700 border-slate-200 hover:bg-slate-50"'
                    class="pill-tab-transition cursor-pointer inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-xs font-semibold focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-[#16a3b8]"
                >
                    <span>{{ $uptTab }}</span>
                    <span
                        :class='selectedUpt === @json($uptTab) ? "bg-white/20 text-white" : "bg-slate-100 text-slate-600"'
                        class="rounded-md px-1.5 py-0.5 text-[10px] font-bold font-sans tabular-nums"
                    >
                        {{ $ultgTabCount }}
                    </span>
                </button>
            @endforeach
        </div>

        {{-- Tabel ULTG Breakdown --}}
        <div class="mt-4 overflow-x-auto rounded-xl border border-slate-200/80">
            <table class="w-full text-xs">
                <thead class="bg-slate-50/70 text-slate-500 uppercase tracking-wider font-semibold border-b border-slate-200/80">
                    <tr>
                        <th class="py-2.5 px-4 text-left">ULTG</th>
                        <th class="py-2.5 px-4 text-right">Kapasitas Trafo (MVA)</th>
                        <th class="py-2.5 px-4 text-right">Fisik Trafo (Bank)</th>
                        <th class="py-2.5 px-4 text-right">Fisik Trafo (Unit / Set)</th>
                        <th class="py-2.5 px-4 text-right">Transmisi (kms)</th>
                        <th class="py-2.5 px-4 text-right">Tower (Unit)</th>
                        <th class="py-2.5 px-4 text-right">Atribut</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    <template x-for="uRow in activeUltgRows" :key="uRow.ultg">
                        <tr class="hover:bg-slate-50/60 transition">
                            <td class="py-2.5 px-4 font-semibold text-slate-800" x-text="uRow.ultg"></td>
                            <td class="py-2.5 px-4 text-right font-sans tabular-nums" :class="isUnrecorded(uRow.trafo_mva) ? 'text-slate-400' : 'text-slate-700 font-semibold'" x-text="formatNum(uRow.trafo_mva, 1)"></td>
                            <td class="py-2.5 px-4 text-right font-sans tabular-nums" :class="isUnrecorded(uRow.trafo_bank) ? 'text-slate-400' : 'text-slate-700'" x-text="formatNum(uRow.trafo_bank, 0)"></td>
                            <td class="py-2.5 px-4 text-right font-sans tabular-nums" :class="isUnrecorded(uRow.trafo_unit) ? 'text-slate-400' : 'text-slate-700'" x-text="formatNum(uRow.trafo_unit, 0)"></td>
                            <td class="py-2.5 px-4 text-right font-sans tabular-nums" :class="isUnrecorded(uRow.transmisi) ? 'text-slate-400' : 'text-slate-700'" x-text="formatNum(uRow.transmisi, 1)"></td>
                            <td class="py-2.5 px-4 text-right font-sans tabular-nums" :class="isUnrecorded(uRow.tower) ? 'text-slate-400' : 'text-slate-700'" x-text="formatNum(uRow.tower, 0)"></td>
                            <td class="py-2.5 px-4 text-right">
                                <button
                                    type="button"
                                    @click="toggleExpand(uRow.ultg)"
                                    class="cursor-pointer rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-semibold text-[#0b6175] hover:bg-[#e6f4f6]/60 transition"
                                    x-text="expandedUltg === uRow.ultg ? 'Tutup' : `Rincian (${Object.keys(uRow.attributes || {}).length})`"
                                ></button>
                            </td>
                        </tr>
                    </template>
                    <template x-if="activeUltgRows.length === 0">
                        <tr>
                            <td colspan="7" class="py-6 px-4 text-center text-slate-400">
                                Belum ada data ULTG tercatat untuk UPT yang dipilih.
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        {{-- Panel Rincian Atribut ULTG Terpilih --}}
        <div class="mt-3 rounded-xl border border-[#bce3e8] bg-[#e6f4f6]/35 p-4" x-show="expandedUltg !== '' && expandedAttributes.length > 0" x-cloak>
            <div class="flex items-center justify-between gap-2 border-b border-[#bce3e8]/80 pb-2 mb-3">
                <span class="text-xs font-bold text-[#0b6175]" x-text="`Rincian Atribut ULTG ${expandedUltg} (UPT ${selectedUpt})`"></span>
                <button type="button" @click="expandedUltg = ''" class="cursor-pointer text-[11px] font-semibold text-slate-500 hover:text-slate-800">Tutup</button>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                <template x-for="attr in expandedAttributes" :key="attr.name">
                    <div class="flex items-center justify-between gap-2 rounded-lg border border-slate-200/80 bg-white px-3 py-2 text-[11px]">
                        <span class="text-slate-600 truncate" x-text="attr.name"></span>
                        <span class="font-sans tabular-nums font-semibold text-slate-900 shrink-0" x-text="attr.formatted"></span>
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- KONTEN FOKUS 1: TRAFO (Default) --}}
    @if ($section === 'trafo')
        {{-- Dual-Chart ECharts Canvases --}}
        <div
            class="grid grid-cols-1 lg:grid-cols-2 gap-5 min-w-0"
            x-data='assetTrafoCharts(@json($chartConfig), @json($bankByUpt), @json($unitByUpt), @json($canonicalUpts))'
            x-init="initCharts()"
        >
            {{-- Canvas 1: Kapasitas Daya per UPT --}}
            <div class="ibs-card min-w-0 max-w-full overflow-hidden p-4 sm:p-5 flex flex-col justify-between">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3 mb-3">
                    <h2 class="ibs-section-title">Kapasitas Daya per UPT</h2>
                    <div class="inline-flex max-w-full flex-wrap items-center rounded-xl bg-slate-100/90 p-1 text-xs">
                        <button
                            type="button"
                            @click="setViewMode('total')"
                            :class="viewMode === 'total' ? 'bg-white text-[#0b6175] font-semibold shadow-2xs' : 'text-slate-600 hover:text-slate-900 font-medium'"
                            class="pill-tab-transition cursor-pointer rounded-lg px-2.5 py-1 text-[11px]"
                        >
                            Total MVA
                        </button>
                        <button
                            type="button"
                            @click="setViewMode('breakdown')"
                            :class="viewMode === 'breakdown' ? 'bg-white text-[#0b6175] font-semibold shadow-2xs' : 'text-slate-600 hover:text-slate-900 font-medium'"
                            class="pill-tab-transition cursor-pointer rounded-lg px-2.5 py-1 text-[11px]"
                        >
                            Per Tegangan
                        </button>
                    </div>
                </div>
                <div
                    id="asset-detail-trafo-chart"
                    class="w-full h-[360px] min-h-[340px]"
                    style="width: 100%; height: 360px; min-height: 340px;"
                ></div>
            </div>

            {{-- Canvas 2: Fisik Trafo Terpasang --}}
            <div class="ibs-card min-w-0 max-w-full overflow-hidden p-4 sm:p-5 flex flex-col justify-between">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3 mb-3">
                    <h2 class="ibs-section-title">Fisik Trafo Terpasang</h2>
                    <span class="inline-flex items-center rounded-md bg-[#e6f4f6] px-2.5 py-0.5 text-[11px] font-semibold text-[#0b6175]">
                        Bank & Unit
                    </span>
                </div>
                <div
                    id="asset-detail-trafo-physical-chart"
                    class="w-full max-w-full overflow-hidden h-[360px] min-h-[340px]"
                    style="width: 100%; height: 360px; min-height: 340px;"
                ></div>
            </div>
        </div>

        {{-- Main Panel: Bedah Detail Trafo per Level Tegangan & UPT --}}
        <div class="ibs-card min-w-0 max-w-full p-4 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-4">
                <h2 class="ibs-section-title text-base font-bold text-slate-900">Bedah Detail Trafo per Level Tegangan & UPT</h2>
                <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-600">
                    8 UPT
                </span>
            </div>

            <div class="mt-4 space-y-5">
                @forelse ($trafoGroups as $tg)
                    @php
                        $tgTotalMva = $tg['uit_total']['mva'] ?? $tg['total_upt']['mva'] ?? null;
                        $tgTotalBank = $tg['uit_total']['bank'] ?? $tg['total_upt']['bank'] ?? null;
                        $tgTotalUnit = $tg['uit_total']['unit'] ?? $tg['total_upt']['unit'] ?? null;
                        $hasUptBank = (bool) ($tg['has_upt_bank'] ?? true);
                    @endphp
                    <div class="rounded-xl border border-slate-200/80 overflow-hidden">
                        <div class="flex flex-wrap items-center justify-between gap-2 bg-slate-50/70 px-4 py-3 border-b border-slate-200/80">
                            <div class="flex min-w-0 items-center gap-2">
                                <span class="h-2 w-2 shrink-0 rounded-full bg-[#0b6175]"></span>
                                <h3 class="text-sm font-semibold text-slate-800">{{ $tg['label'] }} ({{ $tg['voltage'] }})</h3>
                            </div>
                            <div class="flex flex-wrap items-center gap-3 text-xs font-sans tabular-nums text-slate-600">
                                <span>
                                    @if ($tgTotalMva === null)
                                        <span class="text-slate-400">Tidak tercatat</span>
                                    @else
                                        <strong class="text-slate-900">{{ number_format((float) $tgTotalMva, 1, ',', '.') }}</strong> MVA
                                    @endif
                                </span>
                                <span class="text-slate-300">|</span>
                                <span>
                                    @if ($tgTotalBank === null)
                                        <span class="text-slate-400">Tidak tercatat</span>
                                    @else
                                        <strong class="text-slate-900">{{ number_format((float) $tgTotalBank, 0, ',', '.') }}</strong> Bank
                                    @endif
                                </span>
                                <span class="text-slate-300">|</span>
                                <span>
                                    @if ($tgTotalUnit === null)
                                        <span class="text-slate-400">Tidak tercatat</span>
                                    @else
                                        <strong class="text-slate-900">{{ number_format((float) $tgTotalUnit, 0, ',', '.') }}</strong> Unit
                                    @endif
                                </span>
                            </div>
                        </div>

                        <div class="w-full overflow-x-auto">
                            <table class="w-full text-xs">
                                <thead class="bg-slate-50/50 text-slate-500 uppercase tracking-wider font-semibold border-b border-slate-100">
                                    <tr>
                                        <th class="py-2.5 px-4 text-left">UPT</th>
                                        <th class="py-2.5 px-4 text-right">Kapasitas Daya (MVA)</th>
                                        <th class="py-2.5 px-4 text-right">Fisik (Bank)</th>
                                        <th class="py-2.5 px-4 text-right">Fisik (Unit / Set)</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    @foreach ($canonicalUpts as $upt)
                                        @php
                                            $row = $tg['by_upt'][$upt] ?? ['mva' => null, 'bank' => null, 'unit' => null];
                                            $rowMva = $row['mva'] ?? null;
                                            $rowBank = $row['bank'] ?? null;
                                            $rowUnit = $row['unit'] ?? null;
                                            $mvaShare = ($rowMva !== null && $tgTotalMva !== null && (float) $tgTotalMva > 0)
                                                ? min(100, round(((float) $rowMva / (float) $tgTotalMva) * 100, 1))
                                                : 0;
                                        @endphp
                                        <tr class="hover:bg-slate-50/60 transition">
                                            <td class="py-2 px-4 font-semibold text-slate-800">{{ $upt }}</td>
                                            <td class="py-2 px-4 text-right font-sans tabular-nums text-slate-700">
                                                @if ($rowMva === null)
                                                    <span class="text-slate-400 font-normal">Tidak tercatat</span>
                                                @else
                                                    <div class="flex items-center justify-end gap-2.5">
                                                        <div class="hidden sm:block w-16 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                                                            <div class="h-full rounded-full bg-[#0b6175]" style="width: {{ $mvaShare }}%;"></div>
                                                        </div>
                                                        <span class="min-w-[3.5rem] text-right">{{ number_format((float) $rowMva, 1, ',', '.') }}</span>
                                                    </div>
                                                @endif
                                            </td>
                                            <td class="py-2 px-4 text-right font-sans tabular-nums text-slate-700">
                                                @if ($hasUptBank)
                                                    @if ($rowBank === null)
                                                        <span class="text-slate-400 font-normal">Tidak tercatat</span>
                                                    @else
                                                        {{ number_format((float) $rowBank, 0, ',', '.') }}
                                                    @endif
                                                @else
                                                    <span class="text-slate-400">Tidak dirinci</span>
                                                @endif
                                            </td>
                                            <td class="py-2 px-4 text-right font-sans tabular-nums text-slate-700">
                                                @if ($rowUnit === null)
                                                    <span class="text-slate-400 font-normal">Tidak tercatat</span>
                                                @else
                                                    {{ number_format((float) $rowUnit, 0, ',', '.') }}
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="border-t border-slate-200 bg-slate-50/70 font-semibold text-slate-900">
                                    <tr>
                                        <td class="py-2.5 px-4 text-left font-bold text-slate-900">TOTAL UIT JBT</td>
                                        <td class="py-2.5 px-4 text-right font-sans tabular-nums font-bold text-slate-900">
                                            @if ($tgTotalMva === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ number_format((float) $tgTotalMva, 1, ',', '.') }} MVA @endif
                                        </td>
                                        <td class="py-2.5 px-4 text-right font-sans tabular-nums font-bold text-slate-900">
                                            @if ($tgTotalBank === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ number_format((float) $tgTotalBank, 0, ',', '.') }} Bank @endif
                                        </td>
                                        <td class="py-2.5 px-4 text-right font-sans tabular-nums font-bold text-slate-900">
                                            @if ($tgTotalUnit === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ number_format((float) $tgTotalUnit, 0, ',', '.') }} Unit @endif
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                @empty
                    <div class="rounded-xl border border-slate-200/80 bg-slate-50/70 p-6 text-center text-xs text-slate-500">
                        Belum ada data rincian trafo untuk periode yang dipilih.
                    </div>
                @endforelse
            </div>
        </div>

        {{-- Panel Saluran Transmisi & Tower --}}
        <div class="grid grid-cols-1 gap-5 lg:grid-cols-2 min-w-0">
            {{-- Panel Saluran Transmisi (SU vs SK) --}}
            <div class="ibs-card min-w-0 max-w-full p-4 sm:p-5">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3 mb-4">
                    <h3 class="ibs-section-title">Saluran Transmisi (SU vs SK)</h3>
                    <span class="rounded-md bg-[#e6f4f6] px-2.5 py-0.5 text-xs font-semibold font-sans tabular-nums text-[#0b6175]">
                        {{ $totalTransmisi === null ? 'Tidak tercatat' : number_format($totalTransmisi, 1, ',', '.').' kms' }}
                    </span>
                </div>
                <div class="space-y-4">
                    @foreach ($transmisiGroups as $tr)
                        @php
                            $trTotalKms = $tr['uit_total']['total_kms'] ?? $tr['total_upt']['total_kms'] ?? null;
                        @endphp
                        <div class="rounded-xl border border-slate-100 overflow-hidden">
                            <div class="bg-slate-50/70 px-3.5 py-2 text-xs font-semibold text-slate-700 flex justify-between">
                                <span>{{ $tr['label'] }} ({{ $tr['voltage'] }})</span>
                                <span class="font-sans tabular-nums font-bold text-slate-900">
                                    {{ $trTotalKms === null ? 'Tidak tercatat' : number_format((float) $trTotalKms, 1, ',', '.').' kms' }}
                                </span>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-xs">
                                    <thead class="bg-white text-slate-400 font-semibold border-b border-slate-100">
                                        <tr>
                                            <th class="py-1.5 px-3 text-left">UPT</th>
                                            <th class="py-1.5 px-3 text-right">SU (kms)</th>
                                            <th class="py-1.5 px-3 text-right">SK (kms)</th>
                                            <th class="py-1.5 px-3 text-right">Total (kms)</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        @foreach ($canonicalUpts as $upt)
                                            @php $r = $tr['by_upt'][$upt] ?? ['su_kms' => null, 'sk_kms' => null, 'total_kms' => null]; @endphp
                                            <tr class="hover:bg-slate-50/50">
                                                <td class="py-1.5 px-3 font-medium text-slate-700">{{ $upt }}</td>
                                                <td class="py-1.5 px-3 text-right font-sans tabular-nums text-slate-600">
                                                    @if (($r['su_kms'] ?? null) === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ number_format((float) $r['su_kms'], 1, ',', '.') }} @endif
                                                </td>
                                                <td class="py-1.5 px-3 text-right font-sans tabular-nums text-slate-600">
                                                    @if (($r['sk_kms'] ?? null) === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ number_format((float) $r['sk_kms'], 1, ',', '.') }} @endif
                                                </td>
                                                <td class="py-1.5 px-3 text-right font-sans tabular-nums font-semibold text-slate-800">
                                                    @if (($r['total_kms'] ?? null) === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ number_format((float) $r['total_kms'], 1, ',', '.') }} @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Panel Tower Transmisi per Tegangan --}}
            <div class="ibs-card min-w-0 max-w-full p-4 sm:p-5">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3 mb-4">
                    <h3 class="ibs-section-title">Tower Transmisi per Tegangan</h3>
                    <span class="rounded-md bg-[#e6f4f6] px-2.5 py-0.5 text-xs font-semibold font-sans tabular-nums text-[#0b6175]">
                        {{ $totalTower === null ? 'Tidak tercatat' : number_format($totalTower, 0, ',', '.').' Unit' }}
                    </span>
                </div>
                <div class="space-y-4">
                    @foreach ($towerGroups as $tw)
                        @php
                            $twTotalUnit = $tw['uit_total']['total_unit'] ?? $tw['total_upt']['total_unit'] ?? null;
                        @endphp
                        <div class="rounded-xl border border-slate-100 overflow-hidden">
                            <div class="bg-slate-50/70 px-3.5 py-2 text-xs font-semibold text-slate-700 flex justify-between">
                                <span>{{ $tw['label'] }} ({{ $tw['voltage'] }})</span>
                                <span class="font-sans tabular-nums font-bold text-slate-900">
                                    {{ $twTotalUnit === null ? 'Tidak tercatat' : number_format((float) $twTotalUnit, 0, ',', '.').' Unit' }}
                                </span>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-xs">
                                    <thead class="bg-white text-slate-400 font-semibold border-b border-slate-100">
                                        <tr>
                                            <th class="py-1.5 px-3 text-left">UPT</th>
                                            <th class="py-1.5 px-3 text-right">SU (Unit)</th>
                                            <th class="py-1.5 px-3 text-right">SK (Unit)</th>
                                            <th class="py-1.5 px-3 text-right">Total (Unit)</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        @foreach ($canonicalUpts as $upt)
                                            @php $r = $tw['by_upt'][$upt] ?? ['su_unit' => null, 'sk_unit' => null, 'total_unit' => null]; @endphp
                                            <tr class="hover:bg-slate-50/50">
                                                <td class="py-1.5 px-3 font-medium text-slate-700">{{ $upt }}</td>
                                                <td class="py-1.5 px-3 text-right font-sans tabular-nums text-slate-600">
                                                    @if (($r['su_unit'] ?? null) === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ number_format((float) $r['su_unit'], 0, ',', '.') }} @endif
                                                </td>
                                                <td class="py-1.5 px-3 text-right font-sans tabular-nums text-slate-600">
                                                    @if (($r['sk_unit'] ?? null) === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ number_format((float) $r['sk_unit'], 0, ',', '.') }} @endif
                                                </td>
                                                <td class="py-1.5 px-3 text-right font-sans tabular-nums font-semibold text-slate-800">
                                                    @if (($r['total_unit'] ?? null) === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ number_format((float) $r['total_unit'], 0, ',', '.') }} @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    {{-- KONTEN FOKUS 2: DISTRIBUSI DAYA (MVA) --}}
    @if ($section === 'mva')
        {{-- Dual-Chart ECharts: Horizontal Ranked Bar + Donut Share --}}
        <div
            class="grid grid-cols-1 lg:grid-cols-3 gap-5 min-w-0"
            x-data='assetMvaCharts(@json($mvaByUpt), @json($totalTrafoMva))'
            x-init="initCharts()"
        >
            <div class="lg:col-span-2 ibs-card min-w-0 max-w-full overflow-hidden p-4 sm:p-5 flex flex-col justify-between">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3 mb-3">
                    <h2 class="ibs-section-title">Peringkat Daya per UPT</h2>
                    <span class="inline-flex items-center rounded-md bg-[#e6f4f6] px-2.5 py-0.5 text-[11px] font-semibold font-sans tabular-nums text-[#0b6175]">
                        {{ $totalTrafoMva === null ? 'Tidak tercatat' : number_format($totalTrafoMva, 1, ',', '.').' MVA' }}
                    </span>
                </div>
                <div
                    id="asset-mva-ranked-chart"
                    class="w-full max-w-full overflow-hidden h-[360px] min-h-[340px]"
                    style="width: 100%; height: 360px; min-height: 340px;"
                ></div>
            </div>

            <div class="lg:col-span-1 ibs-card min-w-0 max-w-full overflow-hidden p-4 sm:p-5 flex flex-col justify-between">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3 mb-3">
                    <h2 class="ibs-section-title">Pangsa Daya UPT</h2>
                    <span class="text-[11px] text-slate-500 font-semibold">% Pangsa</span>
                </div>
                <div
                    id="asset-mva-donut-chart"
                    class="w-full max-w-full overflow-hidden h-[360px] min-h-[340px]"
                    style="width: 100%; height: 360px; min-height: 340px;"
                ></div>
            </div>
        </div>

        {{-- Tabel Matriks Daya MVA per UPT & Level Tegangan --}}
        <div class="ibs-card min-w-0 max-w-full p-4 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-4 mb-4">
                <h2 class="ibs-section-title text-base font-bold text-slate-900">Matriks Kapasitas Daya MVA</h2>
                <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-600">8 UPT</span>
            </div>

            <div class="w-full min-w-0 overflow-x-auto rounded-xl border border-slate-200/80">
                <table class="w-full text-xs">
                    <thead class="bg-slate-50/70 text-slate-500 uppercase tracking-wider font-semibold border-b border-slate-200/80">
                        <tr>
                            <th class="py-2.5 px-4 text-left">UPT</th>
                            <th class="py-2.5 px-4 text-right">500/150 kV (MVA)</th>
                            <th class="py-2.5 px-4 text-right">150/70 kV (MVA)</th>
                            <th class="py-2.5 px-4 text-right">150/20 kV (MVA)</th>
                            <th class="py-2.5 px-4 text-right">Total Kapasitas (MVA)</th>
                            <th class="py-2.5 px-4 text-right">Pangsa UIT JBT</th>
                        </tr>
                    </thead>
                    @php
                        $trafoGroupByVoltage = collect($trafoGroups)->keyBy('voltage');
                        $tg500 = $trafoGroupByVoltage->get('500/150 kV');
                        $tg150_70 = $trafoGroupByVoltage->get('150/70 kV');
                        $tg150_20 = $trafoGroupByVoltage->get('150/20 kV');
                        $tot500 = $tg500['uit_total']['mva'] ?? $tg500['total_upt']['mva'] ?? null;
                        $tot150_70 = $tg150_70['uit_total']['mva'] ?? $tg150_70['total_upt']['mva'] ?? null;
                        $tot150_20 = $tg150_20['uit_total']['mva'] ?? $tg150_20['total_upt']['mva'] ?? null;
                    @endphp
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @foreach ($rankedMva as $upt => $totMva)
                            @php
                                $mva500 = $tg500['by_upt'][$upt]['mva'] ?? null;
                                $mva150_70 = $tg150_70['by_upt'][$upt]['mva'] ?? null;
                                $mva150_20 = $tg150_20['by_upt'][$upt]['mva'] ?? null;
                                $pct = ($totMva !== null && $totalTrafoMva !== null && $totalTrafoMva > 0)
                                    ? min(100, round(((float) $totMva / $totalTrafoMva) * 100, 1))
                                    : null;
                            @endphp
                            <tr class="hover:bg-slate-50/60 transition">
                                <td class="py-2.5 px-4 font-semibold text-slate-800">{{ $upt }}</td>
                                <td class="py-2.5 px-4 text-right font-sans tabular-nums text-slate-700">
                                    @if ($mva500 === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ number_format((float) $mva500, 1, ',', '.') }} @endif
                                </td>
                                <td class="py-2.5 px-4 text-right font-sans tabular-nums text-slate-700">
                                    @if ($mva150_70 === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ number_format((float) $mva150_70, 1, ',', '.') }} @endif
                                </td>
                                <td class="py-2.5 px-4 text-right font-sans tabular-nums text-slate-700">
                                    @if ($mva150_20 === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ number_format((float) $mva150_20, 1, ',', '.') }} @endif
                                </td>
                                <td class="py-2.5 px-4 text-right font-sans tabular-nums font-bold text-slate-900">
                                    @if ($totMva === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ number_format((float) $totMva, 1, ',', '.') }} @endif
                                </td>
                                <td class="py-2.5 px-4 text-right font-sans tabular-nums">
                                    @if ($pct === null)
                                        <span class="text-slate-400 font-normal">Tidak tercatat</span>
                                    @else
                                        <div class="flex items-center justify-end gap-2.5">
                                            <div class="w-16 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                                                <div class="h-full rounded-full bg-[#0b6175]" style="width: {{ $pct }}%;"></div>
                                            </div>
                                            <span class="font-semibold text-slate-700 min-w-[2.75rem] text-right">{{ number_format($pct, 1, ',', '.') }}%</span>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="border-t border-slate-200 bg-slate-50/70 font-semibold text-slate-900">
                        <tr>
                            <td class="py-2.5 px-4 text-left font-bold text-slate-900">TOTAL UIT JBT</td>
                            <td class="py-2.5 px-4 text-right font-sans tabular-nums font-bold text-slate-900">
                                @if ($tot500 === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ number_format((float) $tot500, 1, ',', '.') }} @endif
                            </td>
                            <td class="py-2.5 px-4 text-right font-sans tabular-nums font-bold text-slate-900">
                                @if ($tot150_70 === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ number_format((float) $tot150_70, 1, ',', '.') }} @endif
                            </td>
                            <td class="py-2.5 px-4 text-right font-sans tabular-nums font-bold text-slate-900">
                                @if ($tot150_20 === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ number_format((float) $tot150_20, 1, ',', '.') }} @endif
                            </td>
                            <td class="py-2.5 px-4 text-right font-sans tabular-nums font-bold text-slate-900">
                                @if ($totalTrafoMva === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ number_format($totalTrafoMva, 1, ',', '.') }} MVA @endif
                            </td>
                            <td class="py-2.5 px-4 text-right font-sans tabular-nums font-bold text-slate-900">
                                {{ $totalTrafoMva === null ? 'Tidak tercatat' : '100,0%' }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    @endif

    {{-- KONTEN FOKUS 3: JARINGAN TRANSMISI (KMS) --}}
    @if ($section === 'transmisi')
        {{-- Dual-Chart ECharts: Stacked Bar (SU vs SK) + Voltage Class Donut --}}
        <div
            class="grid grid-cols-1 lg:grid-cols-3 gap-5 min-w-0"
            x-data='assetTransmisiCharts(@json($transmisiByUpt), @json($transmisiGroups), @json($totalTransmisi))'
            x-init="initCharts()"
        >
            <div class="lg:col-span-2 ibs-card min-w-0 max-w-full overflow-hidden p-4 sm:p-5 flex flex-col justify-between">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3 mb-3">
                    <h2 class="ibs-section-title">Panjang Saluran per UPT</h2>
                    <span class="inline-flex items-center rounded-md bg-[#e6f4f6] px-2.5 py-0.5 text-[11px] font-semibold font-sans tabular-nums text-[#0b6175]">
                        {{ $totalTransmisi === null ? 'Tidak tercatat' : number_format($totalTransmisi, 1, ',', '.').' kms' }}
                    </span>
                </div>
                <div
                    id="asset-transmisi-bar-chart"
                    class="w-full max-w-full overflow-hidden h-[360px] min-h-[340px]"
                    style="width: 100%; height: 360px; min-height: 340px;"
                ></div>
            </div>

            <div class="lg:col-span-1 ibs-card min-w-0 max-w-full overflow-hidden p-4 sm:p-5 flex flex-col justify-between">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3 mb-3">
                    <h2 class="ibs-section-title">Pangsa Kelas Tegangan</h2>
                    <span class="text-[11px] text-slate-500 font-semibold">% Tegangan</span>
                </div>
                <div
                    id="asset-transmisi-voltage-chart"
                    class="w-full max-w-full overflow-hidden h-[360px] min-h-[340px]"
                    style="width: 100%; height: 360px; min-height: 340px;"
                ></div>
            </div>
        </div>

        {{-- Tabel Rincian Saluran Transmisi per Tegangan & UPT --}}
        <div class="ibs-card min-w-0 max-w-full p-4 sm:p-6 space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-4">
                <h2 class="ibs-section-title text-base font-bold text-slate-900">Saluran Transmisi per Tegangan</h2>
                <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-600">4 Tegangan</span>
            </div>

            @foreach ($transmisiGroups as $tr)
                @php
                    $trTotKms = $tr['uit_total']['total_kms'] ?? $tr['total_upt']['total_kms'] ?? null;
                @endphp
                <div class="rounded-xl border border-slate-200/80 overflow-hidden">
                    <div class="flex items-center justify-between bg-slate-50/70 px-4 py-2.5 border-b border-slate-200/80">
                        <span class="text-xs font-semibold text-slate-800">{{ $tr['label'] }} ({{ $tr['voltage'] }})</span>
                        <span class="text-xs font-sans tabular-nums font-bold text-slate-900">
                            {{ $trTotKms === null ? 'Tidak tercatat' : number_format((float) $trTotKms, 1, ',', '.').' kms' }}
                        </span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead class="bg-white text-slate-400 uppercase tracking-wider font-semibold border-b border-slate-100">
                                <tr>
                                    <th class="py-2 px-4 text-left">UPT</th>
                                    <th class="py-2 px-4 text-right">SU (kms)</th>
                                    <th class="py-2 px-4 text-right">SK (kms)</th>
                                    <th class="py-2 px-4 text-right">Total Jalur (kms)</th>
                                    <th class="py-2 px-4 text-right">Kontribusi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($canonicalUpts as $upt)
                                    @php
                                        $r = $tr['by_upt'][$upt] ?? ['su_kms' => null, 'sk_kms' => null, 'total_kms' => null];
                                        $rTot = $r['total_kms'] ?? null;
                                        $share = ($rTot !== null && $trTotKms !== null && (float) $trTotKms > 0)
                                            ? min(100, round(((float) $rTot / (float) $trTotKms) * 100, 1))
                                            : null;
                                    @endphp
                                    <tr class="hover:bg-slate-50/50">
                                        <td class="py-2 px-4 font-medium text-slate-800">{{ $upt }}</td>
                                        <td class="py-2 px-4 text-right font-sans tabular-nums text-slate-600">
                                            @if (($r['su_kms'] ?? null) === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ number_format((float) $r['su_kms'], 1, ',', '.') }} @endif
                                        </td>
                                        <td class="py-2 px-4 text-right font-sans tabular-nums text-slate-600">
                                            @if (($r['sk_kms'] ?? null) === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ number_format((float) $r['sk_kms'], 1, ',', '.') }} @endif
                                        </td>
                                        <td class="py-2 px-4 text-right font-sans tabular-nums font-bold text-slate-800">
                                            @if ($rTot === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ number_format((float) $rTot, 1, ',', '.') }} @endif
                                        </td>
                                        <td class="py-2 px-4 text-right font-sans tabular-nums">
                                            @if ($share === null)
                                                <span class="text-slate-400 font-normal">Tidak tercatat</span>
                                            @else
                                                <div class="flex items-center justify-end gap-2">
                                                    <div class="w-14 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                                                        <div class="h-full rounded-full bg-[#16a3b8]" style="width: {{ $share }}%;"></div>
                                                    </div>
                                                    <span class="text-slate-600 min-w-[2.5rem] text-right">{{ number_format($share, 1, ',', '.') }}%</span>
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- KONTEN FOKUS 4: SEBARAN TOWER (UNIT) --}}
    @if ($section === 'tower')
        {{-- Dual-Chart ECharts: Grouped Bar + UPT Share Donut --}}
        <div
            class="grid grid-cols-1 lg:grid-cols-3 gap-5 min-w-0"
            x-data='assetTowerCharts(@json($towerGroups), @json($towerByUpt), @json($canonicalUpts), @json($totalTower))'
            x-init="initCharts()"
        >
            <div class="lg:col-span-2 ibs-card min-w-0 max-w-full overflow-hidden p-4 sm:p-5 flex flex-col justify-between">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3 mb-3">
                    <h2 class="ibs-section-title">Sebaran Tower per UPT</h2>
                    <span class="inline-flex items-center rounded-md bg-[#e6f4f6] px-2.5 py-0.5 text-[11px] font-semibold font-sans tabular-nums text-[#0b6175]">
                        {{ $totalTower === null ? 'Tidak tercatat' : number_format($totalTower, 0, ',', '.').' Unit' }}
                    </span>
                </div>
                <div
                    id="asset-tower-bar-chart"
                    class="w-full max-w-full overflow-hidden h-[360px] min-h-[340px]"
                    style="width: 100%; height: 360px; min-height: 340px;"
                ></div>
            </div>

            <div class="lg:col-span-1 ibs-card min-w-0 max-w-full overflow-hidden p-4 sm:p-5 flex flex-col justify-between">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3 mb-3">
                    <h2 class="ibs-section-title">Proporsi Tower UPT</h2>
                    <span class="text-[11px] text-slate-500 font-semibold">% Pangsa</span>
                </div>
                <div
                    id="asset-tower-pie-chart"
                    class="w-full max-w-full overflow-hidden h-[360px] min-h-[340px]"
                    style="width: 100%; height: 360px; min-height: 340px;"
                ></div>
            </div>
        </div>

        {{-- Tabel Rincian Tower per Kelas Tegangan & UPT --}}
        <div class="ibs-card min-w-0 max-w-full p-4 sm:p-6 space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-4">
                <h2 class="ibs-section-title text-base font-bold text-slate-900">Tower Transmisi per Tegangan</h2>
                <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-600">8 UPT</span>
            </div>

            @foreach ($towerGroups as $tw)
                @php
                    $twTotUnit = $tw['uit_total']['total_unit'] ?? $tw['total_upt']['total_unit'] ?? null;
                @endphp
                <div class="rounded-xl border border-slate-200/80 overflow-hidden">
                    <div class="flex items-center justify-between bg-slate-50/70 px-4 py-2.5 border-b border-slate-200/80">
                        <span class="text-xs font-semibold text-slate-800">{{ $tw['label'] }} ({{ $tw['voltage'] }})</span>
                        <span class="text-xs font-sans tabular-nums font-bold text-slate-900">
                            {{ $twTotUnit === null ? 'Tidak tercatat' : number_format((float) $twTotUnit, 0, ',', '.').' Unit' }}
                        </span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead class="bg-white text-slate-400 uppercase tracking-wider font-semibold border-b border-slate-100">
                                <tr>
                                    <th class="py-2 px-4 text-left">UPT</th>
                                    <th class="py-2 px-4 text-right">SU (Unit)</th>
                                    <th class="py-2 px-4 text-right">SK (Unit)</th>
                                    <th class="py-2 px-4 text-right">Total Tower (Unit)</th>
                                    <th class="py-2 px-4 text-right">Pangsa</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($canonicalUpts as $upt)
                                    @php
                                        $r = $tw['by_upt'][$upt] ?? ['su_unit' => null, 'sk_unit' => null, 'total_unit' => null];
                                        $rTot = $r['total_unit'] ?? null;
                                        $share = ($rTot !== null && $twTotUnit !== null && (float) $twTotUnit > 0)
                                            ? min(100, round(((float) $rTot / (float) $twTotUnit) * 100, 1))
                                            : null;
                                    @endphp
                                    <tr class="hover:bg-slate-50/50">
                                        <td class="py-2 px-4 font-medium text-slate-800">{{ $upt }}</td>
                                        <td class="py-2 px-4 text-right font-sans tabular-nums text-slate-600">
                                            @if (($r['su_unit'] ?? null) === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ number_format((float) $r['su_unit'], 0, ',', '.') }} @endif
                                        </td>
                                        <td class="py-2 px-4 text-right font-sans tabular-nums text-slate-600">
                                            @if (($r['sk_unit'] ?? null) === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ number_format((float) $r['sk_unit'], 0, ',', '.') }} @endif
                                        </td>
                                        <td class="py-2 px-4 text-right font-sans tabular-nums font-bold text-slate-800">
                                            @if ($rTot === null) <span class="text-slate-400 font-normal">Tidak tercatat</span> @else {{ number_format((float) $rTot, 0, ',', '.') }} @endif
                                        </td>
                                        <td class="py-2 px-4 text-right font-sans tabular-nums">
                                            @if ($share === null)
                                                <span class="text-slate-400 font-normal">Tidak tercatat</span>
                                            @else
                                                <div class="flex items-center justify-end gap-2">
                                                    <div class="w-14 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                                                        <div class="h-full rounded-full bg-[#0b6175]" style="width: {{ $share }}%;"></div>
                                                    </div>
                                                    <span class="text-slate-600 min-w-[2.5rem] text-right">{{ number_format($share, 1, ',', '.') }}%</span>
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Interactive Alpine.js Matrix & Search Filter (Ada di semua sub-page) --}}
    <div
        class="ibs-card min-w-0 max-w-full p-4 sm:p-6"
        x-data='assetInteractiveMatrix(@json($interactiveMatrix), @json($canonicalUpts), @json($section))'
    >
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-b border-slate-100 pb-4">
            <h2 class="ibs-section-title text-base font-bold text-slate-900">Eksplorasi Matriks Seluruh Atribut Aset</h2>
            <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold font-sans tabular-nums text-slate-600" x-text="`${filteredRows.length} atribut`"></span>
        </div>

        {{-- Controls Filter Bar --}}
        <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex flex-wrap items-center gap-1.5 text-xs">
                <button
                    type="button"
                    @click="selectedGroup = 'all'"
                    :class="selectedGroup === 'all' ? 'bg-[#0b6175] text-white font-semibold' : 'bg-slate-100 text-slate-600 hover:bg-slate-200/70 font-medium'"
                    class="pill-tab-transition cursor-pointer rounded-lg px-3 py-1.5"
                >
                    Semua Kelompok
                </button>
                <button
                    type="button"
                    @click="selectedGroup = 'trafo'"
                    :class="selectedGroup === 'trafo' ? 'bg-[#0b6175] text-white font-semibold' : 'bg-slate-100 text-slate-600 hover:bg-slate-200/70 font-medium'"
                    class="pill-tab-transition cursor-pointer rounded-lg px-3 py-1.5"
                >
                    Trafo & Gardu
                </button>
                <button
                    type="button"
                    @click="selectedGroup = 'transmisi'"
                    :class="selectedGroup === 'transmisi' ? 'bg-[#0b6175] text-white font-semibold' : 'bg-slate-100 text-slate-600 hover:bg-slate-200/70 font-medium'"
                    class="pill-tab-transition cursor-pointer rounded-lg px-3 py-1.5"
                >
                    Transmisi SU/SK
                </button>
                <button
                    type="button"
                    @click="selectedGroup = 'tower'"
                    :class="selectedGroup === 'tower' ? 'bg-[#0b6175] text-white font-semibold' : 'bg-slate-100 text-slate-600 hover:bg-slate-200/70 font-medium'"
                    class="pill-tab-transition cursor-pointer rounded-lg px-3 py-1.5"
                >
                    Tower
                </button>
            </div>

            <div class="flex flex-wrap items-center gap-2 min-w-0 max-w-full">
                <select
                    x-model="selectedUpt"
                    class="max-w-full min-w-0 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs text-slate-700 focus:border-[#0b6175] focus:outline-hidden"
                >
                    <option value="all">Semua UPT (8 UPT)</option>
                    @foreach ($canonicalUpts as $u)
                        <option value="{{ $u }}">{{ $u }}</option>
                    @endforeach
                </select>

                <div class="relative w-full sm:w-64 min-w-0">
                    <input
                        type="text"
                        x-model="search"
                        placeholder="Cari atribut aset..."
                        class="w-full rounded-lg border border-slate-200 bg-white pl-8 pr-3 py-1.5 text-xs text-slate-700 placeholder:text-slate-400 focus:border-[#0b6175] focus:outline-hidden"
                    />
                    <svg class="absolute left-2.5 top-2 h-3.5 w-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <circle cx="11" cy="11" r="8" />
                        <path d="m21 21-4.35-4.35" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </div>
            </div>
        </div>

        {{-- Tabel Matriks Interaktif --}}
        <div class="mt-4 w-full min-w-0 overflow-x-auto rounded-xl border border-slate-200/80">
            <table class="w-full text-xs">
                <thead class="bg-slate-50/70 text-slate-500 uppercase tracking-wider font-semibold border-b border-slate-200/80">
                    <tr>
                        <th class="py-2.5 px-4 text-left">Kelompok</th>
                        <th class="py-2.5 px-4 text-left">Nama Atribut Aset</th>
                        <th class="py-2.5 px-4 text-center">Satuan</th>
                        <template x-if="selectedUpt === 'all'">
                            <template x-for="upt in upts" :key="upt">
                                <th class="py-2.5 px-3 text-right" x-text="upt"></th>
                            </template>
                        </template>
                        <template x-if="selectedUpt !== 'all'">
                            <th class="py-2.5 px-4 text-right" x-text="`Nilai (${selectedUpt})`"></th>
                        </template>
                        <th class="py-2.5 px-4 text-right">Total UIT JBT</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    <template x-for="item in filteredRows" :key="item.attribute">
                        <tr class="hover:bg-slate-50/60 transition">
                            <td class="py-2 px-4 font-semibold text-slate-500" x-text="item.group_label"></td>
                            <td class="py-2 px-4 font-medium text-slate-800" x-text="item.attribute"></td>
                            <td class="py-2 px-4 text-center font-sans text-slate-500" x-text="item.unit || 'Tidak tercatat'"></td>
                            <template x-if="selectedUpt === 'all'">
                                <template x-for="upt in upts" :key="upt">
                                    <td
                                        class="py-2 px-3 text-right font-sans tabular-nums"
                                        :class="isUnrecorded(item.values ? item.values[upt] : null) ? 'text-slate-400 font-normal' : 'text-slate-700'"
                                        x-text="formatNumber(item.values ? item.values[upt] : null)"
                                    ></td>
                                </template>
                            </template>
                            <template x-if="selectedUpt !== 'all'">
                                <td
                                    class="py-2 px-4 text-right font-sans tabular-nums"
                                    :class="isUnrecorded(item.values ? item.values[selectedUpt] : null) ? 'text-slate-400 font-normal' : 'text-slate-700'"
                                    x-text="formatNumber(item.values ? item.values[selectedUpt] : null)"
                                ></td>
                            </template>
                            <td
                                class="py-2 px-4 text-right font-sans tabular-nums"
                                :class="isUnrecorded(item.total) ? 'text-slate-400 font-normal' : 'font-bold text-slate-900'"
                                x-text="formatNumber(item.total)"
                            ></td>
                        </tr>
                    </template>
                    <template x-if="filteredRows.length === 0">
                        <tr>
                            <td colspan="12" class="py-6 text-center text-slate-400">
                                Tidak ada atribut yang cocok dengan pencarian atau filter yang dipilih.
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script @cspNonce>
const getAssetPalette = function () {
    const p = window.PLN_CHART_PALETTE || {
        primary: '#0b6175',
        accent: '#16a3b8',
        softTeal: '#bce3e8',
        targetSlate: '#cbd5e1',
        gridLine: '#f1f5f9',
        axisText: '#64748b',
    };
    const categorical = ['#0b6175', '#f59e0b', '#2563eb', '#10b981', '#7c3aed', '#e11d48', '#ea580c', '#64748b'];
    return {
        ...p,
        accent: '#f59e0b',
        softTeal: '#2563eb',
        series: categorical,
        donut: categorical,
    };
};

// Component 0: ULTG Drill-Down Panel
if (typeof window.assetUltgDrilldown !== 'function') {
    window.assetUltgDrilldown = function(ultgByUpt, canonicalUpts) {
        const ultgMap = ultgByUpt || {};
        const uptList = Array.isArray(canonicalUpts) ? canonicalUpts : [];
        const defaultUpt = uptList.find(u => Array.isArray(ultgMap[u]) && ultgMap[u].length > 0) || uptList[0] || '';

        return {
            selectedUpt: defaultUpt,
            expandedUltg: '',

            get activeUltgRows() {
                if (!this.selectedUpt) return [];
                const rows = ultgMap[this.selectedUpt];
                return Array.isArray(rows) ? rows : [];
            },

            get expandedAttributes() {
                if (!this.expandedUltg) return [];
                const row = this.activeUltgRows.find(r => r.ultg === this.expandedUltg);
                if (!row || !row.attributes || typeof row.attributes !== 'object') return [];
                return Object.entries(row.attributes)
                    .sort((a, b) => a[0].localeCompare(b[0]))
                    .map(([name, val]) => ({
                        name: name,
                        formatted: this.formatNum(val, val !== null && val !== undefined && Number(val) % 1 !== 0 ? 1 : 0),
                    }));
            },

            selectUpt(upt) {
                this.selectedUpt = upt;
                this.expandedUltg = '';
            },

            toggleExpand(ultg) {
                this.expandedUltg = this.expandedUltg === ultg ? '' : ultg;
            },

            isUnrecorded(val) {
                return val === null || val === undefined || val === '';
            },

            formatNum(val, decimals = 1) {
                if (this.isUnrecorded(val)) return 'Tidak tercatat';
                const num = Number(val);
                if (isNaN(num)) return 'Tidak tercatat';
                return decimals > 0
                    ? num.toLocaleString('id-ID', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })
                    : num.toLocaleString('id-ID');
            },
        };
    };
}

// Component 1: Trafo Charts
if (typeof window.assetTrafoCharts !== 'function') {
    window.assetTrafoCharts = function(config, bankByUpt, unitByUpt, canonicalUpts) {
        let mvaInstance = null;
        let physicalInstance = null;
        const upts = (config && config.upts) || canonicalUpts || [];
        const mvaSeries = (config && config.mva_series) || [];
        const voltageBreakdown = (config && config.voltage_breakdown) || [];

        return {
            viewMode: 'total',

            initCharts() {
                this.$nextTick(() => {
                    this.renderMvaChart();
                    this.renderPhysicalChart();
                });
            },

            setViewMode(mode) {
                this.viewMode = mode;
                this.renderMvaChart();
            },

            renderMvaChart() {
                const el = document.getElementById('asset-detail-trafo-chart');
                if (!el) return;

                if (!window.echarts && !window.initSafeEChart) {
                    setTimeout(() => this.renderMvaChart(), 50);
                    return;
                }

                const chart = mvaInstance || (window.initSafeEChart ? window.initSafeEChart(el) : (window.echarts ? (window.echarts.getInstanceByDom(el) || window.echarts.init(el)) : null));
                if (!chart) return;
                mvaInstance = chart;

                const pal = getAssetPalette();
                let series = [];

                if (this.viewMode === 'total') {
                    const numericVals = mvaSeries.map(v => (v === null || v === undefined ? 0 : Number(v)));
                    const maxVal = numericVals.length ? Math.max(...numericVals) : 0;
                    series = [{
                        name: 'Total Kapasitas (MVA)',
                        type: 'bar',
                        data: mvaSeries.map(v => {
                            const isRecorded = v !== null && v !== undefined && v !== '';
                            const numVal = isRecorded ? Number(v) : 0;
                            return {
                                value: numVal,
                                isRecorded: isRecorded,
                                itemStyle: {
                                    color: numVal === maxVal && maxVal > 0 ? pal.primary : pal.softTeal,
                                    borderRadius: [6, 6, 0, 0]
                                }
                            };
                        }),
                        barMaxWidth: 36,
                    }];
                } else {
                    series = voltageBreakdown.map((item, idx) => ({
                        name: item.name,
                        type: 'bar',
                        data: (item.data || []).map(v => ({
                            value: (v === null || v === undefined || v === '') ? 0 : Number(v),
                            isRecorded: v !== null && v !== undefined && v !== '',
                        })),
                        itemStyle: {
                            color: pal.series[idx % pal.series.length],
                            borderRadius: [6, 6, 0, 0]
                        }
                    }));
                }

                const option = {
                    animation: true,
                    animationDuration: 650,
                    tooltip: {
                        trigger: 'axis',
                        axisPointer: { type: 'shadow' },
                        formatter: function(params) {
                            if (!params || !params.length) return '';
                            let html = '<div class="font-bold text-slate-800 border-b border-slate-100 pb-1 mb-1 text-xs">UPT ' + params[0].name + '</div>';
                            params.forEach(item => {
                                const isRecorded = item.data && item.data.isRecorded !== false && item.value !== null && item.value !== undefined;
                                const valDisplay = isRecorded
                                    ? Number(item.value).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' MVA'
                                    : '<span class="text-slate-400 font-normal">Tidak tercatat</span>';
                                html += '<div class="flex items-center justify-between gap-4 py-0.5 text-xs text-slate-600">'
                                    + '<span class="flex items-center gap-1.5">' + item.marker + ' ' + item.seriesName + '</span>'
                                    + '<span class="font-bold font-sans tabular-nums text-slate-900">' + valDisplay + '</span>'
                                    + '</div>';
                            });
                            return html;
                        }
                    },
                    legend: {
                        type: 'scroll',
                        top: 5,
                        textStyle: { fontSize: 11, color: pal.axisText }
                    },
                    grid: { top: 45, bottom: 40, left: '4%', right: '4%', containLabel: true },
                    xAxis: {
                        type: 'category',
                        data: upts,
                        axisLabel: { color: '#475569', fontSize: 11, fontWeight: 600 },
                        axisLine: { lineStyle: { color: pal.targetSlate } }
                    },
                    yAxis: {
                        type: 'value',
                        name: 'MVA',
                        nameTextStyle: { color: pal.axisText, fontSize: 11, padding: [0, 0, 4, 0] },
                        axisLabel: { color: pal.axisText, fontSize: 11 },
                        splitLine: { lineStyle: { color: pal.gridLine, type: 'dashed' } }
                    },
                    series: series
                };

                chart.setOption(option, true);
            },

            renderPhysicalChart() {
                const el = document.getElementById('asset-detail-trafo-physical-chart');
                if (!el) return;

                const chart = physicalInstance || (window.initSafeEChart ? window.initSafeEChart(el) : (window.echarts ? (window.echarts.getInstanceByDom(el) || window.echarts.init(el)) : null));
                if (!chart) return;
                physicalInstance = chart;

                const pal = getAssetPalette();
                const bankData = upts.map(u => {
                    const raw = bankByUpt ? bankByUpt[u] : null;
                    const isRecorded = raw !== null && raw !== undefined && raw !== '';
                    return { value: isRecorded ? Number(raw) : 0, isRecorded: isRecorded };
                });
                const unitData = upts.map(u => {
                    const raw = unitByUpt ? unitByUpt[u] : null;
                    const isRecorded = raw !== null && raw !== undefined && raw !== '';
                    return { value: isRecorded ? Number(raw) : 0, isRecorded: isRecorded };
                });

                const option = {
                    animation: true,
                    animationDuration: 650,
                    tooltip: {
                        trigger: 'axis',
                        axisPointer: { type: 'shadow' },
                        formatter: function(params) {
                            if (!params || !params.length) return '';
                            let html = '<div class="font-bold text-slate-800 border-b border-slate-100 pb-1 mb-1 text-xs">UPT ' + params[0].name + '</div>';
                            params.forEach(item => {
                                const isRecorded = item.data && item.data.isRecorded !== false && item.value !== null && item.value !== undefined;
                                const valDisplay = isRecorded
                                    ? Number(item.value).toLocaleString('id-ID')
                                    : '<span class="text-slate-400 font-normal">Tidak tercatat</span>';
                                html += '<div class="flex items-center justify-between gap-4 py-0.5 text-xs text-slate-600">'
                                    + '<span class="flex items-center gap-1.5">' + item.marker + ' ' + item.seriesName + '</span>'
                                    + '<span class="font-bold font-sans tabular-nums text-slate-900">' + valDisplay + '</span>'
                                    + '</div>';
                            });
                            return html;
                        }
                    },
                    legend: {
                        top: 5,
                        textStyle: { fontSize: 11, color: pal.axisText }
                    },
                    grid: { top: 45, bottom: 40, left: '4%', right: '4%', containLabel: true },
                    xAxis: {
                        type: 'category',
                        data: upts,
                        axisLabel: { color: '#475569', fontSize: 11, fontWeight: 600 },
                        axisLine: { lineStyle: { color: pal.targetSlate } }
                    },
                    yAxis: {
                        type: 'value',
                        name: 'Jumlah Fisik',
                        nameTextStyle: { color: pal.axisText, fontSize: 11, padding: [0, 0, 4, 0] },
                        axisLabel: { color: pal.axisText, fontSize: 11 },
                        splitLine: { lineStyle: { color: pal.gridLine, type: 'dashed' } }
                    },
                    series: [
                        {
                            name: 'Bank Fisik',
                            type: 'bar',
                            data: bankData,
                            barMaxWidth: 24,
                            itemStyle: { color: pal.primary, borderRadius: [6, 6, 0, 0] }
                        },
                        {
                            name: 'Unit / Set Fisik',
                            type: 'bar',
                            data: unitData,
                            barMaxWidth: 24,
                            itemStyle: { color: pal.accent, borderRadius: [6, 6, 0, 0] }
                        }
                    ]
                };

                chart.setOption(option, true);
            }
        };
    };
}

// Component 2: MVA Charts
if (typeof window.assetMvaCharts !== 'function') {
    window.assetMvaCharts = function(mvaByUpt, totalTrafoMva) {
        let rankedInstance = null;
        let donutInstance = null;
        const hasTotalMva = totalTrafoMva !== null && totalTrafoMva !== undefined && Number(totalTrafoMva) > 0;

        return {
            initCharts() {
                this.$nextTick(() => {
                    this.renderRankedChart();
                    this.renderDonutChart();
                });
            },

            renderRankedChart() {
                const el = document.getElementById('asset-mva-ranked-chart');
                if (!el) return;

                if (!window.echarts && !window.initSafeEChart) {
                    setTimeout(() => this.renderRankedChart(), 50);
                    return;
                }

                const chart = rankedInstance || (window.initSafeEChart ? window.initSafeEChart(el) : (window.echarts ? (window.echarts.getInstanceByDom(el) || window.echarts.init(el)) : null));
                if (!chart) return;
                rankedInstance = chart;

                const pal = getAssetPalette();
                const pairs = Object.entries(mvaByUpt || {}).sort((a, b) => {
                    const av = (a[1] === null || a[1] === undefined) ? -1 : Number(a[1]);
                    const bv = (b[1] === null || b[1] === undefined) ? -1 : Number(b[1]);
                    return av - bv;
                });
                const uptNames = pairs.map(p => p[0]);
                const rawVals = pairs.map(p => (p[1] === null || p[1] === undefined) ? 0 : Number(p[1]));
                const maxVal = rawVals.length ? Math.max(...rawVals) : 0;

                const option = {
                    animation: true,
                    animationDuration: 650,
                    tooltip: {
                        trigger: 'axis',
                        axisPointer: { type: 'shadow' },
                        formatter: function(params) {
                            if (!params || !params.length) return '';
                            const item = params[0];
                            const isRecorded = item.data && item.data.isRecorded !== false;
                            if (!isRecorded) {
                                return `<div class="p-1 text-xs font-sans">
                                    <span class="font-bold text-slate-800">UPT ${item.name}</span><br/>
                                    <span class="text-slate-400">Tidak tercatat</span>
                                </div>`;
                            }
                            const val = Number(item.value).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
                            const pct = hasTotalMva ? ((Number(item.value) / Number(totalTrafoMva)) * 100).toFixed(1) : '0.0';
                            return `<div class="p-1 text-xs font-sans tabular-nums">
                                <span class="font-bold text-slate-800">UPT ${item.name}</span><br/>
                                <span class="text-[#0b6175] font-bold">${val} MVA</span> (${pct}%)
                            </div>`;
                        }
                    },
                    grid: { top: 20, bottom: 25, left: '4%', right: '10%', containLabel: true },
                    xAxis: {
                        type: 'value',
                        name: 'MVA',
                        nameTextStyle: { color: pal.axisText, fontSize: 11 },
                        axisLabel: { color: pal.axisText, fontSize: 11 },
                        splitLine: { lineStyle: { color: pal.gridLine, type: 'dashed' } }
                    },
                    yAxis: {
                        type: 'category',
                        data: uptNames,
                        axisLabel: { color: '#334155', fontSize: 11, fontWeight: 600 },
                        axisLine: { lineStyle: { color: pal.targetSlate } }
                    },
                    series: [{
                        name: 'Kapasitas MVA',
                        type: 'bar',
                        data: pairs.map(([, raw]) => {
                            const isRecorded = raw !== null && raw !== undefined && raw !== '';
                            const v = isRecorded ? Number(raw) : 0;
                            return {
                                value: v,
                                isRecorded: isRecorded,
                                itemStyle: {
                                    color: v === maxVal && maxVal > 0 ? pal.primary : pal.softTeal,
                                    borderRadius: [0, 6, 6, 0]
                                }
                            };
                        }),
                        barMaxWidth: 20,
                        label: {
                            show: true,
                            position: 'right',
                            formatter: function(p) {
                                if (p.data && p.data.isRecorded === false) return 'Tidak tercatat';
                                return Number(p.value).toLocaleString('id-ID');
                            },
                            fontSize: 10,
                            fontFamily: 'Plus Jakarta Sans, Inter, sans-serif',
                            color: '#475569'
                        }
                    }]
                };

                chart.setOption(option, true);
            },

            renderDonutChart() {
                const el = document.getElementById('asset-mva-donut-chart');
                if (!el) return;

                const chart = donutInstance || (window.initSafeEChart ? window.initSafeEChart(el) : (window.echarts ? (window.echarts.getInstanceByDom(el) || window.echarts.init(el)) : null));
                if (!chart) return;
                donutInstance = chart;

                const pal = getAssetPalette();
                const pieData = Object.entries(mvaByUpt || {}).map(([name, val]) => {
                    const isRecorded = val !== null && val !== undefined && val !== '';
                    return { name: name, value: isRecorded ? Number(val) : 0, isRecorded: isRecorded };
                });

                const option = {
                    animation: true,
                    animationDuration: 650,
                    tooltip: {
                        trigger: 'item',
                        formatter: function(item) {
                            const isRecorded = item.data && item.data.isRecorded !== false;
                            if (!isRecorded) {
                                return `<div class="p-1 text-xs font-sans">
                                    <span class="font-bold text-slate-800">UPT ${item.name}</span><br/>
                                    <span class="text-slate-400">Tidak tercatat</span>
                                </div>`;
                            }
                            const val = Number(item.value).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
                            return `<div class="p-1 text-xs font-sans tabular-nums">
                                <span class="font-bold text-slate-800">UPT ${item.name}</span><br/>
                                <span class="text-slate-600">${val} MVA (${item.percent ?? 0}%)</span>
                            </div>`;
                        }
                    },
                    color: pal.donut,
                    series: [{
                        name: 'Pangsa MVA',
                        type: 'pie',
                        radius: ['46%', '72%'],
                        center: ['50%', '50%'],
                        avoidLabelOverlap: true,
                        itemStyle: { borderRadius: 4, borderColor: '#ffffff', borderWidth: 2 },
                        label: { show: true, position: 'outside', formatter: '{b}\n{d}%', fontSize: 10, color: '#475569' },
                        labelLine: { show: true, length: 8, length2: 8 },
                        data: pieData
                    }]
                };

                chart.setOption(option, true);
            }
        };
    };
}

// Component 3: Transmisi Charts
if (typeof window.assetTransmisiCharts !== 'function') {
    window.assetTransmisiCharts = function(transmisiByUpt, transmisiGroups, totalTransmisi) {
        let barInstance = null;
        let voltageInstance = null;

        return {
            initCharts() {
                this.$nextTick(() => {
                    this.renderBarChart();
                    this.renderVoltageChart();
                });
            },

            renderBarChart() {
                const el = document.getElementById('asset-transmisi-bar-chart');
                if (!el) return;

                if (!window.echarts && !window.initSafeEChart) {
                    setTimeout(() => this.renderBarChart(), 50);
                    return;
                }

                const chart = barInstance || (window.initSafeEChart ? window.initSafeEChart(el) : (window.echarts ? (window.echarts.getInstanceByDom(el) || window.echarts.init(el)) : null));
                if (!chart) return;
                barInstance = chart;

                const pal = getAssetPalette();
                const upts = Object.keys(transmisiByUpt || {});
                const suData = upts.map(u => {
                    const raw = transmisiByUpt[u]?.su_kms;
                    const isRecorded = raw !== null && raw !== undefined && raw !== '';
                    return { value: isRecorded ? Number(raw) : 0, isRecorded: isRecorded };
                });
                const skData = upts.map(u => {
                    const raw = transmisiByUpt[u]?.sk_kms;
                    const isRecorded = raw !== null && raw !== undefined && raw !== '';
                    return { value: isRecorded ? Number(raw) : 0, isRecorded: isRecorded };
                });

                const option = {
                    animation: true,
                    animationDuration: 650,
                    tooltip: {
                        trigger: 'axis',
                        axisPointer: { type: 'shadow' },
                        formatter: function(params) {
                            if (!params || !params.length) return '';
                            let html = '<div class="font-bold text-slate-800 border-b border-slate-100 pb-1 mb-1 text-xs">UPT ' + params[0].name + '</div>';
                            params.forEach(item => {
                                const isRecorded = item.data && item.data.isRecorded !== false && item.value !== null && item.value !== undefined;
                                const valDisplay = isRecorded
                                    ? Number(item.value).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' kms'
                                    : '<span class="text-slate-400 font-normal">Tidak tercatat</span>';
                                html += '<div class="flex items-center justify-between gap-4 py-0.5 text-xs text-slate-600">'
                                    + '<span class="flex items-center gap-1.5">' + item.marker + ' ' + item.seriesName + '</span>'
                                    + '<span class="font-bold font-sans tabular-nums text-slate-900">' + valDisplay + '</span>'
                                    + '</div>';
                            });
                            return html;
                        }
                    },
                    legend: { top: 5, textStyle: { fontSize: 11, color: pal.axisText } },
                    grid: { top: 45, bottom: 40, left: '4%', right: '4%', containLabel: true },
                    xAxis: {
                        type: 'category',
                        data: upts,
                        axisLabel: { color: '#475569', fontSize: 11, fontWeight: 600 },
                        axisLine: { lineStyle: { color: pal.targetSlate } }
                    },
                    yAxis: {
                        type: 'value',
                        name: 'kms',
                        nameTextStyle: { color: pal.axisText, fontSize: 11, padding: [0, 0, 4, 0] },
                        axisLabel: { color: pal.axisText, fontSize: 11 },
                        splitLine: { lineStyle: { color: pal.gridLine, type: 'dashed' } }
                    },
                    series: [
                        {
                            name: 'Saluran Udara (SU kms)',
                            type: 'bar',
                            stack: 'transmisi',
                            data: suData,
                            barMaxWidth: 30,
                            itemStyle: { color: pal.primary }
                        },
                        {
                            name: 'Saluran Kabel (SK kms)',
                            type: 'bar',
                            stack: 'transmisi',
                            data: skData,
                            barMaxWidth: 30,
                            itemStyle: { color: pal.accent, borderRadius: [6, 6, 0, 0] }
                        }
                    ]
                };

                chart.setOption(option, true);
            },

            renderVoltageChart() {
                const el = document.getElementById('asset-transmisi-voltage-chart');
                if (!el) return;

                const chart = voltageInstance || (window.initSafeEChart ? window.initSafeEChart(el) : (window.echarts ? (window.echarts.getInstanceByDom(el) || window.echarts.init(el)) : null));
                if (!chart) return;
                voltageInstance = chart;

                const pal = getAssetPalette();
                const pieData = (transmisiGroups || []).map(tg => {
                    const raw = tg.uit_total?.total_kms ?? tg.total_upt?.total_kms ?? null;
                    const isRecorded = raw !== null && raw !== undefined && raw !== '';
                    return {
                        name: tg.voltage,
                        value: isRecorded ? Number(raw) : 0,
                        isRecorded: isRecorded,
                    };
                });

                const option = {
                    animation: true,
                    animationDuration: 650,
                    tooltip: {
                        trigger: 'item',
                        formatter: function(item) {
                            const isRecorded = item.data && item.data.isRecorded !== false;
                            if (!isRecorded) {
                                return `<div class="p-1 text-xs font-sans">
                                    <span class="font-bold text-slate-800">${item.name}</span><br/>
                                    <span class="text-slate-400">Tidak tercatat</span>
                                </div>`;
                            }
                            const val = Number(item.value).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
                            return `<div class="p-1 text-xs font-sans tabular-nums">
                                <span class="font-bold text-slate-800">${item.name}</span><br/>
                                <span class="text-slate-600">${val} kms (${item.percent ?? 0}%)</span>
                            </div>`;
                        }
                    },
                    color: pal.series,
                    series: [{
                        name: 'Kelas Tegangan',
                        type: 'pie',
                        radius: ['46%', '72%'],
                        center: ['50%', '50%'],
                        avoidLabelOverlap: true,
                        itemStyle: { borderRadius: 4, borderColor: '#ffffff', borderWidth: 2 },
                        label: { show: true, position: 'outside', formatter: '{b}\n{d}%', fontSize: 10, color: '#475569' },
                        labelLine: { show: true, length: 8, length2: 8 },
                        data: pieData
                    }]
                };

                chart.setOption(option, true);
            }
        };
    };
}

// Component 4: Tower Charts
if (typeof window.assetTowerCharts !== 'function') {
    window.assetTowerCharts = function(towerGroups, towerByUpt, canonicalUpts, totalTower) {
        let barInstance = null;
        let pieInstance = null;

        return {
            initCharts() {
                this.$nextTick(() => {
                    this.renderBarChart();
                    this.renderPieChart();
                });
            },

            renderBarChart() {
                const el = document.getElementById('asset-tower-bar-chart');
                if (!el) return;

                if (!window.echarts && !window.initSafeEChart) {
                    setTimeout(() => this.renderBarChart(), 50);
                    return;
                }

                const chart = barInstance || (window.initSafeEChart ? window.initSafeEChart(el) : (window.echarts ? (window.echarts.getInstanceByDom(el) || window.echarts.init(el)) : null));
                if (!chart) return;
                barInstance = chart;

                const pal = getAssetPalette();
                const upts = canonicalUpts || [];

                const series = (towerGroups || []).map((tw, idx) => ({
                    name: tw.voltage,
                    type: 'bar',
                    data: upts.map(u => {
                        const raw = tw.by_upt?.[u]?.total_unit;
                        const isRecorded = raw !== null && raw !== undefined && raw !== '';
                        return { value: isRecorded ? Number(raw) : 0, isRecorded: isRecorded };
                    }),
                    barMaxWidth: 20,
                    itemStyle: {
                        color: pal.series[idx % pal.series.length],
                        borderRadius: [6, 6, 0, 0]
                    }
                }));

                const option = {
                    animation: true,
                    animationDuration: 650,
                    tooltip: {
                        trigger: 'axis',
                        axisPointer: { type: 'shadow' },
                        formatter: function(params) {
                            if (!params || !params.length) return '';
                            let html = '<div class="font-bold text-slate-800 border-b border-slate-100 pb-1 mb-1 text-xs">UPT ' + params[0].name + '</div>';
                            params.forEach(item => {
                                const isRecorded = item.data && item.data.isRecorded !== false && item.value !== null && item.value !== undefined;
                                const valDisplay = isRecorded
                                    ? Number(item.value).toLocaleString('id-ID') + ' Unit'
                                    : '<span class="text-slate-400 font-normal">Tidak tercatat</span>';
                                html += '<div class="flex items-center justify-between gap-4 py-0.5 text-xs text-slate-600">'
                                    + '<span class="flex items-center gap-1.5">' + item.marker + ' ' + item.seriesName + '</span>'
                                    + '<span class="font-bold font-sans tabular-nums text-slate-900">' + valDisplay + '</span>'
                                    + '</div>';
                            });
                            return html;
                        }
                    },
                    legend: { top: 5, textStyle: { fontSize: 11, color: pal.axisText } },
                    grid: { top: 45, bottom: 40, left: '4%', right: '4%', containLabel: true },
                    xAxis: {
                        type: 'category',
                        data: upts,
                        axisLabel: { color: '#475569', fontSize: 11, fontWeight: 600 },
                        axisLine: { lineStyle: { color: pal.targetSlate } }
                    },
                    yAxis: {
                        type: 'value',
                        name: 'Unit',
                        nameTextStyle: { color: pal.axisText, fontSize: 11, padding: [0, 0, 4, 0] },
                        axisLabel: { color: pal.axisText, fontSize: 11 },
                        splitLine: { lineStyle: { color: pal.gridLine, type: 'dashed' } }
                    },
                    series: series
                };

                chart.setOption(option, true);
            },

            renderPieChart() {
                const el = document.getElementById('asset-tower-pie-chart');
                if (!el) return;

                const chart = pieInstance || (window.initSafeEChart ? window.initSafeEChart(el) : (window.echarts ? (window.echarts.getInstanceByDom(el) || window.echarts.init(el)) : null));
                if (!chart) return;
                pieInstance = chart;

                const pal = getAssetPalette();
                const pieData = Object.entries(towerByUpt || {}).map(([name, val]) => {
                    const isRecorded = val !== null && val !== undefined && val !== '';
                    return { name: name, value: isRecorded ? Number(val) : 0, isRecorded: isRecorded };
                });

                const option = {
                    animation: true,
                    animationDuration: 650,
                    tooltip: {
                        trigger: 'item',
                        formatter: function(item) {
                            const isRecorded = item.data && item.data.isRecorded !== false;
                            if (!isRecorded) {
                                return `<div class="p-1 text-xs font-sans">
                                    <span class="font-bold text-slate-800">UPT ${item.name}</span><br/>
                                    <span class="text-slate-400">Tidak tercatat</span>
                                </div>`;
                            }
                            const val = Number(item.value).toLocaleString('id-ID');
                            return `<div class="p-1 text-xs font-sans tabular-nums">
                                <span class="font-bold text-slate-800">UPT ${item.name}</span><br/>
                                <span class="text-slate-600">${val} Unit (${item.percent ?? 0}%)</span>
                            </div>`;
                        }
                    },
                    color: pal.donut,
                    series: [{
                        name: 'Pangsa Tower',
                        type: 'pie',
                        radius: ['46%', '72%'],
                        center: ['50%', '50%'],
                        avoidLabelOverlap: true,
                        itemStyle: { borderRadius: 4, borderColor: '#ffffff', borderWidth: 2 },
                        label: { show: true, position: 'outside', formatter: '{b}\n{d}%', fontSize: 10, color: '#475569' },
                        labelLine: { show: true, length: 8, length2: 8 },
                        data: pieData
                    }]
                };

                chart.setOption(option, true);
            }
        };
    };
}

// Component 5: Interactive Matrix
if (typeof window.assetInteractiveMatrix !== 'function') {
    window.assetInteractiveMatrix = function(rows, upts, activeSection) {
        let initialGroup = 'all';
        if (activeSection === 'trafo' || activeSection === 'mva') {
            initialGroup = 'trafo';
        } else if (activeSection === 'transmisi') {
            initialGroup = 'transmisi';
        } else if (activeSection === 'tower') {
            initialGroup = 'tower';
        }

        return {
            rows: rows || [],
            upts: upts || [],
            selectedGroup: initialGroup,
            selectedUpt: 'all',
            search: '',

            isUnrecorded(val) {
                return val === undefined || val === null || val === '';
            },

            formatNumber(val) {
                if (this.isUnrecorded(val)) return 'Tidak tercatat';
                const n = Number(val);
                if (isNaN(n)) return String(val);
                return n % 1 !== 0
                    ? n.toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 })
                    : n.toLocaleString('id-ID');
            },

            get filteredRows() {
                return this.rows.filter(item => {
                    if (this.selectedGroup !== 'all' && item.group !== this.selectedGroup) {
                        return false;
                    }
                    if (this.search && !item.attribute.toLowerCase().includes(this.search.toLowerCase())) {
                        return false;
                    }
                    return true;
                });
            }
        };
    };
}
</script>
@endsection
