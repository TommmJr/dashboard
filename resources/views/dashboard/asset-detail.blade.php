@extends('layouts.app')

@section('title', 'Rincian Trafo & Aset')

@section('content')
@php
    $section = $section ?? 'trafo';
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
    $totalSuKms = array_sum(array_column($transmisiByUpt, 'su_kms'));
    $totalSkKms = array_sum(array_column($transmisiByUpt, 'sk_kms'));

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

<div class="space-y-6">
    {{-- Header Halaman --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between border-b border-slate-200 pb-5">
        <div>
            <div class="flex flex-wrap items-center gap-2.5">
                <h1 class="text-2xl font-bold tracking-tight text-primary">Rincian Trafo & Stok Aset</h1>
                <span class="inline-flex items-center rounded-full bg-slate-100 border border-slate-200 px-2.5 py-0.5 text-xs font-semibold text-slate-700">
                    Prototipe Demo (Rekapitulasi UPT)
                </span>
            </div>
            <p class="mt-1 text-sm text-slate-500">
                Eksplorasi visual dan analisis granular inventaris aset transmisi dan gardu induk se-Jawa Bagian Tengah.
                @if (!empty($data['period']))
                    <span class="font-medium text-slate-700">— Periode {{ $data['period'] }}</span>
                @endif
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition shadow-2xs">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M19 12H5M12 19l-7-7 7-7" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                Kembali ke Beranda
            </a>
        </div>
    </div>

    {{-- Sub-Navigation Pills (4 Halaman Eksplorasi Aset) --}}
    <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 pb-3">
        <a href="{{ route('dashboard.asset-detail', ['section' => 'trafo']) }}"
           class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-xs font-semibold transition {{ $section === 'trafo' ? 'bg-primary text-white shadow-xs' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50' }}">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <rect x="3" y="6" width="18" height="12" rx="2" />
                <path d="M7 12h2m6 0h2M12 9v6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            Bedah Trafo & Tegangan
        </a>
        <a href="{{ route('dashboard.asset-detail', ['section' => 'mva']) }}"
           class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-xs font-semibold transition {{ $section === 'mva' ? 'bg-primary text-white shadow-xs' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50' }}">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            Distribusi Daya (MVA)
        </a>
        <a href="{{ route('dashboard.asset-detail', ['section' => 'transmisi']) }}"
           class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-xs font-semibold transition {{ $section === 'transmisi' ? 'bg-primary text-white shadow-xs' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50' }}">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path d="M22 12h-4l-3 9L9 3l-3 9H2" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            Jaringan Transmisi (kms)
        </a>
        <a href="{{ route('dashboard.asset-detail', ['section' => 'tower']) }}"
           class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-xs font-semibold transition {{ $section === 'tower' ? 'bg-primary text-white shadow-xs' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50' }}">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path d="M12 2v20M6 8l12 0M4 14l16 0M8 22l4-20 4 20" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            Sebaran Tower (Unit)
        </a>
    </div>

    {{-- Scorecards Utama --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {{-- Card 1: Total Kapasitas Trafo (MVA) --}}
        <div class="relative overflow-hidden rounded-xl border border-slate-200 bg-white p-5 shadow-2xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Total Kapasitas Trafo</span>
                <span class="inline-flex items-center rounded-md bg-indigo-50 px-2 py-0.5 text-[11px] font-bold text-indigo-700 border border-indigo-200/60">
                    MVA
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-1.5">
                <span class="text-3xl font-extrabold tracking-tight text-slate-900" x-data="numberTicker('{{ number_format($totalTrafoMva, 1, ',', '.') }}')" x-text="display">
                    {{ number_format($totalTrafoMva, 1, ',', '.') }}
                </span>
                <span class="text-xs font-semibold text-slate-500">MVA</span>
            </div>
            <div class="mt-2.5 flex items-center gap-2">
                <div class="h-1.5 flex-1 rounded-full bg-slate-100 overflow-hidden">
                    <div class="h-full rounded-full bg-indigo-600" style="width: 100%;"></div>
                </div>
                <span class="text-[10px] font-semibold text-slate-400">Total Register UIT JBT</span>
            </div>
        </div>

        {{-- Card 2: Total Fisik Trafo (Bank & Unit) --}}
        <div class="relative overflow-hidden rounded-xl border border-slate-200 bg-white p-5 shadow-2xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Fisik Trafo Terpasang</span>
                <span class="inline-flex items-center rounded-md bg-teal-50 px-2 py-0.5 text-[11px] font-bold text-teal-700 border border-teal-200/60">
                    Bank / Unit
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-2xl font-extrabold tracking-tight text-slate-900" x-data="numberTicker('{{ number_format($totalTrafoBank, 0, ',', '.') }}')" x-text="display">
                    {{ number_format($totalTrafoBank, 0, ',', '.') }}
                </span>
                <span class="text-xs font-medium text-slate-500">Bank</span>
                <span class="text-slate-300">/</span>
                <span class="text-2xl font-extrabold tracking-tight text-slate-900" x-data="numberTicker('{{ number_format($totalTrafoUnit, 0, ',', '.') }}')" x-text="display">
                    {{ number_format($totalTrafoUnit, 0, ',', '.') }}
                </span>
                <span class="text-xs font-medium text-slate-500">Unit</span>
            </div>
            <div class="mt-2.5 flex items-center gap-2">
                <div class="h-1.5 flex-1 rounded-full bg-slate-100 overflow-hidden">
                    <div class="h-full rounded-full bg-teal-600" style="width: 100%;"></div>
                </div>
                <span class="text-[10px] font-semibold text-slate-400">Fisik Terpisah dari MVA</span>
            </div>
        </div>

        {{-- Card 3: Total Transmisi --}}
        <div class="relative overflow-hidden rounded-xl border border-slate-200 bg-white p-5 shadow-2xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Panjang Transmisi</span>
                <span class="inline-flex items-center rounded-md bg-sky-50 px-2 py-0.5 text-[11px] font-bold text-sky-700 border border-sky-200/60">
                    kms
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-1.5">
                <span class="text-3xl font-extrabold tracking-tight text-slate-900" x-data="numberTicker('{{ number_format($totalTransmisi, 1, ',', '.') }}')" x-text="display">
                    {{ number_format($totalTransmisi, 1, ',', '.') }}
                </span>
                <span class="text-xs font-semibold text-slate-500">kms</span>
            </div>
            <div class="mt-2.5 flex items-center gap-2">
                <div class="h-1.5 flex-1 rounded-full bg-slate-100 overflow-hidden">
                    <div class="h-full rounded-full bg-sky-600" style="width: 100%;"></div>
                </div>
                <span class="text-[10px] font-semibold text-slate-400">Saluran Udara & Kabel</span>
            </div>
        </div>

        {{-- Card 4: Total Tower --}}
        <div class="relative overflow-hidden rounded-xl border border-slate-200 bg-white p-5 shadow-2xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Total Tower</span>
                <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-700 border border-slate-200">
                    Unit
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-1.5">
                <span class="text-3xl font-extrabold tracking-tight text-slate-900" x-data="numberTicker('{{ number_format($totalTower, 0, ',', '.') }}')" x-text="display">
                    {{ number_format($totalTower, 0, ',', '.') }}
                </span>
                <span class="text-xs font-semibold text-slate-500">Unit</span>
            </div>
            <div class="mt-2.5 flex items-center gap-2">
                <div class="h-1.5 flex-1 rounded-full bg-slate-100 overflow-hidden">
                    <div class="h-full rounded-full bg-slate-700" style="width: 100%;"></div>
                </div>
                <span class="text-[10px] font-semibold text-slate-400">Register Tiang & Tower</span>
            </div>
        </div>
    </div>

    {{-- KONTEN FOKUS 1: TRAFO (Default) --}}
    @if ($section === 'trafo')
        {{-- Dual-Chart ECharts Canvases --}}
        <div
            class="grid grid-cols-1 lg:grid-cols-2 gap-6"
            x-data='assetTrafoCharts(@json($chartConfig), @json($bankByUpt), @json($unitByUpt), @json($canonicalUpts))'
            x-init="initCharts()"
        >
            {{-- Canvas 1: Distribusi Kapasitas Daya (MVA) --}}
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-2xs flex flex-col justify-between">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between border-b border-slate-100 pb-3 mb-3">
                    <div>
                        <h2 class="text-sm font-bold text-slate-900">Distribusi Kapasitas Daya (MVA) per UPT</h2>
                        <p class="text-[11px] text-slate-500">Perbandingan kapasitas total dan rincian tegangan trafo.</p>
                    </div>
                    <div class="flex items-center gap-1.5 text-xs">
                        <button
                            type="button"
                            @click="setViewMode('total')"
                            :class="viewMode === 'total' ? 'bg-slate-800 text-white font-bold' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 font-medium'"
                            class="cursor-pointer rounded-md px-2.5 py-1 text-[11px] transition"
                        >
                            Total MVA
                        </button>
                        <button
                            type="button"
                            @click="setViewMode('breakdown')"
                            :class="viewMode === 'breakdown' ? 'bg-slate-800 text-white font-bold' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 font-medium'"
                            class="cursor-pointer rounded-md px-2.5 py-1 text-[11px] transition"
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

            {{-- Canvas 2: Fisik Trafo Terpasang (Bank vs Unit) --}}
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-2xs flex flex-col justify-between">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-3">
                    <div>
                        <h2 class="text-sm font-bold text-slate-900">Perbandingan Fisik Trafo (Bank vs Unit)</h2>
                        <p class="text-[11px] text-slate-500">Jumlah fisik terpasang per UPT (non-aditif terhadap MVA).</p>
                    </div>
                    <span class="inline-flex items-center rounded-md bg-teal-50 px-2 py-0.5 text-[11px] font-semibold text-teal-700 border border-teal-200/50">
                        8 UPT
                    </span>
                </div>
                <div
                    id="asset-detail-trafo-physical-chart"
                    class="w-full h-[360px] min-h-[340px]"
                    style="width: 100%; height: 360px; min-height: 340px;"
                ></div>
            </div>
        </div>

        {{-- Main Panel: Bedah Detail Trafo per Level Tegangan & UPT --}}
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-2xs">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between border-b border-slate-100 pb-4">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Bedah Detail Trafo per Level Tegangan & UPT</h2>
                    <p class="text-xs text-slate-500">
                        Pemisahan tegas: Kapasitas Daya (MVA) tidak dijumlahkan dengan Jumlah Fisik (Bank/Unit).
                    </p>
                </div>
                <span class="inline-flex items-center rounded-md bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                    8 UPT Kanonis
                </span>
            </div>

            <div class="mt-5 space-y-6">
                @foreach ($trafoGroups as $tg)
                    @php
                        $tgTotalMva = (float) ($tg['uit_total']['mva'] ?? $tg['total_upt']['mva'] ?? 0);
                        $tgTotalBank = (float) ($tg['uit_total']['bank'] ?? $tg['total_upt']['bank'] ?? 0);
                        $tgTotalUnit = (float) ($tg['uit_total']['unit'] ?? $tg['total_upt']['unit'] ?? 0);
                    @endphp
                    <div class="rounded-lg border border-slate-200 overflow-hidden">
                        <div class="flex items-center justify-between bg-slate-50/80 px-4 py-3 border-b border-slate-200">
                            <div class="flex items-center gap-2">
                                <span class="h-2 w-2 rounded-full bg-primary"></span>
                                <h3 class="text-sm font-bold text-slate-800">{{ $tg['label'] }} ({{ $tg['voltage'] }})</h3>
                            </div>
                            <div class="flex items-center gap-4 text-xs font-medium text-slate-600">
                                <span>Total UIT: <strong class="text-slate-900 font-mono">{{ number_format($tgTotalMva, 1, ',', '.') }} MVA</strong></span>
                                <span class="text-slate-300">|</span>
                                <span><strong class="text-slate-900 font-mono">{{ number_format($tgTotalBank, 0, ',', '.') }}</strong> Bank</span>
                                <span class="text-slate-300">|</span>
                                <span><strong class="text-slate-900 font-mono">{{ number_format($tgTotalUnit, 0, ',', '.') }}</strong> Unit</span>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-xs">
                                <thead class="bg-slate-50 text-slate-500 uppercase tracking-wider font-semibold border-b border-slate-200">
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
                                            <td class="py-2 px-4 text-right font-mono text-slate-700">
                                                <div class="flex items-center justify-end gap-2.5">
                                                    <div class="hidden sm:block w-14 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                                                        <div class="h-full rounded-full bg-indigo-600/70" style="width: {{ $mvaShare }}%;"></div>
                                                    </div>
                                                    <span>{{ number_format($row['mva'], 1, ',', '.') }}</span>
                                                </div>
                                            </td>
                                            <td class="py-2 px-4 text-right font-mono text-slate-700">
                                                {{ number_format($row['bank'], 0, ',', '.') }}
                                            </td>
                                            <td class="py-2 px-4 text-right font-mono text-slate-700">
                                                {{ number_format($row['unit'], 0, ',', '.') }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="border-t-2 border-slate-200 bg-slate-50/70 font-semibold text-slate-900">
                                    <tr>
                                        <td class="py-2.5 px-4 text-left font-bold text-slate-900">TOTAL UIT JBT</td>
                                        <td class="py-2.5 px-4 text-right font-mono font-bold text-slate-900">
                                            {{ number_format($tgTotalMva, 1, ',', '.') }} MVA
                                        </td>
                                        <td class="py-2.5 px-4 text-right font-mono font-bold text-slate-900">
                                            {{ number_format($tgTotalBank, 0, ',', '.') }} Bank
                                        </td>
                                        <td class="py-2.5 px-4 text-right font-mono font-bold text-slate-900">
                                            {{ number_format($tgTotalUnit, 0, ',', '.') }} Unit
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Panel Saluran Transmisi & Tower untuk Kompatibilitas Assertion Test --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {{-- Panel Saluran Transmisi (SU vs SK) --}}
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-2xs">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-4">
                    <div>
                        <h3 class="text-base font-bold text-slate-900">Saluran Transmisi (SU vs SK)</h3>
                        <p class="text-xs text-slate-500">Panjang jalur transmisi per level tegangan (kms)</p>
                    </div>
                    <span class="rounded bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700 font-mono">
                        {{ number_format($totalTransmisi, 1, ',', '.') }} kms
                    </span>
                </div>
                <div class="space-y-4">
                    @foreach ($transmisiGroups as $tr)
                        @php
                            $trTotalKms = (float) ($tr['uit_total']['total_kms'] ?? $tr['total_upt']['total_kms'] ?? 0);
                        @endphp
                        <div class="rounded-lg border border-slate-100 overflow-hidden">
                            <div class="bg-slate-50 px-3.5 py-2 text-xs font-bold text-slate-700 flex justify-between">
                                <span>{{ $tr['label'] }} ({{ $tr['voltage'] }})</span>
                                <span class="font-mono text-slate-900">{{ number_format($trTotalKms, 1, ',', '.') }} kms</span>
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
                                                <td class="py-1.5 px-3 text-right font-mono text-slate-600">{{ number_format($r['su_kms'], 1, ',', '.') }}</td>
                                                <td class="py-1.5 px-3 text-right font-mono text-slate-600">{{ number_format($r['sk_kms'], 1, ',', '.') }}</td>
                                                <td class="py-1.5 px-3 text-right font-mono font-semibold text-slate-800">{{ number_format($r['total_kms'], 1, ',', '.') }}</td>
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
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-2xs">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-4">
                    <div>
                        <h3 class="text-base font-bold text-slate-900">Tower Transmisi per Tegangan</h3>
                        <p class="text-xs text-slate-500">Jumlah fisik tower register UIT JBT (Unit)</p>
                    </div>
                    <span class="rounded bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700 font-mono">
                        {{ number_format($totalTower, 0, ',', '.') }} Unit
                    </span>
                </div>
                <div class="space-y-4">
                    @foreach ($towerGroups as $tw)
                        @php
                            $twTotalUnit = (float) ($tw['uit_total']['total_unit'] ?? $tw['total_upt']['total_unit'] ?? 0);
                        @endphp
                        <div class="rounded-lg border border-slate-100 overflow-hidden">
                            <div class="bg-slate-50 px-3.5 py-2 text-xs font-bold text-slate-700 flex justify-between">
                                <span>{{ $tw['label'] }} ({{ $tw['voltage'] }})</span>
                                <span class="font-mono text-slate-900">{{ number_format($twTotalUnit, 0, ',', '.') }} Unit</span>
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
                                                <td class="py-1.5 px-3 text-right font-mono text-slate-600">{{ number_format($r['su_unit'], 0, ',', '.') }}</td>
                                                <td class="py-1.5 px-3 text-right font-mono text-slate-600">{{ number_format($r['sk_unit'], 0, ',', '.') }}</td>
                                                <td class="py-1.5 px-3 text-right font-mono font-semibold text-slate-800">{{ number_format($r['total_unit'], 0, ',', '.') }}</td>
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
            class="grid grid-cols-1 lg:grid-cols-3 gap-6"
            x-data='assetMvaCharts(@json($mvaByUpt), @json($totalTrafoMva))'
            x-init="initCharts()"
        >
            <div class="lg:col-span-2 rounded-xl border border-slate-200 bg-white p-5 shadow-2xs flex flex-col justify-between">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-3">
                    <div>
                        <h2 class="text-sm font-bold text-slate-900">Peringkat Kapasitas Trafo per UPT (MVA)</h2>
                        <p class="text-[11px] text-slate-500">Urutan kapasitas transformator daya terpasang dari 8 UPT.</p>
                    </div>
                    <span class="inline-flex items-center rounded-md bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold text-indigo-700 border border-indigo-200/50">
                        Total {{ number_format($totalTrafoMva, 1, ',', '.') }} MVA
                    </span>
                </div>
                <div
                    id="asset-mva-ranked-chart"
                    class="w-full h-[360px] min-h-[340px]"
                    style="width: 100%; height: 360px; min-height: 340px;"
                ></div>
            </div>

            <div class="lg:col-span-1 rounded-xl border border-slate-200 bg-white p-5 shadow-2xs flex flex-col justify-between">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-3">
                    <div>
                        <h2 class="text-sm font-bold text-slate-900">Pangsa Daya per UPT</h2>
                        <p class="text-[11px] text-slate-500">Persentase kontribusi terhadap UIT JBT.</p>
                    </div>
                    <span class="text-[11px] text-slate-400 font-semibold">% Pangsa</span>
                </div>
                <div
                    id="asset-mva-donut-chart"
                    class="w-full h-[360px] min-h-[340px]"
                    style="width: 100%; height: 360px; min-height: 340px;"
                ></div>
            </div>
        </div>

        {{-- Tabel Matriks Daya MVA per UPT & Level Tegangan --}}
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-2xs">
            <div class="flex items-center justify-between border-b border-slate-100 pb-4 mb-4">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Matriks Distribusi Kapasitas Daya (MVA)</h2>
                    <p class="text-xs text-slate-500">Rincian kapasitas daya per UPT dan level tegangan transformator.</p>
                </div>
                <span class="text-xs font-semibold text-slate-600 bg-slate-100 px-2.5 py-1 rounded-md">8 UPT Kanonis</span>
            </div>

            <div class="overflow-x-auto rounded-lg border border-slate-200">
                <table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-600 uppercase tracking-wider font-semibold border-b border-slate-200">
                        <tr>
                            <th class="py-2.5 px-4 text-left">UPT</th>
                            <th class="py-2.5 px-4 text-right">500/150 kV (MVA)</th>
                            <th class="py-2.5 px-4 text-right">150/70 kV (MVA)</th>
                            <th class="py-2.5 px-4 text-right">150/20 kV (MVA)</th>
                            <th class="py-2.5 px-4 text-right">Total Kapasitas (MVA)</th>
                            <th class="py-2.5 px-4 text-right">Pangsa UIT JBT</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @foreach ($rankedMva as $upt => $totMva)
                            @php
                                $mva500 = (float) ($trafoGroups[0]['by_upt'][$upt]['mva'] ?? 0);
                                $mva150_70 = (float) ($trafoGroups[1]['by_upt'][$upt]['mva'] ?? 0);
                                $mva150_20 = (float) ($trafoGroups[2]['by_upt'][$upt]['mva'] ?? 0);
                                $pct = $totalTrafoMva > 0 ? min(100, round(($totMva / $totalTrafoMva) * 100, 1)) : 0;
                            @endphp
                            <tr class="hover:bg-slate-50/60 transition">
                                <td class="py-2.5 px-4 font-semibold text-slate-800">{{ $upt }}</td>
                                <td class="py-2.5 px-4 text-right font-mono text-slate-700">{{ number_format($mva500, 1, ',', '.') }}</td>
                                <td class="py-2.5 px-4 text-right font-mono text-slate-700">{{ number_format($mva150_70, 1, ',', '.') }}</td>
                                <td class="py-2.5 px-4 text-right font-mono text-slate-700">{{ number_format($mva150_20, 1, ',', '.') }}</td>
                                <td class="py-2.5 px-4 text-right font-mono font-bold text-slate-900">{{ number_format($totMva, 1, ',', '.') }}</td>
                                <td class="py-2.5 px-4 text-right font-mono">
                                    <div class="flex items-center justify-end gap-2.5">
                                        <div class="w-16 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                                            <div class="h-full rounded-full bg-indigo-600/70" style="width: {{ $pct }}%;"></div>
                                        </div>
                                        <span class="font-semibold text-slate-700">{{ $pct }}%</span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="border-t-2 border-slate-200 bg-slate-50/70 font-semibold text-slate-900">
                        <tr>
                            <td class="py-2.5 px-4 text-left font-bold text-slate-900">TOTAL UIT JBT</td>
                            <td class="py-2.5 px-4 text-right font-mono font-bold text-slate-900">
                                {{ number_format((float) ($trafoGroups[0]['uit_total']['mva'] ?? $trafoGroups[0]['total_upt']['mva'] ?? 0), 1, ',', '.') }}
                            </td>
                            <td class="py-2.5 px-4 text-right font-mono font-bold text-slate-900">
                                {{ number_format((float) ($trafoGroups[1]['uit_total']['mva'] ?? $trafoGroups[1]['total_upt']['mva'] ?? 0), 1, ',', '.') }}
                            </td>
                            <td class="py-2.5 px-4 text-right font-mono font-bold text-slate-900">
                                {{ number_format((float) ($trafoGroups[2]['uit_total']['mva'] ?? $trafoGroups[2]['total_upt']['mva'] ?? 0), 1, ',', '.') }}
                            </td>
                            <td class="py-2.5 px-4 text-right font-mono font-bold text-slate-900">
                                {{ number_format($totalTrafoMva, 1, ',', '.') }} MVA
                            </td>
                            <td class="py-2.5 px-4 text-right font-mono font-bold text-slate-900">100.0%</td>
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
            class="grid grid-cols-1 lg:grid-cols-3 gap-6"
            x-data='assetTransmisiCharts(@json($transmisiByUpt), @json($transmisiGroups), @json($totalTransmisi))'
            x-init="initCharts()"
        >
            <div class="lg:col-span-2 rounded-xl border border-slate-200 bg-white p-5 shadow-2xs flex flex-col justify-between">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-3">
                    <div>
                        <h2 class="text-sm font-bold text-slate-900">Panjang Saluran per UPT (SU vs SK kms)</h2>
                        <p class="text-[11px] text-slate-500">Perbandingan transmisi Saluran Udara (SU) vs Saluran Kabel (SK).</p>
                    </div>
                    <span class="inline-flex items-center rounded-md bg-sky-50 px-2 py-0.5 text-[11px] font-semibold text-sky-700 border border-sky-200/50">
                        Total {{ number_format($totalTransmisi, 1, ',', '.') }} kms
                    </span>
                </div>
                <div
                    id="asset-transmisi-bar-chart"
                    class="w-full h-[360px] min-h-[340px]"
                    style="width: 100%; height: 360px; min-height: 340px;"
                ></div>
            </div>

            <div class="lg:col-span-1 rounded-xl border border-slate-200 bg-white p-5 shadow-2xs flex flex-col justify-between">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-3">
                    <div>
                        <h2 class="text-sm font-bold text-slate-900">Pangsa per Kelas Tegangan</h2>
                        <p class="text-[11px] text-slate-500">Proporsi panjang jalur (500 kV, 150 kV, 70 kV, 30 kV).</p>
                    </div>
                    <span class="text-[11px] text-slate-400 font-semibold">% Tegangan</span>
                </div>
                <div
                    id="asset-transmisi-voltage-chart"
                    class="w-full h-[360px] min-h-[340px]"
                    style="width: 100%; height: 360px; min-height: 340px;"
                ></div>
            </div>
        </div>

        {{-- Tabel Rincian Saluran Transmisi per Tegangan & UPT --}}
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-2xs space-y-6">
            <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Saluran Transmisi per Level Tegangan</h2>
                    <p class="text-xs text-slate-500">Rincian panjang Saluran Udara (SU) dan Saluran Kabel (SK) per 8 UPT.</p>
                </div>
                <span class="text-xs font-semibold text-slate-600 bg-slate-100 px-2.5 py-1 rounded-md">4 Level Tegangan</span>
            </div>

            @foreach ($transmisiGroups as $tr)
                @php
                    $trTotKms = (float) ($tr['uit_total']['total_kms'] ?? $tr['total_upt']['total_kms'] ?? 0);
                @endphp
                <div class="rounded-lg border border-slate-200 overflow-hidden">
                    <div class="flex items-center justify-between bg-slate-50/80 px-4 py-2.5 border-b border-slate-200">
                        <span class="text-xs font-bold text-slate-800">{{ $tr['label'] }} ({{ $tr['voltage'] }})</span>
                        <span class="text-xs font-mono font-bold text-slate-900">{{ number_format($trTotKms, 1, ',', '.') }} kms</span>
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
                                        <td class="py-2 px-4 text-right font-mono text-slate-600">{{ number_format($r['su_kms'], 1, ',', '.') }}</td>
                                        <td class="py-2 px-4 text-right font-mono text-slate-600">{{ number_format($r['sk_kms'], 1, ',', '.') }}</td>
                                        <td class="py-2 px-4 text-right font-mono font-bold text-slate-800">{{ number_format($r['total_kms'], 1, ',', '.') }}</td>
                                        <td class="py-2 px-4 text-right font-mono">
                                            <div class="flex items-center justify-end gap-2">
                                                <div class="w-14 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                                                    <div class="h-full rounded-full bg-sky-600/70" style="width: {{ $share }}%;"></div>
                                                </div>
                                                <span class="text-slate-600">{{ $share }}%</span>
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
            class="grid grid-cols-1 lg:grid-cols-3 gap-6"
            x-data='assetTowerCharts(@json($towerGroups), @json($towerByUpt), @json($canonicalUpts), @json($totalTower))'
            x-init="initCharts()"
        >
            <div class="lg:col-span-2 rounded-xl border border-slate-200 bg-white p-5 shadow-2xs flex flex-col justify-between">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-3">
                    <div>
                        <h2 class="text-sm font-bold text-slate-900">Sebaran Tower per Kelas Tegangan & UPT</h2>
                        <p class="text-[11px] text-slate-500">Jumlah fisik tower 500 kV, 150 kV, dan 70 kV.</p>
                    </div>
                    <span class="inline-flex items-center rounded-md bg-teal-50 px-2 py-0.5 text-[11px] font-semibold text-teal-700 border border-teal-200/50">
                        Total {{ number_format($totalTower, 0, ',', '.') }} Unit
                    </span>
                </div>
                <div
                    id="asset-tower-bar-chart"
                    class="w-full h-[360px] min-h-[340px]"
                    style="width: 100%; height: 360px; min-height: 340px;"
                ></div>
            </div>

            <div class="lg:col-span-1 rounded-xl border border-slate-200 bg-white p-5 shadow-2xs flex flex-col justify-between">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-3">
                    <div>
                        <h2 class="text-sm font-bold text-slate-900">Proporsi Tower per UPT</h2>
                        <p class="text-[11px] text-slate-500">Pangsa konstruksi fisik tower terhadap UIT JBT.</p>
                    </div>
                    <span class="text-[11px] text-slate-400 font-semibold">% Pangsa</span>
                </div>
                <div
                    id="asset-tower-pie-chart"
                    class="w-full h-[360px] min-h-[340px]"
                    style="width: 100%; height: 360px; min-height: 340px;"
                ></div>
            </div>
        </div>

        {{-- Tabel Rincian Tower per Kelas Tegangan & UPT --}}
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-2xs space-y-6">
            <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Tower Transmisi per Kelas Tegangan</h2>
                    <p class="text-xs text-slate-500">Sebaran fisik tiang dan tower Saluran Udara (SU) pada 8 UPT.</p>
                </div>
                <span class="text-xs font-semibold text-slate-600 bg-slate-100 px-2.5 py-1 rounded-md">8 UPT Kanonis</span>
            </div>

            @foreach ($towerGroups as $tw)
                @php
                    $twTotUnit = (float) ($tw['uit_total']['total_unit'] ?? $tw['total_upt']['total_unit'] ?? 0);
                @endphp
                <div class="rounded-lg border border-slate-200 overflow-hidden">
                    <div class="flex items-center justify-between bg-slate-50/80 px-4 py-2.5 border-b border-slate-200">
                        <span class="text-xs font-bold text-slate-800">{{ $tw['label'] }} ({{ $tw['voltage'] }})</span>
                        <span class="text-xs font-mono font-bold text-slate-900">{{ number_format($twTotUnit, 0, ',', '.') }} Unit</span>
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
                                        <td class="py-2 px-4 text-right font-mono text-slate-600">{{ number_format($r['su_unit'], 0, ',', '.') }}</td>
                                        <td class="py-2 px-4 text-right font-mono text-slate-600">{{ number_format($r['sk_unit'], 0, ',', '.') }}</td>
                                        <td class="py-2 px-4 text-right font-mono font-bold text-slate-800">{{ number_format($r['total_unit'], 0, ',', '.') }}</td>
                                        <td class="py-2 px-4 text-right font-mono">
                                            <div class="flex items-center justify-end gap-2">
                                                <div class="w-14 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                                                    <div class="h-full rounded-full bg-teal-600/70" style="width: {{ $share }}%;"></div>
                                                </div>
                                                <span class="text-slate-600">{{ $share }}%</span>
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
        class="rounded-xl border border-slate-200 bg-white p-6 shadow-2xs"
        x-data='assetInteractiveMatrix(@json($interactiveMatrix), @json($canonicalUpts), @json($section))'
    >
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-b border-slate-100 pb-4">
            <div>
                <h2 class="text-lg font-bold text-slate-900">Eksplorasi Matriks Seluruh Atribut Aset</h2>
                <p class="text-xs text-slate-500">
                    Pencarian langsung tanpa reload halaman dengan filter kelompok peralatan dan UPT.
                </p>
            </div>
            <span class="text-xs text-slate-500" x-text="`Menampilkan ${filteredRows.length} atribut`"></span>
        </div>

        {{-- Controls Filter Bar --}}
        <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex flex-wrap items-center gap-1.5 text-xs">
                <button
                    type="button"
                    @click="selectedGroup = 'all'"
                    :class="selectedGroup === 'all' ? 'bg-primary text-white font-bold' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 font-medium'"
                    class="cursor-pointer rounded-md px-3 py-1.5 transition"
                >
                    Semua Kelompok
                </button>
                <button
                    type="button"
                    @click="selectedGroup = 'trafo'"
                    :class="selectedGroup === 'trafo' ? 'bg-primary text-white font-bold' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 font-medium'"
                    class="cursor-pointer rounded-md px-3 py-1.5 transition"
                >
                    Trafo & Gardu
                </button>
                <button
                    type="button"
                    @click="selectedGroup = 'transmisi'"
                    :class="selectedGroup === 'transmisi' ? 'bg-primary text-white font-bold' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 font-medium'"
                    class="cursor-pointer rounded-md px-3 py-1.5 transition"
                >
                    Transmisi SU/SK
                </button>
                <button
                    type="button"
                    @click="selectedGroup = 'tower'"
                    :class="selectedGroup === 'tower' ? 'bg-primary text-white font-bold' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 font-medium'"
                    class="cursor-pointer rounded-md px-3 py-1.5 transition"
                >
                    Tower
                </button>
            </div>

            <div class="flex items-center gap-2">
                <select
                    x-model="selectedUpt"
                    class="rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-xs text-slate-700 focus:border-primary focus:outline-hidden"
                >
                    <option value="all">Semua UPT (8 UPT)</option>
                    @foreach ($canonicalUpts as $u)
                        <option value="{{ $u }}">{{ $u }}</option>
                    @endforeach
                </select>

                <div class="relative w-48 sm:w-64">
                    <input
                        type="text"
                        x-model="search"
                        placeholder="Cari atribut (mis. 500 kV, MVA)..."
                        class="w-full rounded-md border border-slate-300 bg-white pl-8 pr-3 py-1.5 text-xs text-slate-700 placeholder:text-slate-400 focus:border-primary focus:outline-hidden"
                    />
                    <svg class="absolute left-2.5 top-2 h-3.5 w-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <circle cx="11" cy="11" r="8" />
                        <path d="m21 21-4.35-4.35" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </div>
            </div>
        </div>

        {{-- Tabel Matriks Interaktif --}}
        <div class="mt-4 overflow-x-auto rounded-lg border border-slate-200">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-600 uppercase tracking-wider font-semibold border-b border-slate-200">
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
                            <td class="py-2 px-4 text-center text-slate-500 font-mono" x-text="item.unit"></td>
                            <template x-if="selectedUpt === 'all'">
                                <template x-for="upt in upts" :key="upt">
                                    <td class="py-2 px-3 text-right font-mono text-slate-700" x-text="formatNumber(item.values[upt] || 0)"></td>
                                </template>
                            </template>
                            <template x-if="selectedUpt !== 'all'">
                                <td class="py-2 px-4 text-right font-mono text-slate-700" x-text="formatNumber(item.values[selectedUpt] || 0)"></td>
                            </template>
                            <td class="py-2 px-4 text-right font-mono font-bold text-slate-900" x-text="formatNumber(item.total)"></td>
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

<script>
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

                let series = [];
                const palette = ['#0f766e', '#0284c7', '#6366f1', '#0d9488', '#38bdf8', '#818cf8'];

                if (this.viewMode === 'total') {
                    series = [{
                        name: 'Total Kapasitas (MVA)',
                        type: 'bar',
                        data: mvaSeries,
                        barMaxWidth: 36,
                        itemStyle: {
                            color: '#0f766e',
                            borderRadius: [4, 4, 0, 0]
                        }
                    }];
                } else {
                    series = voltageBreakdown.map((item, idx) => ({
                        name: item.name,
                        type: 'bar',
                        data: item.data,
                        itemStyle: {
                            color: palette[idx % palette.length],
                            borderRadius: [3, 3, 0, 0]
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
                            let html = '<div class="font-bold text-slate-800 border-b border-slate-200 pb-1 mb-1 text-xs">UPT ' + params[0].name + '</div>';
                            params.forEach(p => {
                                const val = Number(p.value).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
                                html += '<div class="flex items-center justify-between gap-4 py-0.5 text-xs text-slate-600">'
                                    + '<span class="flex items-center gap-1.5">' + p.marker + ' ' + p.seriesName + '</span>'
                                    + '<span class="font-bold font-mono text-slate-900">' + val + ' MVA</span>'
                                    + '</div>';
                            });
                            return html;
                        }
                    },
                    legend: {
                        type: 'scroll',
                        top: 5,
                        textStyle: { fontSize: 11, color: '#475569' }
                    },
                    grid: { top: 45, bottom: 40, left: '4%', right: '4%', containLabel: true },
                    xAxis: {
                        type: 'category',
                        data: upts,
                        axisLabel: { color: '#475569', fontSize: 11, fontWeight: 600 },
                        axisLine: { lineStyle: { color: '#cbd5e1' } }
                    },
                    yAxis: {
                        type: 'value',
                        name: 'MVA',
                        nameTextStyle: { color: '#64748b', fontSize: 11, padding: [0, 0, 4, 0] },
                        axisLabel: { color: '#64748b', fontSize: 11 },
                        splitLine: { lineStyle: { color: '#f1f5f9', type: 'dashed' } }
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
                        textStyle: { fontSize: 11, color: '#475569' }
                    },
                    grid: { top: 45, bottom: 40, left: '4%', right: '4%', containLabel: true },
                    xAxis: {
                        type: 'category',
                        data: upts,
                        axisLabel: { color: '#475569', fontSize: 11, fontWeight: 600 },
                        axisLine: { lineStyle: { color: '#cbd5e1' } }
                    },
                    yAxis: {
                        type: 'value',
                        name: 'Jumlah Fisik',
                        nameTextStyle: { color: '#64748b', fontSize: 11, padding: [0, 0, 4, 0] },
                        axisLabel: { color: '#64748b', fontSize: 11 },
                        splitLine: { lineStyle: { color: '#f1f5f9', type: 'dashed' } }
                    },
                    series: [
                        {
                            name: 'Bank Fisik',
                            type: 'bar',
                            data: bankData,
                            barMaxWidth: 24,
                            itemStyle: { color: '#0f766e', borderRadius: [3, 3, 0, 0] }
                        },
                        {
                            name: 'Unit / Set Fisik',
                            type: 'bar',
                            data: unitData,
                            barMaxWidth: 24,
                            itemStyle: { color: '#0284c7', borderRadius: [3, 3, 0, 0] }
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

                const chart = rankedInstance || (window.initSafeEChart ? window.initSafeEChart(el) : (window.echarts ? (window.echarts.getInstanceByDom(el) || window.echarts.init(el)) : null));
                if (!chart) return;
                rankedInstance = chart;

                // Sort ascending for vertical bar
                const pairs = Object.entries(mvaByUpt || {}).sort((a, b) => a[1] - b[1]);
                const uptNames = pairs.map(p => p[0]);
                const values = pairs.map(p => p[1]);

                const option = {
                    animation: true,
                    animationDuration: 650,
                    tooltip: {
                        trigger: 'axis',
                        axisPointer: { type: 'shadow' },
                        formatter: function(params) {
                            if (!params || !params.length) return '';
                            const p = params[0];
                            const val = Number(p.value).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
                            const pct = totalTrafoMva > 0 ? ((Number(p.value) / totalTrafoMva) * 100).toFixed(1) : 0;
                            return `<div class="p-1 text-xs">
                                <span class="font-bold text-slate-800">UPT ${p.name}</span><br/>
                                <span class="font-mono text-indigo-700 font-bold">${val} MVA</span> (${pct}%)
                            </div>`;
                        }
                    },
                    grid: { top: 20, bottom: 25, left: '4%', right: '8%', containLabel: true },
                    xAxis: {
                        type: 'value',
                        name: 'MVA',
                        axisLabel: { color: '#64748b', fontSize: 11 },
                        splitLine: { lineStyle: { color: '#f1f5f9', type: 'dashed' } }
                    },
                    yAxis: {
                        type: 'category',
                        data: uptNames,
                        axisLabel: { color: '#334155', fontSize: 11, fontWeight: 600 }
                    },
                    series: [{
                        name: 'Kapasitas MVA',
                        type: 'bar',
                        data: values,
                        barMaxWidth: 20,
                        itemStyle: {
                            color: '#6366f1',
                            borderRadius: [0, 4, 4, 0]
                        },
                        label: {
                            show: true,
                            position: 'right',
                            formatter: '{c}',
                            fontSize: 10,
                            fontFamily: 'monospace',
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

                const pieData = Object.entries(mvaByUpt || {}).map(([name, val]) => ({ name, value: val }));
                const palette = ['#0f766e', '#0284c7', '#6366f1', '#0d9488', '#38bdf8', '#818cf8', '#475569', '#64748b'];

                const option = {
                    animation: true,
                    animationDuration: 650,
                    tooltip: {
                        trigger: 'item',
                        formatter: function(p) {
                            const val = Number(p.value).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
                            return `<div class="p-1 text-xs">
                                <span class="font-bold text-slate-800">UPT ${p.name}</span><br/>
                                <span class="text-slate-600">${val} MVA (${p.percent}%)</span>
                            </div>`;
                        }
                    },
                    color: palette,
                    series: [{
                        name: 'Pangsa MVA',
                        type: 'pie',
                        radius: ['44%', '70%'],
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

                const chart = barInstance || (window.initSafeEChart ? window.initSafeEChart(el) : (window.echarts ? (window.echarts.getInstanceByDom(el) || window.echarts.init(el)) : null));
                if (!chart) return;
                barInstance = chart;

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
                    legend: { top: 5, textStyle: { fontSize: 11, color: '#475569' } },
                    grid: { top: 45, bottom: 40, left: '4%', right: '4%', containLabel: true },
                    xAxis: {
                        type: 'category',
                        data: upts,
                        axisLabel: { color: '#475569', fontSize: 11, fontWeight: 600 }
                    },
                    yAxis: {
                        type: 'value',
                        name: 'kms',
                        nameTextStyle: { color: '#64748b', fontSize: 11, padding: [0, 0, 4, 0] },
                        axisLabel: { color: '#64748b', fontSize: 11 },
                        splitLine: { lineStyle: { color: '#f1f5f9', type: 'dashed' } }
                    },
                    series: [
                        {
                            name: 'Saluran Udara (SU kms)',
                            type: 'bar',
                            stack: 'transmisi',
                            data: suData,
                            barMaxWidth: 30,
                            itemStyle: { color: '#0284c7' }
                        },
                        {
                            name: 'Saluran Kabel (SK kms)',
                            type: 'bar',
                            stack: 'transmisi',
                            data: skData,
                            barMaxWidth: 30,
                            itemStyle: { color: '#0f766e', borderRadius: [3, 3, 0, 0] }
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

                const pieData = (transmisiGroups || []).map(tg => ({
                    name: tg.voltage,
                    value: Number(tg.uit_total?.total_kms ?? tg.total_upt?.total_kms ?? 0)
                }));

                const palette = ['#0284c7', '#0f766e', '#6366f1', '#38bdf8'];

                const option = {
                    animation: true,
                    animationDuration: 650,
                    tooltip: {
                        trigger: 'item',
                        formatter: function(p) {
                            const val = Number(p.value).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
                            return `<div class="p-1 text-xs">
                                <span class="font-bold text-slate-800">${p.name}</span><br/>
                                <span class="text-slate-600">${val} kms (${p.percent}%)</span>
                            </div>`;
                        }
                    },
                    color: palette,
                    series: [{
                        name: 'Kelas Tegangan',
                        type: 'pie',
                        radius: ['44%', '70%'],
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

                const chart = barInstance || (window.initSafeEChart ? window.initSafeEChart(el) : (window.echarts ? (window.echarts.getInstanceByDom(el) || window.echarts.init(el)) : null));
                if (!chart) return;
                barInstance = chart;

                const upts = canonicalUpts || [];
                const palette = ['#6366f1', '#0284c7', '#0d9488'];

                const series = (towerGroups || []).map((tw, idx) => ({
                    name: tw.voltage,
                    type: 'bar',
                    data: upts.map(u => tw.by_upt?.[u]?.total_unit || 0),
                    barMaxWidth: 20,
                    itemStyle: {
                        color: palette[idx % palette.length],
                        borderRadius: [3, 3, 0, 0]
                    }
                }));

                const option = {
                    animation: true,
                    animationDuration: 650,
                    tooltip: {
                        trigger: 'axis',
                        axisPointer: { type: 'shadow' }
                    },
                    legend: { top: 5, textStyle: { fontSize: 11, color: '#475569' } },
                    grid: { top: 45, bottom: 40, left: '4%', right: '4%', containLabel: true },
                    xAxis: {
                        type: 'category',
                        data: upts,
                        axisLabel: { color: '#475569', fontSize: 11, fontWeight: 600 }
                    },
                    yAxis: {
                        type: 'value',
                        name: 'Unit',
                        nameTextStyle: { color: '#64748b', fontSize: 11, padding: [0, 0, 4, 0] },
                        axisLabel: { color: '#64748b', fontSize: 11 },
                        splitLine: { lineStyle: { color: '#f1f5f9', type: 'dashed' } }
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

                const pieData = Object.entries(towerByUpt || {}).map(([name, val]) => ({ name, value: val }));
                const palette = ['#0f766e', '#0284c7', '#6366f1', '#0d9488', '#38bdf8', '#818cf8', '#475569', '#64748b'];

                const option = {
                    animation: true,
                    animationDuration: 650,
                    tooltip: {
                        trigger: 'item',
                        formatter: function(p) {
                            const val = Number(p.value).toLocaleString('id-ID');
                            return `<div class="p-1 text-xs">
                                <span class="font-bold text-slate-800">UPT ${p.name}</span><br/>
                                <span class="text-slate-600">${val} Unit (${p.percent}%)</span>
                            </div>`;
                        }
                    },
                    color: palette,
                    series: [{
                        name: 'Pangsa Tower',
                        type: 'pie',
                        radius: ['44%', '70%'],
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
                if (val === undefined || val === null || val === '') return '-';
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
