@extends('layouts.app')

@section('title', 'Rincian Trafo & Aset')

@section('content')
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
                Bedah detail kapasitas daya (MVA) dan fisik (Bank/Unit) per level tegangan serta sebaran 8 UPT se-Jawa Bagian Tengah.
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

    {{-- Scorecards Ringkasan UIT JBT --}}
    @php
        $summary = $data['summary_totals'] ?? [];
        $totalTower = $summary['total_tower'] ?? 0;
        $totalTransmisi = $summary['total_transmisi'] ?? 0;
        $totalTrafoMva = $summary['total_trafo_mva'] ?? 0;
        $totalTrafoBank = $summary['total_trafo_bank'] ?? 0;
        $totalTrafoUnit = $summary['total_trafo_unit'] ?? 0;
    @endphp
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {{-- Card 1: Total Kapasitas Trafo (MVA) --}}
        <div class="relative overflow-hidden rounded-xl border border-slate-200 bg-white p-5 shadow-2xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Total Kapasitas Trafo</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-slate-600">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <rect x="3" y="6" width="18" height="12" rx="2" />
                        <path d="M7 12h2m6 0h2M12 9v6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </div>
            </div>
            <div class="mt-3 flex items-baseline gap-1.5">
                <span class="text-3xl font-extrabold tracking-tight text-slate-900" x-data="numberTicker('{{ number_format($totalTrafoMva, 1, ',', '.') }}')" x-text="display">
                    {{ number_format($totalTrafoMva, 1, ',', '.') }}
                </span>
                <span class="text-xs font-semibold text-slate-500">MVA</span>
            </div>
            <p class="mt-1 text-[11px] text-slate-500">Kapasitas daya register tingkat UIT JBT</p>
        </div>

        {{-- Card 2: Total Fisik Trafo (Bank & Unit) --}}
        <div class="relative overflow-hidden rounded-xl border border-slate-200 bg-white p-5 shadow-2xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Fisik Trafo Terpasang</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-slate-600">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M4 6h16M4 12h16M4 18h16" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </div>
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
            <p class="mt-1 text-[11px] text-slate-500">Jumlah fisik terpisah dari kapasitas MVA</p>
        </div>

        {{-- Card 3: Total Transmisi --}}
        <div class="relative overflow-hidden rounded-xl border border-slate-200 bg-white p-5 shadow-2xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Panjang Transmisi</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-slate-600">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M22 12h-4l-3 9L9 3l-3 9H2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </div>
            </div>
            <div class="mt-3 flex items-baseline gap-1.5">
                <span class="text-3xl font-extrabold tracking-tight text-slate-900" x-data="numberTicker('{{ number_format($totalTransmisi, 1, ',', '.') }}')" x-text="display">
                    {{ number_format($totalTransmisi, 1, ',', '.') }}
                </span>
                <span class="text-xs font-semibold text-slate-500">kms</span>
            </div>
            <p class="mt-1 text-[11px] text-slate-500">Saluran Udara (SU) & Saluran Kabel (SK)</p>
        </div>

        {{-- Card 4: Total Tower --}}
        <div class="relative overflow-hidden rounded-xl border border-slate-200 bg-white p-5 shadow-2xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Total Tower</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-slate-600">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M12 2v20M6 8l12 0M4 14l16 0M8 22l4-20 4 20" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </div>
            </div>
            <div class="mt-3 flex items-baseline gap-1.5">
                <span class="text-3xl font-extrabold tracking-tight text-slate-900" x-data="numberTicker('{{ number_format($totalTower, 0, ',', '.') }}')" x-text="display">
                    {{ number_format($totalTower, 0, ',', '.') }}
                </span>
                <span class="text-xs font-semibold text-slate-500">Unit</span>
            </div>
            <p class="mt-1 text-[11px] text-slate-500">Register Tower se-Jawa Bagian Tengah</p>
        </div>
    </div>

    {{-- Main Panel: Bedah Detail Trafo per Level Tegangan & UPT --}}
    @php
        $trafoGroups = $data['trafo_groups'] ?? [];
        $canonicalUpts = $data['canonical_upts'] ?? [];
    @endphp
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
                <div class="rounded-lg border border-slate-200 overflow-hidden">
                    <div class="flex items-center justify-between bg-slate-50/80 px-4 py-3 border-b border-slate-200">
                        <div class="flex items-center gap-2">
                            <span class="h-2 w-2 rounded-full bg-primary"></span>
                            <h3 class="text-sm font-bold text-slate-800">{{ $tg['label'] }} ({{ $tg['voltage'] }})</h3>
                        </div>
                        <div class="flex items-center gap-4 text-xs font-medium text-slate-600">
                            <span>Total UIT: <strong class="text-slate-900 font-mono">{{ number_format($tg['uit_total']['mva'] ?? $tg['total_upt']['mva'], 1, ',', '.') }} MVA</strong></span>
                            <span class="text-slate-300">|</span>
                            <span><strong class="text-slate-900 font-mono">{{ number_format($tg['uit_total']['bank'] ?? $tg['total_upt']['bank'], 0, ',', '.') }}</strong> Bank</span>
                            <span class="text-slate-300">|</span>
                            <span><strong class="text-slate-900 font-mono">{{ number_format($tg['uit_total']['unit'] ?? $tg['total_upt']['unit'], 0, ',', '.') }}</strong> Unit</span>
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
                                    @endphp
                                    <tr class="hover:bg-slate-50/60 transition">
                                        <td class="py-2 px-4 font-semibold text-slate-800">{{ $upt }}</td>
                                        <td class="py-2 px-4 text-right font-mono text-slate-700">
                                            {{ number_format($row['mva'], 1, ',', '.') }}
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
                                        {{ number_format($tg['uit_total']['mva'] ?? $tg['total_upt']['mva'], 1, ',', '.') }} MVA
                                    </td>
                                    <td class="py-2.5 px-4 text-right font-mono font-bold text-slate-900">
                                        {{ number_format($tg['uit_total']['bank'] ?? $tg['total_upt']['bank'], 0, ',', '.') }} Bank
                                    </td>
                                    <td class="py-2.5 px-4 text-right font-mono font-bold text-slate-900">
                                        {{ number_format($tg['uit_total']['unit'] ?? $tg['total_upt']['unit'], 0, ',', '.') }} Unit
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- ECharts Visual Distribution: Kapasitas MVA vs Sebaran Fisik Trafo --}}
    @php
        $chartConfig = $data['chart_config'] ?? [];
    @endphp
    <div
        class="rounded-xl border border-slate-200 bg-white p-6 shadow-2xs"
        x-data='assetDetailChartWidget(@json($chartConfig))'
        x-init="initChart()"
    >
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between border-b border-slate-100 pb-4">
            <div>
                <h2 class="text-lg font-bold text-slate-900">Distribusi Kapasitas Daya (MVA) per UPT</h2>
                <p class="text-xs text-slate-500">
                    Visualisasi perbandingan kapasitas total dan rincian level tegangan trafo pada 8 UPT.
                </p>
            </div>
            <div class="flex items-center gap-1.5 text-xs">
                <button
                    type="button"
                    @click="setViewMode('total')"
                    :class="viewMode === 'total' ? 'bg-slate-800 text-white font-bold' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 font-medium'"
                    class="cursor-pointer rounded-md px-3 py-1 transition"
                >
                    Total Kapasitas (MVA)
                </button>
                <button
                    type="button"
                    @click="setViewMode('breakdown')"
                    :class="viewMode === 'breakdown' ? 'bg-slate-800 text-white font-bold' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 font-medium'"
                    class="cursor-pointer rounded-md px-3 py-1 transition"
                >
                    Breakdown per Tegangan
                </button>
            </div>
        </div>

        <div
            id="asset-detail-trafo-chart"
            class="mt-6 w-full h-[360px] min-h-[360px]"
            style="width: 100%; height: 360px;"
        ></div>
    </div>

    {{-- Breakdown Saluran Transmisi & Tower per Tegangan --}}
    @php
        $transmisiGroups = $data['transmisi_groups'] ?? [];
        $towerGroups = $data['tower_groups'] ?? [];
        $bayGroups = $data['bay_groups'] ?? [];
    @endphp
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {{-- Panel Saluran Transmisi (SU vs SK kms) --}}
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
                    <div class="rounded-lg border border-slate-100 overflow-hidden">
                        <div class="bg-slate-50 px-3.5 py-2 text-xs font-bold text-slate-700 flex justify-between">
                            <span>{{ $tr['label'] }} ({{ $tr['voltage'] }})</span>
                            <span class="font-mono text-slate-900">{{ number_format($tr['uit_total']['total_kms'] ?? $tr['total_upt']['total_kms'], 1, ',', '.') }} kms</span>
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

        {{-- Panel Tower per Tegangan (SU vs SK unit) --}}
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
                    <div class="rounded-lg border border-slate-100 overflow-hidden">
                        <div class="bg-slate-50 px-3.5 py-2 text-xs font-bold text-slate-700 flex justify-between">
                            <span>{{ $tw['label'] }} ({{ $tw['voltage'] }})</span>
                            <span class="font-mono text-slate-900">{{ number_format($tw['uit_total']['total_unit'] ?? $tw['total_upt']['total_unit'], 0, ',', '.') }} Unit</span>
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

    {{-- Interactive Alpine.js Matrix & Search Filter --}}
    @php
        $interactiveMatrix = $data['interactive_matrix'] ?? [];
    @endphp
    <div
        class="rounded-xl border border-slate-200 bg-white p-6 shadow-2xs"
        x-data='assetInteractiveMatrix(@json($interactiveMatrix), @json($canonicalUpts))'
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
if (typeof window.assetDetailChartWidget !== 'function') {
    window.assetDetailChartWidget = function(config) {
        let chartInstance = null;
        const upts = (config && config.upts) || [];
        const mvaSeries = (config && config.mva_series) || [];
        const voltageBreakdown = (config && config.voltage_breakdown) || [];

        return {
            viewMode: 'total',

            initChart() {
                const el = document.getElementById('asset-detail-trafo-chart');
                if (!el) return;

                if (!window.echarts) {
                    setTimeout(() => this.initChart(), 50);
                    return;
                }

                if (!chartInstance) {
                    chartInstance = window.echarts.getInstanceByDom(el) || window.echarts.init(el);
                    new ResizeObserver(() => {
                        if (chartInstance) chartInstance.resize();
                    }).observe(el);
                }

                this.render();
            },

            setViewMode(mode) {
                this.viewMode = mode;
                this.render();
            },

            render() {
                if (!chartInstance) return;

                let series = [];
                const palette = ['#0b6175', '#16a3b8', '#0b7a75', '#1971c2', '#7a5195', '#2f9e44'];

                if (this.viewMode === 'total') {
                    series = [{
                        name: 'Total Kapasitas (MVA)',
                        type: 'bar',
                        data: mvaSeries,
                        barMaxWidth: 38,
                        itemStyle: {
                            color: '#0b6175',
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
                    animationEasing: 'cubicOut',
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
                    grid: {
                        top: 45,
                        bottom: 40,
                        left: '4%',
                        right: '4%',
                        containLabel: true
                    },
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

                chartInstance.setOption(option, true);
            }
        };
    };
}

if (typeof window.assetInteractiveMatrix !== 'function') {
    window.assetInteractiveMatrix = function(rows, upts) {
        return {
            rows: rows || [],
            upts: upts || [],
            selectedGroup: 'all',
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
