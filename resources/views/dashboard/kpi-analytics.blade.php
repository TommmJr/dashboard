@extends('layouts.app')

@section('title', 'Analitik & Bottleneck KPI')

@section('content')
@php
    $visibleUnitsLabel = !empty($visibleUnits) ? implode(', ', $visibleUnits) : 'TJBT';
    $rag = $ragThresholds ?? ['tercapai_min' => 100.0, 'hampir_min' => 95.0];
    $fmt = function (?float $value, int $decimals = 2): string {
        if ($value === null) {
            return '—';
        }
        $formatted = number_format($value, $decimals, ',', '.');
        return str_contains($formatted, ',') ? rtrim(rtrim($formatted, '0'), ',') : $formatted;
    };
    $achievedRatioPct = $scoredCount > 0 ? round(($achievedCount / $scoredCount) * 100, 1) : 0;
@endphp

<div class="mx-auto max-w-7xl space-y-6">
    {{-- Top Header & Navigation Bar --}}
    <div id="kpi-analytics-header" class="flex flex-col gap-4 rounded-xl border border-slate-200/80 bg-white p-4 sm:p-5 shadow-xs transition sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-wrap items-center gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-teal-500/20 bg-teal-50 text-primary shadow-2xs">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M3 3v18h18" stroke-linecap="round" stroke-linejoin="round" />
                    <path d="m19 9-5 5-4-4-3 3" stroke-linecap="round" stroke-linejoin="round" />
                    <circle cx="19" cy="9" r="1.5" fill="currentColor" stroke="none" />
                </svg>
            </div>
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-xl font-extrabold tracking-tight text-primary">Analitik & Bottleneck KPI</h1>
                    @if ($period !== null)
                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-700">
                            Periode {{ $period }}
                        </span>
                    @endif
                </div>
                <p class="text-xs text-slate-500 mt-0.5">
                    Evaluasi komprehensif deviasi realisasi target, identifikasi indikator penghambat skor NKO, dan prioritas tindakan perbaikan unit {{ $visibleUnitsLabel }}.
                </p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('dashboard.kpi-detail') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200/80 bg-white px-3 py-2 text-xs font-semibold text-slate-700 shadow-2xs transition hover:border-slate-300 hover:bg-slate-50">
                <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M9 17v-6m3 6V7m3 10v-4M5 21h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2Z" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Rincian KPI
            </a>
            <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200/80 bg-white px-3 py-2 text-xs font-semibold text-slate-700 shadow-2xs transition hover:border-slate-300 hover:bg-slate-50">
                &larr; Dashboard Utama
            </a>
        </div>
    </div>

    {{-- Filter Periode Rentang --}}
    <div class="rounded-xl border border-slate-200/80 bg-white p-4 shadow-xs">
        <form method="GET" action="{{ route('dashboard.kpi-analytics') }}" class="flex flex-wrap items-end gap-3 text-xs">
            <div>
                <label for="filter-start-date" class="block font-semibold text-slate-600 mb-1">Mulai Periode</label>
                <input
                    type="date"
                    id="filter-start-date"
                    name="start_date"
                    value="{{ $filters['start_date'] ?? '' }}"
                    class="h-9 rounded-lg border border-slate-300 bg-white px-2.5 text-xs text-slate-800 shadow-2xs focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                />
            </div>
            <div>
                <label for="filter-end-date" class="block font-semibold text-slate-600 mb-1">Sampai Periode</label>
                <input
                    type="date"
                    id="filter-end-date"
                    name="end_date"
                    value="{{ $filters['end_date'] ?? '' }}"
                    class="h-9 rounded-lg border border-slate-300 bg-white px-2.5 text-xs text-slate-800 shadow-2xs focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                />
            </div>
            <button
                type="submit"
                class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-primary px-3.5 font-semibold text-white shadow-2xs transition hover:bg-primary/90"
            >
                Terapkan Filter
            </button>
            @if (! empty($filters['start_date']) || ! empty($filters['end_date']))
                <a
                    href="{{ route('dashboard.kpi-analytics') }}"
                    class="inline-flex h-9 items-center rounded-lg border border-slate-200 bg-white px-3 text-xs font-medium text-slate-600 transition hover:bg-slate-50"
                >
                    Reset Filter
                </a>
            @endif
        </form>
    </div>

    {{-- 1. Executive Summary & Gap Analysis Cards --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {{-- Card 1: Skor NKO Aktif --}}
        <div class="relative overflow-hidden rounded-xl border border-slate-200/80 bg-white p-5 shadow-xs transition-all duration-200 ease-out hover:-translate-y-0.5 hover:shadow-md">
            <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-teal-500 to-teal-400"></div>
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Skor NKO Aktif ({{ $visibleUnitsLabel }})</p>
                <div class="flex h-8 w-8 items-center justify-center rounded-lg border border-teal-100 bg-teal-50 text-teal-700 shadow-2xs">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <circle cx="12" cy="8" r="6"/>
                        <path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
            </div>
            <p class="mt-3 text-3xl font-extrabold leading-none tracking-tight text-slate-800">
                {{ $nkoScore !== null ? number_format($nkoScore, 2, ',', '.') : '—' }}
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
                <span class="inline-flex items-center rounded-md border border-teal-200 bg-teal-50 px-2 py-0.5 text-[11px] font-semibold text-teal-800">
                    Target &ge; 100
                </span>
                <span class="text-[11px]">{{ $nkoScore !== null && $nkoScore >= 100 ? 'Memenuhi Standar' : 'Perlu Evaluasi' }}</span>
            </div>
        </div>

        {{-- Card 2: Rasio Indikator Tercapai --}}
        <div class="relative overflow-hidden rounded-xl border border-slate-200/80 bg-white p-5 shadow-xs transition-all duration-200 ease-out hover:-translate-y-0.5 hover:shadow-md">
            <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-emerald-500 to-emerald-400"></div>
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Indikator Tercapai (&ge; 100%)</p>
                <div class="flex h-8 w-8 items-center justify-center rounded-lg border border-emerald-100 bg-emerald-50 text-emerald-700 shadow-2xs">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="m9 12 2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
            </div>
            <p class="mt-3 text-3xl font-extrabold leading-none tracking-tight text-slate-800">
                {{ $achievedCount }} <span class="text-base font-medium text-slate-500">/ {{ $scoredCount }}</span>
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
                <span class="inline-flex items-center rounded-md border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-800">
                    {{ $achievedRatioPct }}% Tercapai
                </span>
                <span class="text-[11px]">Daun berskor</span>
            </div>
        </div>

        {{-- Card 3: Rata-rata Pencapaian --}}
        <div class="relative overflow-hidden rounded-xl border border-slate-200/80 bg-white p-5 shadow-xs transition-all duration-200 ease-out hover:-translate-y-0.5 hover:shadow-md">
            <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-blue-500 to-blue-400"></div>
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Rata-rata Pencapaian</p>
                <div class="flex h-8 w-8 items-center justify-center rounded-lg border border-blue-100 bg-blue-50 text-blue-700 shadow-2xs">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M18 20V10M12 20V4M6 20v-6" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
            </div>
            <p class="mt-3 text-3xl font-extrabold leading-none tracking-tight text-slate-800">
                {{ $avgPencapaian !== null ? number_format($avgPencapaian, 1, ',', '.') . '%' : '—' }}
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
                <span class="inline-flex items-center rounded-md border border-blue-200 bg-blue-50 px-2 py-0.5 text-[11px] font-semibold text-blue-800">
                    Batas Maks 110%
                </span>
                <span class="text-[11px]">Unweighted Mean</span>
            </div>
        </div>

        {{-- Card 4: Bottleneck / Kritis --}}
        <div class="relative overflow-hidden rounded-xl border border-slate-200/80 bg-white p-5 shadow-xs transition-all duration-200 ease-out hover:-translate-y-0.5 hover:shadow-md">
            <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-rose-500 to-rose-400"></div>
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Indikator Bottleneck (&lt; 100%)</p>
                <div class="flex h-8 w-8 items-center justify-center rounded-lg border border-rose-100 bg-rose-50 text-rose-700 shadow-2xs">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
            </div>
            <p class="mt-3 text-3xl font-extrabold leading-none tracking-tight text-slate-800">
                {{ $bottleneckCount }}
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
                <span class="inline-flex items-center rounded-md border border-rose-200 bg-rose-50 px-2 py-0.5 text-[11px] font-semibold text-rose-800">
                    {{ $criticalCount }} Kritis (&lt; 95%)
                </span>
                <span class="text-[11px]">{{ $nearCount }} Mendekati</span>
            </div>
        </div>
    </div>

    {{-- 2. Visualisasi Grafik Analitik ECharts (2 Grid Kolom) --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        {{-- Grafik 1: Horizontal Bar / Bullet Chart (8 Kolom) --}}
        <div class="lg:col-span-8 rounded-xl border border-slate-200/80 bg-white p-5 shadow-xs">
            <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-100 pb-3">
                <div>
                    <h2 class="text-base font-bold text-primary">Komparasi Pencapaian per Indikator</h2>
                    <p class="text-xs text-slate-500 mt-0.5">
                        Posisi pencapaian aktual terhadap garis batas ambang (Ambang 95% & Target 100%).
                    </p>
                </div>
                <div class="flex items-center gap-2 text-xs">
                    <span class="inline-flex items-center gap-1 font-semibold text-emerald-700">
                        <span class="h-2 w-2 rounded-full bg-emerald-500"></span> &ge; 100%
                    </span>
                    <span class="inline-flex items-center gap-1 font-semibold text-amber-700">
                        <span class="h-2 w-2 rounded-full bg-amber-500"></span> 95-99.9%
                    </span>
                    <span class="inline-flex items-center gap-1 font-semibold text-rose-700">
                        <span class="h-2 w-2 rounded-full bg-rose-500"></span> &lt; 95%
                    </span>
                </div>
            </div>
            <div class="mt-4">
                <div id="kpi-bullet-chart" class="w-full" style="width: 100%; height: 360px;"></div>
            </div>
        </div>

        {{-- Grafik 2: Distribusi Status RAG & Kategori (4 Kolom) --}}
        <div class="lg:col-span-4 rounded-xl border border-slate-200/80 bg-white p-5 shadow-xs flex flex-col justify-between">
            <div>
                <div class="border-b border-slate-100 pb-3">
                    <h2 class="text-base font-bold text-primary">Distribusi Status RAG</h2>
                    <p class="text-xs text-slate-500 mt-0.5">
                        Proporsi indikator berdasarkan tingkat keberhasilan.
                    </p>
                </div>
                <div class="mt-4">
                    <div id="kpi-distribution-chart" class="w-full" style="width: 100%; height: 260px;"></div>
                </div>
            </div>

            {{-- Ringkasan Mini Grup Kategori --}}
            <div class="mt-3 border-t border-slate-100 pt-3">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">Ringkasan per Kelompok Kinerja</p>
                <div class="space-y-1.5 max-h-36 overflow-y-auto pr-1">
                    @foreach ($groupStats as $grp)
                        <div class="flex items-center justify-between text-xs rounded-lg bg-slate-50 px-2.5 py-1.5 border border-slate-100">
                            <span class="font-medium text-slate-700 truncate max-w-[160px]" title="{{ $grp['name'] }}">
                                {{ $grp['name'] }}
                            </span>
                            <div class="flex items-center gap-2 shrink-0">
                                <span class="text-slate-500 text-[11px]">{{ $grp['tercapai'] }}/{{ $grp['total'] }}</span>
                                <span class="font-bold text-slate-800">{{ number_format($grp['avg'], 1, ',', '.') }}%</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- 3. Bottleneck Finder ("Indikator Prioritas Perbaikan") --}}
    <script>
        window.__kpiBottleneckIndicators = {!! json_encode($indicators) !!};
        if (typeof window.kpiBottleneckSection !== 'function') {
            window.kpiBottleneckSection = function(initialItems) {
                return {
                    activeTab: 'bottleneck',
                    searchQuery: '',
                    sortBy: 'pencapaian_asc',
                    itemsList: initialItems || [],
                    filterIndicators() {
                        let items = [...this.itemsList];

                        // Tab filter
                        if (this.activeTab === 'bottleneck') {
                            items = items.filter(i => i.tier === 'perlu' || i.tier === 'hampir');
                        } else if (this.activeTab === 'tercapai') {
                            items = items.filter(i => i.tier === 'tercapai');
                        } else if (this.activeTab === 'kritis') {
                            items = items.filter(i => i.tier === 'perlu');
                        }

                        // Search query
                        if (this.searchQuery.trim() !== '') {
                            const q = this.searchQuery.trim().toLowerCase();
                            items = items.filter(i => (i.name + ' ' + i.group + ' ' + (i.satuan || '')).toLowerCase().includes(q));
                        }

                        // Sorting
                        items.sort((a, b) => {
                            if (this.sortBy === 'pencapaian_asc') {
                                return (a.pencapaian ?? 999) - (b.pencapaian ?? 999);
                            } else if (this.sortBy === 'pencapaian_desc') {
                                return (b.pencapaian ?? -1) - (a.pencapaian ?? -1);
                            } else if (this.sortBy === 'name_asc') {
                                return a.name.localeCompare(b.name);
                            } else if (this.sortBy === 'gap_asc') {
                                return (a.gap ?? 999999) - (b.gap ?? 999999);
                            }
                            return 0;
                        });

                        return items;
                    }
                };
            };
        }
    </script>
    <div
        id="kpi-bottleneck-section"
        class="rounded-xl border border-slate-200/80 bg-white p-5 shadow-xs"
        x-data="kpiBottleneckSection(window.__kpiBottleneckIndicators)"
    >
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-b border-slate-100 pb-4">
            <div>
                <div class="flex items-center gap-2">
                    <h2 class="text-base font-bold text-primary">Bottleneck Finder &mdash; Indikator Prioritas Perbaikan</h2>
                    <span class="inline-flex items-center rounded-full bg-rose-50 px-2.5 py-0.5 text-xs font-semibold text-rose-700 border border-rose-200">
                        {{ $bottleneckCount }} Indikator Tertinggal
                    </span>
                </div>
                <p class="text-xs text-slate-500 mt-0.5">
                    Daftar indikator kinerja yang berada di bawah target 100%, diurutkan dari deviasi terbesar sebagai fokus intervensi manajemen.
                </p>
            </div>

            {{-- Search & Sort Controls --}}
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative">
                    <input
                        type="search"
                        x-model="searchQuery"
                        placeholder="Cari indikator atau grup..."
                        class="h-9 w-48 sm:w-60 rounded-lg border border-slate-300 bg-white py-1.5 pl-3 pr-3 text-xs text-slate-800 placeholder:text-slate-400 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    />
                </div>
                <select
                    x-model="sortBy"
                    class="h-9 rounded-lg border border-slate-300 bg-white px-2.5 text-xs text-slate-700 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                >
                    <option value="pencapaian_asc">Pencapaian Terendah (Prioritas Utama)</option>
                    <option value="pencapaian_desc">Pencapaian Tertinggi</option>
                    <option value="gap_asc">Selisih Realisasi Terendah</option>
                    <option value="name_asc">Nama Indikator (A-Z)</option>
                </select>
            </div>
        </div>

        {{-- Filter Tabs --}}
        <div class="mt-4 flex flex-wrap items-center gap-2 text-xs">
            <button
                type="button"
                @click="activeTab = 'bottleneck'"
                :class="activeTab === 'bottleneck' ? 'bg-primary text-white font-bold shadow-2xs' : 'bg-slate-100 text-slate-700 font-medium hover:bg-slate-200'"
                class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 transition cursor-pointer"
            >
                <span class="h-2 w-2 rounded-full bg-rose-400"></span>
                Prioritas Bottleneck ({{ $bottleneckCount }})
            </button>
            <button
                type="button"
                @click="activeTab = 'kritis'"
                :class="activeTab === 'kritis' ? 'bg-rose-600 text-white font-bold shadow-2xs' : 'bg-slate-100 text-slate-700 font-medium hover:bg-slate-200'"
                class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 transition cursor-pointer"
            >
                <span class="h-2 w-2 rounded-full bg-rose-300"></span>
                Perlu Perhatian (&lt; 95%): {{ $criticalCount }}
            </button>
            <button
                type="button"
                @click="activeTab = 'all'"
                :class="activeTab === 'all' ? 'bg-slate-800 text-white font-bold shadow-2xs' : 'bg-slate-100 text-slate-700 font-medium hover:bg-slate-200'"
                class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 transition cursor-pointer"
            >
                Semua Indikator ({{ $totalIndicators }})
            </button>
            <button
                type="button"
                @click="activeTab = 'tercapai'"
                :class="activeTab === 'tercapai' ? 'bg-emerald-600 text-white font-bold shadow-2xs' : 'bg-slate-100 text-slate-700 font-medium hover:bg-slate-200'"
                class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 transition cursor-pointer"
            >
                <span class="h-2 w-2 rounded-full bg-emerald-300"></span>
                Tercapai (&ge; 100%): {{ $achievedCount }}
            </button>
        </div>

        {{-- Table Container --}}
        <div class="mt-4 overflow-x-auto rounded-lg border border-slate-200/80">
            <table class="w-full text-left text-xs text-slate-700">
                <thead class="bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-500 border-b border-slate-200">
                    <tr>
                        <th scope="col" class="py-3 px-3.5 w-12 text-center">No</th>
                        <th scope="col" class="py-3 px-3.5 min-w-[200px]">Indikator Kinerja</th>
                        <th scope="col" class="py-3 px-3.5 min-w-[120px]">Kelompok</th>
                        <th scope="col" class="py-3 px-3.5 text-right">Target</th>
                        <th scope="col" class="py-3 px-3.5 text-right">Realisasi</th>
                        <th scope="col" class="py-3 px-3.5 text-right min-w-[110px]">Selisih (Gap)</th>
                        <th scope="col" class="py-3 px-3.5 min-w-[140px]">Pencapaian %</th>
                        <th scope="col" class="py-3 px-3.5 text-center w-28">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    <template x-for="(item, index) in filterIndicators()" :key="item.id">
                        <tr class="transition hover:bg-slate-50/70">
                            <td class="py-2.5 px-3.5 text-center font-medium text-slate-400 text-[11px]" x-text="index + 1"></td>
                            <td class="py-2.5 px-3.5">
                                <span class="font-semibold text-slate-800 block" x-text="item.name"></span>
                                <span class="text-[10px] text-slate-400" x-show="item.satuan" x-text="'Satuan: ' + item.satuan"></span>
                            </td>
                            <td class="py-2.5 px-3.5 text-slate-500" x-text="item.group"></td>
                            <td class="py-2.5 px-3.5 text-right font-medium tabular-nums text-slate-700">
                                <template x-if="item.mode === 'numeric'">
                                    <span x-text="item.target !== null ? Number(item.target).toLocaleString('id-ID', {maximumFractionDigits: 2}) : '—'"></span>
                                </template>
                                <template x-if="item.mode === 'date'">
                                    <span class="text-[11px] text-slate-600" x-text="item.target_date || '—'"></span>
                                </template>
                                <template x-if="item.mode === 'score_only'">
                                    <span class="text-slate-400">—</span>
                                </template>
                            </td>
                            <td class="py-2.5 px-3.5 text-right font-medium tabular-nums text-slate-700">
                                <template x-if="item.mode === 'numeric'">
                                    <span x-text="item.realisasi !== null ? Number(item.realisasi).toLocaleString('id-ID', {maximumFractionDigits: 2}) : '—'"></span>
                                </template>
                                <template x-if="item.mode === 'date'">
                                    <span class="text-[11px] text-slate-600" x-text="item.realisasi_date || '—'"></span>
                                </template>
                                <template x-if="item.mode === 'score_only'">
                                    <span class="text-slate-400">—</span>
                                </template>
                            </td>
                            <td class="py-2.5 px-3.5 text-right tabular-nums">
                                <template x-if="item.mode === 'numeric' && item.gap !== null">
                                    <span
                                        class="font-semibold text-xs"
                                        :class="item.tier === 'tercapai' ? 'text-emerald-700' : 'text-rose-700'"
                                        x-text="item.gap_formatted"
                                    ></span>
                                </template>
                                <template x-if="item.mode === 'date' && item.delta_days !== null">
                                    <span
                                        class="text-[11px] font-medium"
                                        :class="item.delta_days <= 0 ? 'text-emerald-700' : 'text-rose-700'"
                                        x-text="item.delta_days <= 0 ? Math.abs(item.delta_days) + ' hr lebih cepat' : item.delta_days + ' hr terlambat'"
                                    ></span>
                                </template>
                                <template x-if="(item.mode === 'numeric' && item.gap === null) || (item.mode === 'date' && item.delta_days === null) || item.mode === 'score_only'">
                                    <span class="text-slate-400">—</span>
                                </template>
                            </td>
                            <td class="py-2.5 px-3.5">
                                <template x-if="item.pencapaian !== null">
                                    <div class="flex items-center gap-2">
                                        <div class="h-2 w-16 sm:w-20 rounded-full bg-slate-100 overflow-hidden">
                                            <div
                                                class="h-full rounded-full transition-all duration-300"
                                                :class="item.tier === 'tercapai' ? 'bg-emerald-500' : (item.tier === 'hampir' ? 'bg-amber-500' : 'bg-rose-500')"
                                                :style="'width: ' + Math.max(0, Math.min(100, item.pencapaian)) + '%'"
                                            ></div>
                                        </div>
                                        <span
                                            class="font-bold tabular-nums text-xs"
                                            :class="item.tier === 'tercapai' ? 'text-emerald-700' : (item.tier === 'hampir' ? 'text-amber-700' : 'text-rose-700')"
                                            x-text="Number(item.pencapaian).toFixed(1) + '%'"
                                        ></span>
                                    </div>
                                </template>
                                <template x-if="item.pencapaian === null">
                                    <span class="text-slate-400 text-xs">—</span>
                                </template>
                            </td>
                            <td class="py-2.5 px-3.5 text-center">
                                <span
                                    class="inline-flex items-center rounded-md px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider"
                                    :class="{
                                        'border border-emerald-200 bg-emerald-50 text-emerald-800': item.tier === 'tercapai',
                                        'border border-amber-200 bg-amber-50 text-amber-800': item.tier === 'hampir',
                                        'border border-rose-200 bg-rose-50 text-rose-800': item.tier === 'perlu',
                                        'border border-slate-200 bg-slate-50 text-slate-600': item.tier === 'date' || item.tier === 'unverified'
                                    }"
                                    x-text="item.tier === 'tercapai' ? 'Tercapai' : (item.tier === 'hampir' ? 'Mendekati' : (item.tier === 'perlu' ? 'Kritis' : 'Info'))"
                                ></span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- Safe JSON Data Island for ECharts Charts --}}
<script type="application/json" id="kpiAnalyticsChartData">
{
    "chartIndicators": {!! json_encode($chartIndicators) !!},
    "ragCounts": {!! json_encode($ragCounts) !!},
    "ragThresholds": {!! json_encode($ragThresholds) !!}
}
</script>

<script>
(function() {
    let chartBulletInstance = null;
    let chartDistributionInstance = null;

    function initAnalyticsCharts() {
        const rawEl = document.getElementById('kpiAnalyticsChartData');
        if (!rawEl) {
            return;
        }
        if (!window.echarts) {
            setTimeout(initAnalyticsCharts, 50);
            return;
        }

        let data = {};
        try {
            data = JSON.parse(rawEl.textContent);
        } catch (e) {
            console.error('Failed to parse chart data:', e);
            return;
        }

        const chartIndicators = data.chartIndicators || [];
        const ragCounts = data.ragCounts || { tercapai: 0, hampir: 0, perlu: 0 };
        const rag = data.ragThresholds || { tercapai_min: 100, hampir_min: 95 };

        // 1. Horizontal Bar / Bullet Chart
        const bulletEl = document.getElementById('kpi-bullet-chart');
        if (bulletEl) {
            if (chartBulletInstance) {
                chartBulletInstance.dispose();
            }
            chartBulletInstance = window.echarts.init(bulletEl);

            const names = chartIndicators.map(i => {
                const n = i.name || '';
                return n.length > 25 ? n.substring(0, 23) + '..' : n;
            });
            const fullNames = chartIndicators.map(i => i.name || '');
            const scores = chartIndicators.map(i => Number((i.pencapaian || 0).toFixed(1)));
            const groups = chartIndicators.map(i => i.group || 'Umum');
            const gaps = chartIndicators.map(i => i.gap_formatted || '—');

            chartBulletInstance.setOption({
                animation: true,
                animationDuration: 650,
                animationEasing: 'cubicOut',
                animationDurationUpdate: 400,
                tooltip: {
                    trigger: 'axis',
                    axisPointer: { type: 'shadow' },
                    formatter: function(params) {
                        const idx = params[0].dataIndex;
                        const fullName = fullNames[idx];
                        const grp = groups[idx];
                        const val = scores[idx];
                        const gapStr = gaps[idx];
                        return `<div class="text-xs">
                            <strong class="text-slate-800">${fullName}</strong><br/>
                            <span class="text-slate-500">Kelompok:</span> ${grp}<br/>
                            <span class="text-slate-500">Pencapaian:</span> <b>${val}%</b><br/>
                            <span class="text-slate-500">Selisih Target:</span> ${gapStr}
                        </div>`;
                    }
                },
                grid: {
                    left: 170,
                    right: 40,
                    top: 25,
                    bottom: 25
                },
                xAxis: {
                    type: 'value',
                    min: 0,
                    max: 120,
                    axisLabel: {
                        formatter: '{value}%',
                        fontSize: 10
                    },
                    splitLine: {
                        lineStyle: { color: '#f1f5f9' }
                    }
                },
                yAxis: {
                    type: 'category',
                    data: names,
                    axisLabel: {
                        fontSize: 10,
                        color: '#475569'
                    }
                },
                series: [{
                    name: 'Pencapaian %',
                    type: 'bar',
                    data: scores,
                    itemStyle: {
                        color: function(params) {
                            const val = Number(params.value || 0);
                            if (val >= rag.tercapai_min) return '#10b981'; // emerald-500
                            if (val >= rag.hampir_min) return '#f59e0b';   // amber-500
                            return '#f43f5e';                             // rose-500
                        },
                        borderRadius: [0, 4, 4, 0]
                    },
                    markLine: {
                        silent: true,
                        symbol: 'none',
                        data: [
                            {
                                xAxis: 100,
                                lineStyle: { color: '#0b6175', type: 'dashed', width: 1.5 },
                                label: { formatter: 'Target 100%', position: 'end', fontSize: 10, color: '#0b6175' }
                            },
                            {
                                xAxis: 95,
                                lineStyle: { color: '#f59e0b', type: 'dotted', width: 1.5 },
                                label: { formatter: 'Ambang 95%', position: 'start', fontSize: 10, color: '#b45309' }
                            }
                        ]
                    }
                }]
            });

            new ResizeObserver(() => chartBulletInstance && chartBulletInstance.resize()).observe(bulletEl);
        }

        // 2. RAG Distribution Chart (Donut)
        const distEl = document.getElementById('kpi-distribution-chart');
        if (distEl) {
            if (chartDistributionInstance) {
                chartDistributionInstance.dispose();
            }
            chartDistributionInstance = window.echarts.init(distEl);

            const distData = [
                { value: ragCounts.tercapai || 0, name: 'Tercapai (>=100%)', itemStyle: { color: '#10b981' } },
                { value: ragCounts.hampir || 0, name: 'Mendekati (95-99.9%)', itemStyle: { color: '#f59e0b' } },
                { value: ragCounts.perlu || 0, name: 'Perlu Perhatian (<95%)', itemStyle: { color: '#f43f5e' } }
            ].filter(d => d.value > 0);

            chartDistributionInstance.setOption({
                animation: true,
                animationDuration: 650,
                animationEasing: 'cubicOut',
                animationDurationUpdate: 400,
                tooltip: {
                    trigger: 'item',
                    formatter: '{b}: <b>{c} indikator</b> ({d}%)'
                },
                legend: {
                    bottom: 0,
                    itemWidth: 10,
                    itemHeight: 10,
                    textStyle: { fontSize: 10 }
                },
                series: [{
                    name: 'Status RAG',
                    type: 'pie',
                    radius: ['45%', '72%'],
                    center: ['50%', '42%'],
                    avoidLabelOverlap: false,
                    label: {
                        show: false,
                        position: 'center'
                    },
                    emphasis: {
                        label: {
                            show: true,
                            fontSize: 12,
                            fontWeight: 'bold',
                            formatter: '{c}'
                        }
                    },
                    labelLine: { show: false },
                    data: distData
                }]
            });

            new ResizeObserver(() => chartDistributionInstance && chartDistributionInstance.resize()).observe(distEl);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAnalyticsCharts);
    } else {
        initAnalyticsCharts();
    }
})();
</script>
@endsection
