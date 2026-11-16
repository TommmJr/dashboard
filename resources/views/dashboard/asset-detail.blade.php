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
    $totalTower = (float) ($summary['total_tower'] ?? 0);
    $totalTransmisi = (float) ($summary['total_transmisi'] ?? 0);
    $totalTrafoMva = (float) ($summary['total_trafo_mva'] ?? 0);
    $totalTrafoBank = (float) ($summary['total_trafo_bank'] ?? 0);
    $totalTrafoUnit = (float) ($summary['total_trafo_unit'] ?? 0);
    $trafoGroups = $data['trafo_groups'] ?? [];
    $transmisiGroups = $data['transmisi_groups'] ?? [];
    $towerGroups = $data['tower_groups'] ?? [];
    $bayGroups = $data['bay_groups'] ?? [];
    $chartConfig = $data['chart_config'] ?? [];
    $interactiveMatrix = $data['interactive_matrix'] ?? [];
    $freshness = is_array($data['freshness'] ?? null) ? $data['freshness'] : null;

    $formattedPeriod = 'Tidak tercatat';
    if (!empty($data['period'])) {
        try {
            $months = [
                1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
                7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
            ];
            $dt = \Carbon\Carbon::parse($data['period']);
            $formattedPeriod = ($months[$dt->month] ?? $dt->format('M')).' '.$dt->year;
        } catch (\Throwable $e) {
            $formattedPeriod = 'Tidak tercatat';
        }
    }

    // Helper kalkulasi daya MVA per UPT
    $mvaByUpt = [];
    foreach ($canonicalUpts as $upt) {
        $uptMva = 0.0;
        foreach ($trafoGroups as $tg) {
            $uptMva += (float) ($tg['by_upt'][$upt]['mva'] ?? 0);
        }
        $mvaByUpt[$upt] = round($uptMva, 1);
    }
    $rankedMva = $mvaByUpt;
    arsort($rankedMva);

    // Helper kalkulasi fisik trafo
    $bankByUpt = [];
    $unitByUpt = [];
    foreach ($canonicalUpts as $upt) {
        $b = 0.0;
        $u = 0.0;
        foreach ($trafoGroups as $tg) {
            $b += (float) ($tg['by_upt'][$upt]['bank'] ?? 0);
            $u += (float) ($tg['by_upt'][$upt]['unit'] ?? 0);
        }
        $bankByUpt[$upt] = round($b, 0);
        $unitByUpt[$upt] = round($u, 0);
    }

    // Helper kalkulasi transmisi
    $transmisiByUpt = [];
    foreach ($canonicalUpts as $upt) {
        $su = 0.0;
        $sk = 0.0;
        foreach ($transmisiGroups as $tr) {
            $su += (float) ($tr['by_upt'][$upt]['su_kms'] ?? 0);
            $sk += (float) ($tr['by_upt'][$upt]['sk_kms'] ?? 0);
        }
        $transmisiByUpt[$upt] = [
            'su_kms' => round($su, 1),
            'sk_kms' => round($sk, 1),
            'total_kms' => round($su + $sk, 1),
        ];
    }

    // Helper kalkulasi tower
    $towerByUpt = [];
    foreach ($canonicalUpts as $upt) {
        $tot = 0.0;
        foreach ($towerGroups as $tw) {
            $tot += (float) ($tw['by_upt'][$upt]['total_unit'] ?? 0);
        }
        $towerByUpt[$upt] = round($tot, 0);
    }
@endphp

<div class="space-y-5 min-w-0 max-w-full">
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
                @if (!empty($data['period']))
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

        <div class="flex items-center gap-2">
            <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition shadow-2xs">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M19 12H5M12 19l-7-7 7-7" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                Kembali ke Beranda
            </a>
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
        @if (!empty($data['period'])) Periode {{ $data['period'] }} @endif
    </span>

    {{-- Toolbar Tunggal: 4 Navigasi Seksi --}}
    <div class="ibs-card min-w-0 max-w-full p-3.5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-1.5">
                <a href="{{ route('dashboard.asset-detail', ['section' => 'trafo']) }}"
                   class="pill-tab-transition inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-semibold {{ $section === 'trafo' ? 'bg-[#0b6175] text-white shadow-2xs' : 'bg-slate-100/80 text-slate-700 hover:bg-slate-200/70' }}">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <rect x="3" y="6" width="18" height="12" rx="2" />
                        <path d="M7 12h2m6 0h2M12 9v6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    Bedah Trafo & Tegangan
                </a>
                <a href="{{ route('dashboard.asset-detail', ['section' => 'mva']) }}"
                   class="pill-tab-transition inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-semibold {{ $section === 'mva' ? 'bg-[#0b6175] text-white shadow-2xs' : 'bg-slate-100/80 text-slate-700 hover:bg-slate-200/70' }}">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    Distribusi Daya (MVA)
                </a>
                <a href="{{ route('dashboard.asset-detail', ['section' => 'transmisi']) }}"
                   class="pill-tab-transition inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-semibold {{ $section === 'transmisi' ? 'bg-[#0b6175] text-white shadow-2xs' : 'bg-slate-100/80 text-slate-700 hover:bg-slate-200/70' }}">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M22 12h-4l-3 9L9 3l-3 9H2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    Jaringan Transmisi (kms)
                </a>
                <a href="{{ route('dashboard.asset-detail', ['section' => 'tower']) }}"
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
                    <span class="ibs-metric-value text-2xl sm:text-3xl" x-data="numberTicker('{{ number_format($totalTrafoMva, 1, ',', '.') }}')" x-text="display">
                        {{ number_format($totalTrafoMva, 1, ',', '.') }}
                    </span>
                    <span class="text-xs font-medium text-slate-500">MVA</span>
                </div>
            </div>

            {{-- Col 2: Fisik Trafo Terpasang --}}
            <div class="p-5">
                <div class="flex items-center justify-between gap-2">
                    <span class="ibs-metric-label">Fisik Trafo Terpasang</span>
                    <span class="rounded-md bg-[#e6f4f6] px-2 py-0.5 text-[11px] font-semibold text-[#0b6175]">Bank / Unit</span>
                </div>
                <div class="mt-2.5 flex items-baseline gap-1.5">
                    <span class="ibs-metric-value text-2xl" x-data="numberTicker('{{ number_format($totalTrafoBank, 0, ',', '.') }}')" x-text="display">
                        {{ number_format($totalTrafoBank, 0, ',', '.') }}
                    </span>
                    <span class="text-xs font-medium text-slate-500">Bank</span>
                    <span class="text-slate-300">/</span>
                    <span class="ibs-metric-value text-2xl" x-data="numberTicker('{{ number_format($totalTrafoUnit, 0, ',', '.') }}')" x-text="display">
                        {{ number_format($totalTrafoUnit, 0, ',', '.') }}
                    </span>
                    <span class="text-xs font-medium text-slate-500">Unit</span>
                </div>
            </div>

            {{-- Col 3: Panjang Transmisi --}}
            <div class="p-5">
                <div class="flex items-center justify-between gap-2">
                    <span class="ibs-metric-label">Panjang Transmisi</span>
                    <span class="rounded-md bg-[#e6f4f6] px-2 py-0.5 text-[11px] font-semibold text-[#0b6175]">kms</span>
                </div>
                <div class="mt-2.5 flex items-baseline gap-1.5">
                    <span class="ibs-metric-value text-2xl sm:text-3xl" x-data="numberTicker('{{ number_format($totalTransmisi, 1, ',', '.') }}')" x-text="display">
                        {{ number_format($totalTransmisi, 1, ',', '.') }}
                    </span>
                    <span class="text-xs font-medium text-slate-500">kms</span>
                </div>
            </div>

            {{-- Col 4: Total Tower --}}
            <div class="p-5">
                <div class="flex items-center justify-between gap-2">
                    <span class="ibs-metric-label">Total Tower</span>
                    <span class="rounded-md bg-[#e6f4f6] px-2 py-0.5 text-[11px] font-semibold text-[#0b6175]">Unit</span>
                </div>
                <div class="mt-2.5 flex items-baseline gap-1.5">
                    <span class="ibs-metric-value text-2xl sm:text-3xl" x-data="numberTicker('{{ number_format($totalTower, 0, ',', '.') }}')" x-text="display">
                        {{ number_format($totalTower, 0, ',', '.') }}
                    </span>
                    <span class="text-xs font-medium text-slate-500">Unit</span>
                </div>
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
                        $tgTotalMva = (float) ($tg['uit_total']['mva'] ?? $tg['total_upt']['mva'] ?? 0);
                        $tgTotalBank = (float) ($tg['uit_total']['bank'] ?? $tg['total_upt']['bank'] ?? 0);
                        $tgTotalUnit = (float) ($tg['uit_total']['unit'] ?? $tg['total_upt']['unit'] ?? 0);
                        $hasUptBank = (bool) ($tg['has_upt_bank'] ?? true);
                    @endphp
                    <div class="rounded-xl border border-slate-200/80 overflow-hidden">
                        <div class="flex flex-wrap items-center justify-between gap-2 bg-slate-50/70 px-4 py-3 border-b border-slate-200/80">
                            <div class="flex min-w-0 items-center gap-2">
                                <span class="h-2 w-2 shrink-0 rounded-full bg-[#0b6175]"></span>
                                <h3 class="text-sm font-semibold text-slate-800">{{ $tg['label'] }} ({{ $tg['voltage'] }})</h3>
                            </div>
                            <div class="flex flex-wrap items-center gap-3 text-xs font-sans tabular-nums text-slate-600">
                                <span><strong class="text-slate-900">{{ number_format($tgTotalMva, 1, ',', '.') }}</strong> MVA</span>
                                <span class="text-slate-300">|</span>
                                <span><strong class="text-slate-900">{{ number_format($tgTotalBank, 0, ',', '.') }}</strong> Bank</span>
                                <span class="text-slate-300">|</span>
                                <span><strong class="text-slate-900">{{ number_format($tgTotalUnit, 0, ',', '.') }}</strong> Unit</span>
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
                                            $row = $tg['by_upt'][$upt] ?? ['mva' => 0, 'bank' => 0, 'unit' => 0];
                                            $mvaShare = $tgTotalMva > 0 ? min(100, round(($row['mva'] / $tgTotalMva) * 100, 1)) : 0;
                                        @endphp
                                        <tr class="hover:bg-slate-50/60 transition">
                                            <td class="py-2 px-4 font-semibold text-slate-800">{{ $upt }}</td>
                                            <td class="py-2 px-4 text-right font-sans tabular-nums text-slate-700">
                                                <div class="flex items-center justify-end gap-2.5">
                                                    <div class="hidden sm:block w-16 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                                                        <div class="h-full rounded-full bg-[#0b6175]" style="width: {{ $mvaShare }}%;"></div>
                                                    </div>
                                                    <span class="min-w-[3.5rem] text-right">{{ number_format($row['mva'], 1, ',', '.') }}</span>
                                                </div>
                                            </td>
                                            <td class="py-2 px-4 text-right font-sans tabular-nums text-slate-700">
                                                @if ($hasUptBank)
                                                    {{ number_format($row['bank'], 0, ',', '.') }}
                                                @else
                                                    <span class="text-slate-400">Tidak dirinci</span>
                                                @endif
                                            </td>
                                            <td class="py-2 px-4 text-right font-sans tabular-nums text-slate-700">
                                                {{ number_format($row['unit'], 0, ',', '.') }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="border-t border-slate-200 bg-slate-50/70 font-semibold text-slate-900">
                                    <tr>
                                        <td class="py-2.5 px-4 text-left font-bold text-slate-900">TOTAL UIT JBT</td>
                                        <td class="py-2.5 px-4 text-right font-sans tabular-nums font-bold text-slate-900">
                                            {{ number_format($tgTotalMva, 1, ',', '.') }} MVA
                                        </td>
                                        <td class="py-2.5 px-4 text-right font-sans tabular-nums font-bold text-slate-900">
                                            {{ number_format($tgTotalBank, 0, ',', '.') }} Bank
                                        </td>
                                        <td class="py-2.5 px-4 text-right font-sans tabular-nums font-bold text-slate-900">
                                            {{ number_format($tgTotalUnit, 0, ',', '.') }} Unit
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
                        {{ number_format($totalTransmisi, 1, ',', '.') }} kms
                    </span>
                </div>
                <div class="space-y-4">
                    @foreach ($transmisiGroups as $tr)
                        @php
                            $trTotalKms = (float) ($tr['uit_total']['total_kms'] ?? $tr['total_upt']['total_kms'] ?? 0);
                        @endphp
                        <div class="rounded-xl border border-slate-100 overflow-hidden">
                            <div class="bg-slate-50/70 px-3.5 py-2 text-xs font-semibold text-slate-700 flex justify-between">
                                <span>{{ $tr['label'] }} ({{ $tr['voltage'] }})</span>
                                <span class="font-sans tabular-nums font-bold text-slate-900">{{ number_format($trTotalKms, 1, ',', '.') }} kms</span>
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
                                            @php $r = $tr['by_upt'][$upt] ?? ['su_kms' => 0, 'sk_kms' => 0, 'total_kms' => 0]; @endphp
                                            <tr class="hover:bg-slate-50/50">
                                                <td class="py-1.5 px-3 font-medium text-slate-700">{{ $upt }}</td>
                                                <td class="py-1.5 px-3 text-right font-sans tabular-nums text-slate-600">{{ number_format($r['su_kms'], 1, ',', '.') }}</td>
                                                <td class="py-1.5 px-3 text-right font-sans tabular-nums text-slate-600">{{ number_format($r['sk_kms'], 1, ',', '.') }}</td>
                                                <td class="py-1.5 px-3 text-right font-sans tabular-nums font-semibold text-slate-800">{{ number_format($r['total_kms'], 1, ',', '.') }}</td>
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
                        {{ number_format($totalTower, 0, ',', '.') }} Unit
                    </span>
                </div>
                <div class="space-y-4">
                    @foreach ($towerGroups as $tw)
                        @php
                            $twTotalUnit = (float) ($tw['uit_total']['total_unit'] ?? $tw['total_upt']['total_unit'] ?? 0);
                        @endphp
                        <div class="rounded-xl border border-slate-100 overflow-hidden">
                            <div class="bg-slate-50/70 px-3.5 py-2 text-xs font-semibold text-slate-700 flex justify-between">
                                <span>{{ $tw['label'] }} ({{ $tw['voltage'] }})</span>
                                <span class="font-sans tabular-nums font-bold text-slate-900">{{ number_format($twTotalUnit, 0, ',', '.') }} Unit</span>
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
                                            @php $r = $tw['by_upt'][$upt] ?? ['su_unit' => 0, 'sk_unit' => 0, 'total_unit' => 0]; @endphp
                                            <tr class="hover:bg-slate-50/50">
                                                <td class="py-1.5 px-3 font-medium text-slate-700">{{ $upt }}</td>
                                                <td class="py-1.5 px-3 text-right font-sans tabular-nums text-slate-600">{{ number_format($r['su_unit'], 0, ',', '.') }}</td>
                                                <td class="py-1.5 px-3 text-right font-sans tabular-nums text-slate-600">{{ number_format($r['sk_unit'], 0, ',', '.') }}</td>
                                                <td class="py-1.5 px-3 text-right font-sans tabular-nums font-semibold text-slate-800">{{ number_format($r['total_unit'], 0, ',', '.') }}</td>
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
                        {{ number_format($totalTrafoMva, 1, ',', '.') }} MVA
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
                    @endphp
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @foreach ($rankedMva as $upt => $totMva)
                            @php
                                $mva500 = (float) ($tg500['by_upt'][$upt]['mva'] ?? 0);
                                $mva150_70 = (float) ($tg150_70['by_upt'][$upt]['mva'] ?? 0);
                                $mva150_20 = (float) ($tg150_20['by_upt'][$upt]['mva'] ?? 0);
                                $pct = $totalTrafoMva > 0 ? min(100, round(($totMva / $totalTrafoMva) * 100, 1)) : 0;
                            @endphp
                            <tr class="hover:bg-slate-50/60 transition">
                                <td class="py-2.5 px-4 font-semibold text-slate-800">{{ $upt }}</td>
                                <td class="py-2.5 px-4 text-right font-sans tabular-nums text-slate-700">{{ number_format($mva500, 1, ',', '.') }}</td>
                                <td class="py-2.5 px-4 text-right font-sans tabular-nums text-slate-700">{{ number_format($mva150_70, 1, ',', '.') }}</td>
                                <td class="py-2.5 px-4 text-right font-sans tabular-nums text-slate-700">{{ number_format($mva150_20, 1, ',', '.') }}</td>
                                <td class="py-2.5 px-4 text-right font-sans tabular-nums font-bold text-slate-900">{{ number_format($totMva, 1, ',', '.') }}</td>
                                <td class="py-2.5 px-4 text-right font-sans tabular-nums">
                                    <div class="flex items-center justify-end gap-2.5">
                                        <div class="w-16 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                                            <div class="h-full rounded-full bg-[#0b6175]" style="width: {{ $pct }}%;"></div>
                                        </div>
                                        <span class="font-semibold text-slate-700 min-w-[2.75rem] text-right">{{ $pct }}%</span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="border-t border-slate-200 bg-slate-50/70 font-semibold text-slate-900">
                        <tr>
                            <td class="py-2.5 px-4 text-left font-bold text-slate-900">TOTAL UIT JBT</td>
                            <td class="py-2.5 px-4 text-right font-sans tabular-nums font-bold text-slate-900">
                                {{ number_format((float) ($tg500['uit_total']['mva'] ?? $tg500['total_upt']['mva'] ?? 0), 1, ',', '.') }}
                            </td>
                            <td class="py-2.5 px-4 text-right font-sans tabular-nums font-bold text-slate-900">
                                {{ number_format((float) ($tg150_70['uit_total']['mva'] ?? $tg150_70['total_upt']['mva'] ?? 0), 1, ',', '.') }}
                            </td>
                            <td class="py-2.5 px-4 text-right font-sans tabular-nums font-bold text-slate-900">
                                {{ number_format((float) ($tg150_20['uit_total']['mva'] ?? $tg150_20['total_upt']['mva'] ?? 0), 1, ',', '.') }}
                            </td>
                            <td class="py-2.5 px-4 text-right font-sans tabular-nums font-bold text-slate-900">
                                {{ number_format($totalTrafoMva, 1, ',', '.') }} MVA
                            </td>
                            <td class="py-2.5 px-4 text-right font-sans tabular-nums font-bold text-slate-900">100,0%</td>
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
                        {{ number_format($totalTransmisi, 1, ',', '.') }} kms
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
                    $trTotKms = (float) ($tr['uit_total']['total_kms'] ?? $tr['total_upt']['total_kms'] ?? 0);
                @endphp
                <div class="rounded-xl border border-slate-200/80 overflow-hidden">
                    <div class="flex items-center justify-between bg-slate-50/70 px-4 py-2.5 border-b border-slate-200/80">
                        <span class="text-xs font-semibold text-slate-800">{{ $tr['label'] }} ({{ $tr['voltage'] }})</span>
                        <span class="text-xs font-sans tabular-nums font-bold text-slate-900">{{ number_format($trTotKms, 1, ',', '.') }} kms</span>
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
                                        $r = $tr['by_upt'][$upt] ?? ['su_kms' => 0, 'sk_kms' => 0, 'total_kms' => 0];
                                        $share = $trTotKms > 0 ? min(100, round(($r['total_kms'] / $trTotKms) * 100, 1)) : 0;
                                    @endphp
                                    <tr class="hover:bg-slate-50/50">
                                        <td class="py-2 px-4 font-medium text-slate-800">{{ $upt }}</td>
                                        <td class="py-2 px-4 text-right font-sans tabular-nums text-slate-600">{{ number_format($r['su_kms'], 1, ',', '.') }}</td>
                                        <td class="py-2 px-4 text-right font-sans tabular-nums text-slate-600">{{ number_format($r['sk_kms'], 1, ',', '.') }}</td>
                                        <td class="py-2 px-4 text-right font-sans tabular-nums font-bold text-slate-800">{{ number_format($r['total_kms'], 1, ',', '.') }}</td>
                                        <td class="py-2 px-4 text-right font-sans tabular-nums">
                                            <div class="flex items-center justify-end gap-2">
                                                <div class="w-14 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                                                    <div class="h-full rounded-full bg-[#16a3b8]" style="width: {{ $share }}%;"></div>
                                                </div>
                                                <span class="text-slate-600 min-w-[2.5rem] text-right">{{ $share }}%</span>
                                            </div>
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
                        {{ number_format($totalTower, 0, ',', '.') }} Unit
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
                    $twTotUnit = (float) ($tw['uit_total']['total_unit'] ?? $tw['total_upt']['total_unit'] ?? 0);
                @endphp
                <div class="rounded-xl border border-slate-200/80 overflow-hidden">
                    <div class="flex items-center justify-between bg-slate-50/70 px-4 py-2.5 border-b border-slate-200/80">
                        <span class="text-xs font-semibold text-slate-800">{{ $tw['label'] }} ({{ $tw['voltage'] }})</span>
                        <span class="text-xs font-sans tabular-nums font-bold text-slate-900">{{ number_format($twTotUnit, 0, ',', '.') }} Unit</span>
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
                                        $r = $tw['by_upt'][$upt] ?? ['su_unit' => 0, 'sk_unit' => 0, 'total_unit' => 0];
                                        $share = $twTotUnit > 0 ? min(100, round(($r['total_unit'] / $twTotUnit) * 100, 1)) : 0;
                                    @endphp
                                    <tr class="hover:bg-slate-50/50">
                                        <td class="py-2 px-4 font-medium text-slate-800">{{ $upt }}</td>
                                        <td class="py-2 px-4 text-right font-sans tabular-nums text-slate-600">{{ number_format($r['su_unit'], 0, ',', '.') }}</td>
                                        <td class="py-2 px-4 text-right font-sans tabular-nums text-slate-600">{{ number_format($r['sk_unit'], 0, ',', '.') }}</td>
                                        <td class="py-2 px-4 text-right font-sans tabular-nums font-bold text-slate-800">{{ number_format($r['total_unit'], 0, ',', '.') }}</td>
                                        <td class="py-2 px-4 text-right font-sans tabular-nums">
                                            <div class="flex items-center justify-end gap-2">
                                                <div class="w-14 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                                                    <div class="h-full rounded-full bg-[#0b6175]" style="width: {{ $share }}%;"></div>
                                                </div>
                                                <span class="text-slate-600 min-w-[2.5rem] text-right">{{ $share }}%</span>
                                            </div>
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
                                    <td class="py-2 px-3 text-right font-sans tabular-nums text-slate-700" x-text="formatNumber(item.values[upt] || 0)"></td>
                                </template>
                            </template>
                            <template x-if="selectedUpt !== 'all'">
                                <td class="py-2 px-4 text-right font-sans tabular-nums text-slate-700" x-text="formatNumber(item.values[selectedUpt] || 0)"></td>
                            </template>
                            <td class="py-2 px-4 text-right font-sans tabular-nums font-bold text-slate-900" x-text="formatNumber(item.total)"></td>
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
    return {
        ...p,
        series: [p.primary, p.accent, '#52b2bf', p.softTeal, '#94a3b8', p.targetSlate],
        donut: [p.primary, p.accent, '#52b2bf', '#84cdd6', p.softTeal, '#64748b', '#94a3b8', p.targetSlate],
    };
};

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
                    const maxVal = mvaSeries.length ? Math.max(...mvaSeries.map(Number)) : 0;
                    series = [{
                        name: 'Total Kapasitas (MVA)',
                        type: 'bar',
                        data: mvaSeries.map(v => ({
                            value: v,
                            itemStyle: {
                                color: Number(v) === maxVal && maxVal > 0 ? pal.primary : pal.softTeal,
                                borderRadius: [6, 6, 0, 0]
                            }
                        })),
                        barMaxWidth: 36,
                    }];
                } else {
                    series = voltageBreakdown.map((item, idx) => ({
                        name: item.name,
                        type: 'bar',
                        data: item.data,
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
                                const val = Number(item.value).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
                                html += '<div class="flex items-center justify-between gap-4 py-0.5 text-xs text-slate-600">'
                                    + '<span class="flex items-center gap-1.5">' + item.marker + ' ' + item.seriesName + '</span>'
                                    + '<span class="font-bold font-sans tabular-nums text-slate-900">' + val + ' MVA</span>'
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
                const bankData = upts.map(u => bankByUpt[u] || 0);
                const unitData = upts.map(u => unitByUpt[u] || 0);

                const option = {
                    animation: true,
                    animationDuration: 650,
                    tooltip: {
                        trigger: 'axis',
                        axisPointer: { type: 'shadow' }
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
                            itemStyle: { color: pal.softTeal, borderRadius: [6, 6, 0, 0] }
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
                const pairs = Object.entries(mvaByUpt || {}).sort((a, b) => a[1] - b[1]);
                const uptNames = pairs.map(p => p[0]);
                const rawVals = pairs.map(p => Number(p[1]));
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
                            const val = Number(item.value).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
                            const pct = totalTrafoMva > 0 ? ((Number(item.value) / totalTrafoMva) * 100).toFixed(1) : 0;
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
                        data: rawVals.map(v => ({
                            value: v,
                            itemStyle: {
                                color: v === maxVal && maxVal > 0 ? pal.primary : pal.softTeal,
                                borderRadius: [0, 6, 6, 0]
                            }
                        })),
                        barMaxWidth: 20,
                        label: {
                            show: true,
                            position: 'right',
                            formatter: function(p) { return Number(p.value).toLocaleString('id-ID'); },
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
                const pieData = Object.entries(mvaByUpt || {}).map(([name, val]) => ({ name, value: val }));

                const option = {
                    animation: true,
                    animationDuration: 650,
                    tooltip: {
                        trigger: 'item',
                        formatter: function(item) {
                            const val = Number(item.value).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
                            return `<div class="p-1 text-xs font-sans tabular-nums">
                                <span class="font-bold text-slate-800">UPT ${item.name}</span><br/>
                                <span class="text-slate-600">${val} MVA (${item.percent}%)</span>
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
                const suData = upts.map(u => transmisiByUpt[u]?.su_kms || 0);
                const skData = upts.map(u => transmisiByUpt[u]?.sk_kms || 0);

                const option = {
                    animation: true,
                    animationDuration: 650,
                    tooltip: {
                        trigger: 'axis',
                        axisPointer: { type: 'shadow' }
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
                const pieData = (transmisiGroups || []).map(tg => ({
                    name: tg.voltage,
                    value: Number(tg.uit_total?.total_kms ?? tg.total_upt?.total_kms ?? 0)
                }));

                const option = {
                    animation: true,
                    animationDuration: 650,
                    tooltip: {
                        trigger: 'item',
                        formatter: function(item) {
                            const val = Number(item.value).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
                            return `<div class="p-1 text-xs font-sans tabular-nums">
                                <span class="font-bold text-slate-800">${item.name}</span><br/>
                                <span class="text-slate-600">${val} kms (${item.percent}%)</span>
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
                    data: upts.map(u => tw.by_upt?.[u]?.total_unit || 0),
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
                        axisPointer: { type: 'shadow' }
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
                const pieData = Object.entries(towerByUpt || {}).map(([name, val]) => ({ name, value: val }));

                const option = {
                    animation: true,
                    animationDuration: 650,
                    tooltip: {
                        trigger: 'item',
                        formatter: function(item) {
                            const val = Number(item.value).toLocaleString('id-ID');
                            return `<div class="p-1 text-xs font-sans tabular-nums">
                                <span class="font-bold text-slate-800">UPT ${item.name}</span><br/>
                                <span class="text-slate-600">${val} Unit (${item.percent}%)</span>
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

            formatNumber(val) {
                if (val === undefined || val === null || val === '') return '0';
                const n = Number(val);
                if (isNaN(n)) return val;
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
