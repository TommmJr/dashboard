{{--
    Halaman Dashboard (halaman utama setelah login).
    - Dilindungi middleware 'auth', jadi hanya user yang login yang bisa lihat.
    - Daftar widget ditentukan DashboardController dari
      config/dashboard_layouts.php (per role, difilter Gate di backend),
      lalu di-render via SATU loop x-dynamic-component ke komponen di
      resources/views/components/widgets/.
    - State Alpine performanceDashboard() tetap di wrapper file ini:
      widget kpi-summary & trend-chart berbagi SATU fetch bersama dari
      GET /api/performance-data (sengaja tidak dipecah per widget).
    - Scope redesign 2026-11-18: hanya $isKpiDashboard (Dashboard Pengusahaan)
      yang memakai .font-pengusahaan (Geist) dan tata letak Bento Command Strip.
      Dashboard Admin dan Manajemen Aset dibiarkan utuh sesuai batasan scope.
--}}
@extends('layouts.app')

@section('title')
    {{ auth()->user()?->role?->slug === \App\Models\Role::ADMIN ? 'Dashboard Admin' : 'Dashboard' }}
@endsection

@php
    $currentUser = auth()->user();
    $isAdminDashboard = $currentUser?->role?->slug === \App\Models\Role::ADMIN;
    $isStaffDashboard = $currentUser?->role?->slug === \App\Models\Role::STAFF_DIVISI;
    $canAccessKpiDetail = \App\Http\Controllers\DashboardController::canAccessKpiDetail($currentUser);
    $isKpiDashboard = $canAccessKpiDetail && ! $isAdminDashboard;
    $isAssetDashboard = \App\Http\Controllers\AssetDetailController::canAccessAssetDetail($currentUser) && ! $isAdminDashboard;
    $kpiWidgetKeys = ['kpi-summary', 'trend-chart', 'nko-ranking', 'kinerja-realisasi', 'kinerja-detail', 'target-realisasi-per-indikator', 'pencapaian-nko', 'nko-donut', 'kpi-rag-status', 'custom-chart'];
    $hasKpiWidget = collect($widgets)->contains(fn (array $w): bool => in_array($w['key'] ?? '', $kpiWidgetKeys, true));
    $showKpiDictionary = $isKpiDashboard || $hasKpiWidget;
    $needsPerformanceState = ! $isAdminDashboard || collect($widgets)->contains(fn (array $w): bool => in_array($w['key'] ?? '', ['kpi-summary', 'trend-chart'], true));

    $execMeta = is_array($executiveMeta ?? null) ? $executiveMeta : [];
    $execUnitLabel = $execMeta['unit_label'] ?? (! empty(config('kpi.visible_units')) ? implode(', ', config('kpi.visible_units')) : 'Semua Unit');
    $execPeriodLabel = $execMeta['period_label'] ?? null;
    $execFreshness = $execMeta['freshness'] ?? ($kpiFreshness ?? null);
    $hasActiveFilter = ! empty($filters['start_date']) || ! empty($filters['end_date']) || ! empty($filters['units']);
@endphp

@section('content')
<div
    class="mx-auto min-w-0 {{ $isKpiDashboard ? 'max-w-[1600px] font-pengusahaan space-y-5' : ($isAssetDashboard ? 'max-w-7xl font-aset' : 'max-w-7xl') }}"
    @if ($needsPerformanceState) x-data="performanceDashboard(@js($filters ?? []))" x-init="load()" @endif
>
    @if ($isKpiDashboard)
        {{-- Unified Executive Command Strip (L1 Dashboard Pengusahaan) --}}
        <header id="dashboard-header" class="ibs-card px-4 py-3.5 sm:px-5 sm:py-4 animate-entrance">
            <div class="flex min-w-0 flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                {{-- Left: Identity & Telemetry Context --}}
                <div class="flex min-w-0 flex-col gap-1.5">
                    <div class="flex min-w-0 flex-wrap items-center gap-2">
                        <span class="inline-flex h-2 w-2 shrink-0 rounded-full bg-[#16a3b8]" aria-hidden="true"></span>
                        <h1 class="text-lg font-bold tracking-tight text-slate-900 sm:text-xl">Kinerja Pengusahaan</h1>
                        <span class="inline-flex items-center rounded-lg border border-slate-200/80 bg-slate-50 px-2.5 py-0.5 text-[11px] font-semibold text-slate-700 whitespace-nowrap">
                            {{ $execUnitLabel }}
                        </span>
                        <span class="inline-flex items-center rounded-full bg-[#e6f4f6] px-2.5 py-0.5 text-[11px] font-semibold text-[#0b6175] whitespace-nowrap">
                            {{ $execPeriodLabel ?? 'Belum tersedia' }}
                        </span>
                    </div>
                    <x-data-freshness-badge id="kpi-freshness-indicator" :freshness="$execFreshness" />
                </div>

                {{-- Right: Action Cluster (Single Solid Primary CTA) --}}
                <div class="flex min-w-0 max-w-full flex-wrap items-center gap-2 lg:shrink-0">
                    @if ($showKpiDictionary)
                        <button type="button" id="kpi-dictionary-trigger" @click="$dispatch('open-kpi-dictionary')" class="pg-control-btn inline-flex h-8 items-center gap-1.5 rounded-xl border border-slate-200/90 bg-white px-3 text-xs font-semibold text-slate-700 whitespace-nowrap hover:border-[#0b6175]/35 hover:bg-[#e6f4f6]/45 hover:text-[#0b6175] cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]">
                            <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span>Kamus KPI</span>
                        </button>
                    @endif

                    @can('dataset.import')
                        <a href="{{ route('datasets.create') }}" class="pg-control-btn inline-flex h-8 items-center gap-1.5 rounded-xl border border-slate-200/90 bg-white px-3 text-xs font-semibold text-slate-700 whitespace-nowrap hover:border-slate-300 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]">
                            <svg class="h-3.5 w-3.5 shrink-0 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M12 5v14M5 12h14" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span>Unggah Data</span>
                        </a>
                    @endcan

                    @if ($canAccessKpiDetail)
                        <a href="{{ route('dashboard.kpi-detail') }}" class="pg-control-btn inline-flex h-8 items-center gap-1.5 rounded-xl bg-[#0b6175] px-3.5 text-xs font-semibold text-white whitespace-nowrap shadow-xs hover:bg-[#094e5e] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8] focus-visible:ring-offset-1">
                            <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v0a2 2 0 0 1-2 2h-2a2 2 0 0 1-2-2Z" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M9 12h6M9 16h4" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span>Rincian KPI</span>
                        </a>
                    @endif
                </div>
            </div>

            @if (! $isStaffDashboard)
                {{-- Integrated Filter Bar (Row 2 untuk Management/Editor agar bebas crowding di 1280px) --}}
                <form id="dashboard-filter" x-ref="dashboardFilterForm" method="GET" action="{{ route('dashboard') }}" class="mt-3 flex min-w-0 max-w-full flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3">
                    <div class="flex min-w-0 max-w-full flex-wrap items-center gap-2">
                        {{-- Quick Period Presets --}}
                        <div class="inline-flex items-center rounded-xl border border-slate-200/80 bg-slate-100/80 p-0.5 text-[11px]" role="group" aria-label="Preset rentang periode">
                            <button
                                type="button"
                                @click="applyPeriodPreset('all')"
                                :class="!filters.start_date && !filters.end_date ? 'bg-white text-[#0b6175] font-semibold shadow-2xs ring-1 ring-slate-900/5' : 'text-slate-600 font-medium hover:text-slate-900'"
                                class="pill-tab-transition rounded-lg px-2.5 py-1 cursor-pointer whitespace-nowrap"
                            >
                                Semua
                            </button>
                            <button
                                type="button"
                                @click="applyPeriodPreset('3m')"
                                class="pill-tab-transition rounded-lg px-2.5 py-1 text-slate-600 font-medium hover:bg-white/70 hover:text-slate-900 cursor-pointer whitespace-nowrap"
                            >
                                3 Bulan
                            </button>
                            <button
                                type="button"
                                @click="applyPeriodPreset('ytd')"
                                class="pill-tab-transition rounded-lg px-2.5 py-1 text-slate-600 font-medium hover:bg-white/70 hover:text-slate-900 cursor-pointer whitespace-nowrap"
                            >
                                Tahun Ini
                            </button>
                        </div>

                        {{-- Compact Date Inputs --}}
                        <div class="flex min-w-0 max-w-full flex-wrap items-center gap-1.5 rounded-xl border border-slate-200/80 bg-slate-50/60 px-2.5 py-1">
                            <label for="start_date" class="sr-only">Dari tanggal</label>
                            <input id="start_date" x-ref="startDateInput" name="start_date" type="date" value="{{ $filters['start_date'] ?? '' }}" class="h-6 min-w-0 max-w-full bg-transparent text-xs font-medium text-slate-700 outline-none focus:text-slate-950">
                            <span class="text-[11px] font-medium text-slate-400" aria-hidden="true">s.d.</span>
                            <label for="end_date" class="sr-only">Sampai tanggal</label>
                            <input id="end_date" x-ref="endDateInput" name="end_date" type="date" value="{{ $filters['end_date'] ?? '' }}" class="h-6 min-w-0 max-w-full bg-transparent text-xs font-medium text-slate-700 outline-none focus:text-slate-950">
                        </div>
                    </div>

                    <div class="flex min-w-0 max-w-full flex-wrap items-center gap-1.5">
                        {{-- Unit Multi-Select Popover with Search --}}
                        <div class="relative min-w-0 max-w-full">
                            <details class="group relative">
                                <summary id="unit" aria-label="Pilih unit" class="flex h-8 min-w-[130px] max-w-full cursor-pointer list-none items-center justify-between gap-2 rounded-xl border border-slate-200/90 bg-white px-3 text-left text-xs font-medium text-slate-700 outline-none transition hover:border-[#0b6175]/40 focus-visible:ring-2 focus-visible:ring-[#16a3b8]/30">
                                    <span class="truncate" x-text="selectedUnitLabel()">{{ count($filters['units'] ?? []) > 0 ? count($filters['units']).' unit dipilih' : 'Semua unit' }}</span>
                                    <svg class="h-3.5 w-3.5 shrink-0 text-slate-400 transition group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                </summary>
                                <div class="absolute left-0 z-30 mt-1.5 w-60 max-w-[calc(100vw-4rem)] rounded-xl border border-slate-200/90 bg-white p-2 shadow-lg sm:left-auto sm:right-0" role="group" aria-label="Pilihan unit">
                                    @if (count($availableUnits ?? []) > 4)
                                        <div class="mb-1.5 px-1">
                                            <input
                                                type="search"
                                                x-model="unitSearch"
                                                placeholder="Cari unit..."
                                                aria-label="Cari unit dalam filter"
                                                class="w-full rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs text-slate-800 placeholder:text-slate-400 outline-none focus:border-[#0b6175] focus:bg-white"
                                            />
                                        </div>
                                    @endif
                                    <div class="max-h-52 overflow-y-auto space-y-0.5">
                                        @forelse ($availableUnits ?? [] as $unitName)
                                            <label
                                                x-show="unitSearch.trim() === '' || '{{ strtolower(addcslashes($unitName, "'\\")) }}'.includes(unitSearch.trim().toLowerCase())"
                                                class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-xs text-slate-700 hover:bg-[#e6f4f6]/50"
                                            >
                                                <input type="checkbox" name="unit[]" value="{{ $unitName }}" @checked(in_array($unitName, $filters['units'] ?? [], true)) class="h-3.5 w-3.5 rounded border-slate-300 text-[#0b6175] focus:ring-[#16a3b8]/30">
                                                <span class="truncate font-medium">{{ $unitName }}</span>
                                            </label>
                                        @empty
                                            <p class="px-2 py-1.5 text-xs text-slate-500">Belum ada unit.</p>
                                        @endforelse
                                    </div>
                                </div>
                            </details>
                        </div>

                        <button type="submit"
                                title="Terapkan Filter"
                                aria-label="Terapkan"
                                class="pg-control-btn inline-flex h-8 items-center gap-1.5 rounded-xl border border-[#0b6175]/25 bg-[#e6f4f6]/75 px-3 text-xs font-semibold text-[#0b6175] hover:bg-[#e6f4f6] cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/40">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 4h18M6 12h12M10 20h4" stroke-linecap="round" /></svg>
                            <span>Terapkan</span>
                        </button>

                        <a href="{{ route('dashboard') }}"
                           title="Reset Filter"
                           aria-label="Reset"
                           class="pg-control-btn inline-flex h-8 w-8 items-center justify-center rounded-xl border {{ $hasActiveFilter ? 'border-slate-300 bg-white text-slate-700' : 'border-slate-200/80 bg-white text-slate-400' }} hover:border-slate-300 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/40">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7M3 4v5h5" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            <span class="sr-only">Reset</span>
                        </a>
                    </div>
                </form>
            @endif
        </header>
    @elseif ($isStaffDashboard)
        <div id="dashboard-header" class="mb-4 flex min-w-0 flex-col gap-3 rounded-lg border border-slate-200/80 bg-white px-4 py-3 shadow-[0_1px_2px_0_rgba(0,0,0,0.03)] sm:flex-row sm:items-center sm:justify-between">
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-2">
                    <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-teal-500/20 bg-teal-50 text-primary">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M3 3v18h18" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="m19 9-5 5-4-4-3 3" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <h1 class="text-base font-bold tracking-tight text-primary">Dashboard Divisi</h1>
                </div>
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="inline-flex items-center rounded border border-teal-200/80 bg-teal-50 px-2 py-0.5 font-semibold text-teal-800">
                        {{ $currentUser?->division?->name ?? 'Divisi' }}
                    </span>
                    <span class="text-slate-300" aria-hidden="true">&bull;</span>
                    @if (in_array($currentUser?->division?->slug, ['managemen-aset', 'data-aset'], true))
                        <span class="inline-flex items-center gap-1.5 text-slate-500">
                            <strong class="font-semibold text-slate-800">UIT JBT</strong>
                            <span class="text-slate-300" aria-hidden="true">&bull;</span>
                            <span class="font-medium text-slate-600">8 UPT</span>
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 text-slate-500">
                            <strong class="font-semibold text-slate-800">{{ !empty(config('kpi.visible_units')) ? implode(', ', config('kpi.visible_units')) : 'Semua Unit' }}</strong>
                        </span>
                    @endif
                </div>
            </div>

            @can('dataset.import')
                <div class="flex shrink-0 items-center">
                    <a href="{{ route('datasets.create') }}" class="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-white shadow-2xs transition hover:bg-primary/90 focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M12 5v14M5 12h14" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        Unggah Dataset
                    </a>
                </div>
            @endcan
        </div>
    @else
        <div id="dashboard-header" class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between animate-entrance">
            <div class="flex min-w-0 flex-col gap-1.5">
                <h1 class="text-2xl font-extrabold tracking-tight text-primary">
                    {{ $isAdminDashboard ? 'Dashboard Admin' : 'Dashboard' }}
                </h1>
                @if ($isAdminDashboard)
                    <x-data-freshness-badge id="kpi-freshness-indicator" :freshness="$kpiFreshness ?? null" context-label="Dataset KPI" />
                @endif
            </div>

            @if ($isAdminDashboard)
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('datasets.index') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200/80 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-2xs transition hover:border-slate-300 hover:bg-slate-50">
                        <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M4 7c0-1.1 3.6-2 8-2s8 .9 8 2-3.6 2-8 2-8-.9-8-2Z"/>
                            <path d="M4 7v10c0 1.1 3.6 2 8 2s8-.9 8-2V7"/>
                            <path d="M4 12c0 1.1 3.6 2 8 2s8-.9 8-2"/>
                        </svg>
                        Dataset
                    </a>
                    <a href="{{ route('audit-logs.index') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200/80 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-2xs transition hover:border-slate-300 hover:bg-slate-50">
                        <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M4 12a8 8 0 1 0 2.3-5.7M4 4v5h5"/>
                            <path d="M12 8v4l2.5 1.5"/>
                        </svg>
                        Audit Log
                    </a>
                    @can('dashboard.update')
                        <a href="{{ route('dashboard.builder') }}" class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white shadow-2xs transition hover:bg-primary/90">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.4-9.4a2 2 0 1 1 2.8 2.8L11.8 15H9v-2.8l8.6-8.6Z"/>
                            </svg>
                            Atur Layout
                        </a>
                    @endcan
                    @if ($showKpiDictionary)
                        <button type="button" id="kpi-dictionary-trigger" @click="$dispatch('open-kpi-dictionary')" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200/90 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 whitespace-nowrap shadow-2xs transition hover:border-[#0b6175]/40 hover:bg-[#e6f4f6]/50 hover:text-[#0b6175] cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-1">
                            <span>Kamus KPI</span>
                        </button>
                    @endif
                </div>
            @endif

            @if (! $isAdminDashboard)
                <form id="dashboard-filter" method="GET" action="{{ route('dashboard') }}" class="flex min-w-0 max-w-full flex-wrap items-center gap-2">
                    <div class="flex min-w-0 max-w-full flex-wrap items-center gap-1.5">
                        <label for="start_date" class="sr-only">Dari tanggal</label>
                        <input id="start_date" name="start_date" type="date" value="{{ $filters['start_date'] ?? '' }}" class="h-9 min-w-0 max-w-full rounded border border-slate-200 bg-white px-2.5 text-sm text-primary outline-none focus:border-accent focus:ring-2 focus:ring-accent/20">
                        <span class="text-xs font-medium text-slate-400" aria-hidden="true">s.d.</span>
                        <label for="end_date" class="sr-only">Sampai tanggal</label>
                        <input id="end_date" name="end_date" type="date" value="{{ $filters['end_date'] ?? '' }}" class="h-9 min-w-0 max-w-full rounded border border-slate-200 bg-white px-2.5 text-sm text-primary outline-none focus:border-accent focus:ring-2 focus:ring-accent/20">
                    </div>

                    <div class="relative min-w-0 max-w-full">
                        <details class="group relative">
                            <summary id="unit" aria-label="Pilih unit" class="flex h-9 min-w-[130px] max-w-full cursor-pointer list-none items-center justify-between gap-2 rounded border border-slate-200 bg-white px-2.5 text-left text-sm text-primary outline-none transition hover:border-accent focus:border-accent focus:ring-2 focus:ring-accent/20">
                                <span class="truncate" x-text="selectedUnitLabel()">{{ count($filters['units'] ?? []) > 0 ? count($filters['units']).' unit dipilih' : 'Semua unit' }}</span>
                                <svg class="h-4 w-4 shrink-0 text-slate-400 transition group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            </summary>
                            <div class="absolute left-0 z-30 mt-1 max-h-60 w-56 max-w-[calc(100vw-5rem)] overflow-y-auto rounded border border-slate-200 bg-white p-2 shadow-lg sm:left-auto sm:right-0" role="group" aria-label="Pilihan unit">
                                @forelse ($availableUnits ?? [] as $unitName)
                                    <label class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm text-primary hover:bg-surface">
                                        <input type="checkbox" name="unit[]" value="{{ $unitName }}" @checked(in_array($unitName, $filters['units'] ?? [], true)) class="h-4 w-4 rounded border-slate-200 text-accent focus:ring-accent/30">
                                        <span class="truncate">{{ $unitName }}</span>
                                    </label>
                                @empty
                                    <p class="px-2 py-1.5 text-sm text-slate-500">Belum ada unit.</p>
                                @endforelse
                            </div>
                        </details>
                    </div>

                    <div class="flex items-center gap-1.5">
                        <button type="submit"
                                title="Terapkan Filter"
                                aria-label="Terapkan"
                                class="inline-flex h-9 w-9 items-center justify-center rounded bg-accent text-white transition hover:bg-primary focus:outline-none focus:ring-2 focus:ring-accent/30 disabled:cursor-not-allowed disabled:opacity-60">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 4h18M6 12h12M10 20h4" stroke-linecap="round" /></svg>
                            <span class="sr-only">Terapkan</span>
                        </button>
                        <a href="{{ route('dashboard') }}"
                           title="Reset Filter"
                           aria-label="Reset"
                           class="inline-flex h-9 w-9 items-center justify-center rounded border border-slate-200 bg-white text-slate-600 transition hover:border-accent hover:text-accent focus:outline-none focus:ring-2 focus:ring-accent/30">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7M3 4v5h5" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            <span class="sr-only">Reset</span>
                        </a>
                    </div>
                </form>
            @endif
        </div>
    @endif

    @if (! $isAdminDashboard && ! $isStaffDashboard)
        <p x-show="error" x-cloak class="-mt-4 mb-4 text-sm font-medium text-red-700" x-text="error" role="alert"></p>
    @endif

    @if ($isAdminDashboard && is_array($systemHealth ?? null))
        <div class="mb-5">
            <x-system-health-panel :health="$systemHealth" />
        </div>
    @endif

    {{-- Widget per role: satu loop generik, urutan & komposisi dari config. --}}
    <div class="grid grid-cols-1 grid-flow-dense gap-4 sm:gap-5 lg:grid-cols-3">
        @if ($isStaffDashboard && count($widgets) === 1 && ($widgets[0]['key'] ?? '') === 'dataset-status')
            <div class="h-full min-w-0 max-w-full rounded-lg border border-slate-200 bg-white p-5 shadow-2xs lg:col-span-2">
                <div class="flex items-center gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-primary">
                        <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5m-4 0h4" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <h2 class="text-base font-bold text-primary">Ruang Kerja Divisi {{ auth()->user()?->division?->name ?? '' }}</h2>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="rounded-lg border border-slate-200/80 bg-slate-50/50 p-3.5">
                        <p class="text-xs font-semibold text-slate-800">Manajemen Dataset</p>
                        <p class="mt-1 text-xs text-slate-500">Riwayat unggah dan status verifikasi.</p>
                        <a href="{{ route('datasets.index') }}" class="mt-2.5 inline-flex items-center gap-1 text-xs font-semibold text-accent transition hover:text-primary">
                            Lihat Arsip Dataset &rarr;
                        </a>
                    </div>
                    <div class="rounded-lg border border-slate-200/80 bg-slate-50/50 p-3.5">
                        <p class="text-xs font-semibold text-slate-800">Unggah Data Baru</p>
                        <p class="mt-1 text-xs text-slate-500">Impor berkas dataset operasional.</p>
                        @can('dataset.import')
                            <a href="{{ route('datasets.create') }}" class="mt-2.5 inline-flex items-center gap-1 text-xs font-semibold text-accent transition hover:text-primary">
                                Unggah Dataset Baru &rarr;
                            </a>
                        @else
                            <span class="mt-2.5 inline-flex text-xs text-slate-400">Hubungi Admin untuk izin impor</span>
                        @endcan
                    </div>
                </div>

                <div class="mt-3.5 flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-xs text-slate-600">
                    <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-slate-400" aria-hidden="true"></span>
                    <span>Visualisasi analitik divisi belum tersedia.</span>
                </div>
            </div>
        @endif

        @foreach ($widgets as $widget)
            @php
                $isSingleStaffWidget = $isStaffDashboard && count($widgets) === 1 && ($widget['key'] ?? '') === 'dataset-status';
                $hasKpiStaffLayout = $isStaffDashboard && collect($widgets)->contains(fn (array $w): bool => in_array($w['key'] ?? '', ['kpi-summary', 'kinerja-detail', 'nko-ranking'], true));
                $widgetClass = match (true) {
                    $isSingleStaffWidget => 'lg:col-span-1',
                    $isStaffDashboard && ($widget['key'] ?? '') === 'nko-ranking' => 'lg:col-span-2',
                    $hasKpiStaffLayout && ($widget['key'] ?? '') === 'dataset-status' => 'lg:col-span-1',
                    default => match ((int) ($widget['cols'] ?? 3)) {
                        1 => 'lg:col-span-1',
                        2 => 'lg:col-span-2',
                        default => 'lg:col-span-3',
                    },
                };
                $staggerIndex = (($loop->iteration - 1) % 6) + 1;
            @endphp
            <div class="{{ $widgetClass }} h-full min-w-0 max-w-full animate-entrance stagger-{{ $staggerIndex }}">
                @if (($widget['key'] ?? '') === 'aset-upt-chart')
                    @include('components.widgets.aset-upt-chart', ['widget' => $widget, 'data' => $widget['data'] ?? $widgetData['aset-upt-chart'] ?? null])
                @elseif (($widget['key'] ?? '') === 'kpi-summary')
                    <x-dynamic-component :component="$widget['view']" :widget="$widget" :data="$widget['data'] ?? null" :executive-meta="$executiveMeta ?? null" />
                @else
                    <x-dynamic-component :component="$widget['view']" :widget="$widget" :data="$widget['data'] ?? null" />
                @endif
            </div>
        @endforeach
    </div>

    @if ($showKpiDictionary)
        <x-kpi-dictionary-drawer />
    @endif
</div>

<script @cspNonce>
    let performanceChartInstance = null;

    function performanceDashboard(initial = {}) {
        return {
            loading: true,
            empty: false,
            kpi: null,
            error: '',
            units: [],
            periodLabels: [],
            lastPayload: null,
            chartStyle: 'area',
            showTargetLine: true,
            trendDelta: null,
            unitSearch: '',
            annotations: [],
            annotationsOpen: false,
            annotationFormOpen: false,
            annotationPeriod: '',
            annotationNote: '',
            editingPeriod: null,
            confirmDeletePeriod: null,
            annotationSaving: false,
            annotationError: '',
            annotationSuccess: '',
            filters: {
                start_date: initial.start_date ?? '',
                end_date: initial.end_date ?? '',
                units: initial.units ?? [],
            },

            formatPeriodChip(raw) {
                const key = String(raw || '').slice(0, 7);
                if (!/^\d{4}-\d{2}$/.test(key)) {
                    return String(raw || '');
                }
                const shortMonths = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
                const [year, monthStr] = key.split('-');
                const monthLabel = shortMonths[Number(monthStr) - 1] || monthStr;
                return `${monthLabel} ${year}`;
            },

            applyPeriodPreset(preset) {
                const startInput = this.$refs.startDateInput;
                const endInput = this.$refs.endDateInput;
                const form = this.$refs.dashboardFilterForm;
                if (!startInput || !endInput || !form) return;

                if (preset === 'all') {
                    startInput.value = '';
                    endInput.value = '';
                    form.submit();
                    return;
                }

                const now = new Date();
                const pad = (n) => String(n).padStart(2, '0');
                const toIso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

                let startDate;
                if (preset === '3m') {
                    startDate = new Date(now.getFullYear(), now.getMonth() - 2, 1);
                } else {
                    startDate = new Date(now.getFullYear(), 0, 1);
                }

                startInput.value = toIso(startDate);
                endInput.value = toIso(now);
                form.submit();
            },

            setChartStyle(style) {
                if (this.chartStyle === style) return;
                this.chartStyle = style;
                if (this.lastPayload && !this.empty) {
                    this.$nextTick(() => this.renderChart(this.lastPayload));
                }
            },

            toggleTargetLine() {
                this.showTargetLine = !this.showTargetLine;
                if (this.lastPayload && !this.empty) {
                    this.$nextTick(() => this.renderChart(this.lastPayload));
                }
            },

            computeTrendDelta(payload) {
                const labels = Array.isArray(payload?.labels) ? payload.labels : [];
                const series = Array.isArray(payload?.series) ? payload.series : [];
                if (labels.length < 2 || series.length === 0) {
                    this.trendDelta = null;
                    return;
                }

                const lastIdx = labels.length - 1;
                const prevIdx = labels.length - 2;
                const avgAt = (idx) => {
                    const vals = series
                        .map((s) => (Array.isArray(s.data) ? s.data[idx] : null))
                        .filter((v) => v !== null && v !== undefined && !Number.isNaN(Number(v)))
                        .map(Number);
                    if (vals.length === 0) return null;
                    return vals.reduce((a, b) => a + b, 0) / vals.length;
                };

                const curr = avgAt(lastIdx);
                const prev = avgAt(prevIdx);
                if (curr === null || prev === null) {
                    this.trendDelta = null;
                    return;
                }

                const diff = Number((curr - prev).toFixed(2));
                const sign = diff > 0 ? '+' : '';
                this.trendDelta = {
                    value: diff,
                    label: `${sign}${diff.toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} vs ${this.formatPeriodChip(labels[prevIdx])}`,
                    positive: diff >= 0,
                };
            },

            availableAnnotationPeriods() {
                const monthNames = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                const map = new Map();

                (Array.isArray(this.periodLabels) ? this.periodLabels : []).forEach((raw) => {
                    const key = String(raw || '').slice(0, 7);
                    if (!/^\d{4}-\d{2}$/.test(key) || map.has(key)) {
                        return;
                    }
                    const [year, monthStr] = key.split('-');
                    const monthLabel = monthNames[Number(monthStr) - 1] || monthStr;
                    map.set(key, { key, label: `${monthLabel} ${year}` });
                });

                if (this.editingPeriod && /^\d{4}-\d{2}$/.test(this.editingPeriod) && !map.has(this.editingPeriod)) {
                    const [year, monthStr] = this.editingPeriod.split('-');
                    const monthLabel = monthNames[Number(monthStr) - 1] || monthStr;
                    map.set(this.editingPeriod, { key: this.editingPeriod, label: `${monthLabel} ${year}` });
                }

                return Array.from(map.values()).sort((a, b) => b.key.localeCompare(a.key));
            },

            openAnnotationForm() {
                this.annotationError = '';
                this.annotationSuccess = '';
                this.editingPeriod = null;
                const options = this.availableAnnotationPeriods();
                this.annotationPeriod = options.length > 0 ? options[0].key : '';
                this.annotationNote = '';
                this.annotationsOpen = true;
                this.annotationFormOpen = true;
            },

            editAnnotation(item) {
                this.annotationError = '';
                this.annotationSuccess = '';
                this.confirmDeletePeriod = null;
                this.editingPeriod = item.period;
                this.annotationPeriod = item.period;
                this.annotationNote = item.note || '';
                this.annotationsOpen = true;
                this.annotationFormOpen = true;
            },

            closeAnnotationForm() {
                this.annotationFormOpen = false;
                this.editingPeriod = null;
                this.annotationError = '';
            },

            async saveAnnotation(endpoint) {
                this.annotationError = '';
                this.annotationSuccess = '';

                const noteText = String(this.annotationNote || '').trim();
                if (!this.annotationPeriod) {
                    this.annotationError = 'Pilih periode terlebih dahulu.';
                    return;
                }
                if (!noteText) {
                    this.annotationError = 'Catatan periode tidak boleh kosong.';
                    return;
                }

                this.annotationSaving = true;
                try {
                    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                    const response = await fetch(endpoint, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({
                            period: this.annotationPeriod,
                            note: noteText,
                            start_date: this.filters.start_date || null,
                            end_date: this.filters.end_date || null,
                        }),
                    });

                    if (response.status === 401 || response.status === 419) {
                        window.location.href = "{{ route('login') }}";
                        return;
                    }

                    const data = await response.json().catch(() => ({}));
                    if (!response.ok) {
                        const firstFieldError = data?.errors ? Object.values(data.errors).flat()[0] : null;
                        this.annotationError = firstFieldError || data?.message || 'Gagal menyimpan catatan periode.';
                        return;
                    }

                    this.annotations = Array.isArray(data.annotations) ? data.annotations : [];
                    this.annotationSuccess = data.message || 'Catatan periode berhasil disimpan.';
                    this.annotationFormOpen = false;
                    this.annotationsOpen = true;
                    this.editingPeriod = null;
                    this.annotationNote = '';
                } catch (e) {
                    this.annotationError = 'Gagal menyimpan catatan periode.';
                } finally {
                    this.annotationSaving = false;
                }
            },

            async deleteAnnotation(endpoint) {
                this.annotationError = '';
                this.annotationSuccess = '';
                this.annotationSaving = true;

                try {
                    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                    const deleteUrl = new URL(endpoint, window.location.origin);
                    if (this.filters.start_date) deleteUrl.searchParams.set('start_date', this.filters.start_date);
                    if (this.filters.end_date) deleteUrl.searchParams.set('end_date', this.filters.end_date);
                    const response = await fetch(deleteUrl, {
                        method: 'DELETE',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                    });

                    if (response.status === 401 || response.status === 419) {
                        window.location.href = "{{ route('login') }}";
                        return;
                    }

                    const data = await response.json().catch(() => ({}));
                    if (!response.ok) {
                        this.annotationError = data?.message || 'Gagal menghapus catatan periode.';
                        return;
                    }

                    this.annotations = Array.isArray(data.annotations) ? data.annotations : [];
                    this.confirmDeletePeriod = null;
                    this.annotationSuccess = data.message || 'Catatan periode berhasil dihapus.';
                } catch (e) {
                    this.annotationError = 'Gagal menghapus catatan periode.';
                } finally {
                    this.annotationSaving = false;
                }
            },

            formatNumber(value) {
                return new Intl.NumberFormat('id-ID', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                }).format(value);
            },

            hasFilters() {
                return Object.values(this.filters).some((value) => Array.isArray(value) ? value.length > 0 : value !== '');
            },

            selectedUnitLabel() {
                if (this.filters.units.length === 0) {
                    return 'Semua unit';
                }

                return `${this.filters.units.length} unit dipilih`;
            },

            async applyFilters() {
                if (this.filters.start_date !== '' && this.filters.end_date !== '' && this.filters.start_date > this.filters.end_date) {
                    this.error = 'Tanggal akhir harus sama atau setelah tanggal mulai.';
                    return;
                }

                await this.load();
            },

            async resetFilters() {
                this.filters = { start_date: '', end_date: '', units: [] };
                await this.load();
            },

            async load() {
                this.loading = true;
                this.error = '';

                try {
                    const url = new URL("{{ route('performance.data') }}", window.location.origin);
                    Object.entries(this.filters).forEach(([key, value]) => {
                        if (Array.isArray(value)) {
                            value.forEach((item) => url.searchParams.append('unit[]', item));
                        } else if (value !== '') {
                            url.searchParams.set(key, value);
                        }
                    });

                    const response = await fetch(url, {
                        headers: { 'Accept': 'application/json' },
                    });

                    if (!response.ok) {
                        if (response.status === 401 || response.status === 419) {
                            window.location.href = "{{ route('login') }}";
                            return;
                        }
                        if (response.status === 422) {
                            this.error = 'Filter tanggal atau unit tidak valid.';
                            this.empty = true;
                            return;
                        }
                        throw new Error(`HTTP ${response.status}`);
                    }

                    const payload = await response.json();

                    if (!this.hasFilters()) {
                        this.units = payload.series.map((series) => series.name);
                    }
                    this.kpi = payload.kpi;
                    this.empty = payload.labels.length === 0;
                    this.periodLabels = payload.labels;
                    this.lastPayload = payload;
                    this.annotations = Array.isArray(payload.annotations) ? payload.annotations : [];
                    this.computeTrendDelta(payload);

                    if (!this.empty) {
                        this.$nextTick(() => this.renderChart(payload));
                    }
                } catch (e) {
                    console.error('Chart loading error:', e);
                    this.error = 'Gagal memuat data kinerja.';
                    this.empty = true;
                } finally {
                    this.loading = false;
                }
            },

            renderChart(payload) {
                const el = document.getElementById('performanceChart');
                if (!el) return;
                if (!window.echarts) return;
                if (performanceChartInstance) { performanceChartInstance.dispose(); }
                performanceChartInstance = window.echarts.init(el);

                const tokens = window.PLN_CHART_PALETTE || {
                    primary: '#0b6175',
                    accent: '#16a3b8',
                    softTeal: '#bce3e8',
                    targetSlate: '#cbd5e1',
                    gridLine: '#f1f5f9',
                    axisText: '#64748b',
                    rag: { tercapai: '#10b981', waspada: '#f59e0b', kritis: '#f43f5e' },
                };
                const chartFont = window.PLN_PENGUSAHAAN_FONT || "'Geist', 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif";

                const fmtTooltipNumber = (v) => (v === null || v === undefined || String(v).trim() === '' || Number.isNaN(Number(v)))
                    ? 'Tidak tercatat'
                    : Number(v).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 2 });

                const targetMarkLine = this.showTargetLine ? {
                    silent: true,
                    symbol: 'none',
                    data: [{
                        yAxis: 100,
                        lineStyle: { color: tokens.primary, type: 'dashed', width: 1.5, opacity: 0.7 },
                        label: {
                            formatter: 'Target 100',
                            position: 'insideEndTop',
                            color: tokens.primary,
                            fontFamily: chartFont,
                            fontSize: 10.5,
                            fontWeight: 600,
                        },
                    }],
                } : undefined;

                if (payload.labels.length <= 1) {
                    const lastIdx = Math.max(0, payload.labels.length - 1);
                    const ranked = payload.series
                        .filter((s) => s.data[lastIdx] !== null && s.data[lastIdx] !== undefined)
                        .map((s) => ({ name: s.name, value: Number(s.data[lastIdx]) }))
                        .sort((a, b) => b.value - a.value);

                    performanceChartInstance.setOption({
                        animation: true,
                        animationDuration: 550,
                        animationEasing: 'cubicOut',
                        textStyle: { fontFamily: chartFont },
                        tooltip: {
                            trigger: 'axis',
                            axisPointer: { type: 'shadow' },
                            backgroundColor: '#ffffff',
                            borderColor: '#e2e8f0',
                            borderWidth: 1,
                            textStyle: { fontFamily: chartFont, fontSize: 12, color: '#0f172a' },
                            valueFormatter: fmtTooltipNumber,
                        },
                        grid: { left: 54, right: 20, top: 24, bottom: 38 },
                        xAxis: {
                            type: 'category',
                            data: ranked.map((row) => row.name),
                            axisLine: { lineStyle: { color: tokens.gridLine } },
                            axisTick: { show: false },
                            axisLabel: {
                                interval: 0,
                                rotate: ranked.length > 8 ? 35 : 0,
                                color: tokens.axisText,
                                fontFamily: chartFont,
                                fontSize: 11,
                            },
                        },
                        yAxis: {
                            type: 'value',
                            min: 0,
                            axisLabel: { color: tokens.axisText, fontFamily: chartFont, fontSize: 11 },
                            splitLine: { lineStyle: { color: tokens.gridLine } },
                        },
                        series: [{
                            name: 'NKO',
                            type: 'bar',
                            barMaxWidth: 42,
                            data: ranked.map((row) => ({
                                value: row.value,
                                itemStyle: {
                                    color: row.value >= 100 ? tokens.primary : (row.value >= 95 ? tokens.rag.waspada : tokens.rag.kritis),
                                    borderRadius: [6, 6, 0, 0],
                                },
                            })),
                            markLine: targetMarkLine,
                            label: {
                                show: true,
                                position: 'top',
                                color: '#0f172a',
                                fontFamily: chartFont,
                                fontWeight: 600,
                                fontSize: 11,
                                formatter: (params) => fmtTooltipNumber(params.value),
                            },
                        }],
                    });
                } else {
                    const palette = [tokens.primary, tokens.accent, '#0284c7', '#64748b'];
                    const isBar = this.chartStyle === 'bar';
                    const isArea = this.chartStyle === 'area';
                    const formattedLabels = payload.labels.map((l) => this.formatPeriodChip(l));

                    const chartSeries = payload.series.map((s, i) => {
                        const baseColor = palette[i % palette.length];
                        const item = isBar
                            ? {
                                name: s.name,
                                type: 'bar',
                                barMaxWidth: 36,
                                data: s.data.map((val) => {
                                    if (val === null || val === undefined) return null;
                                    const num = Number(val);
                                    return {
                                        value: num,
                                        itemStyle: {
                                            color: payload.series.length === 1
                                                ? (num >= 100 ? tokens.primary : (num >= 95 ? tokens.rag.waspada : tokens.rag.kritis))
                                                : baseColor,
                                            borderRadius: [6, 6, 0, 0],
                                        },
                                    };
                                }),
                                label: {
                                    show: payload.series.length === 1 && payload.labels.length <= 12,
                                    position: 'top',
                                    color: '#0f172a',
                                    fontFamily: chartFont,
                                    fontWeight: 600,
                                    fontSize: 11,
                                    formatter: (params) => fmtTooltipNumber(params.value),
                                },
                            }
                            : {
                                name: s.name,
                                type: 'line',
                                smooth: true,
                                symbol: 'circle',
                                symbolSize: 6,
                                showSymbol: payload.labels.length <= 14,
                                data: s.data,
                                lineStyle: { width: 2.5, color: baseColor },
                                itemStyle: { color: baseColor },
                            };

                        if (!isBar && isArea && i === 0 && window.echarts?.graphic?.LinearGradient) {
                            item.areaStyle = {
                                color: new window.echarts.graphic.LinearGradient(0, 0, 0, 1, [
                                    { offset: 0, color: 'rgba(11, 97, 117, 0.16)' },
                                    { offset: 1, color: 'rgba(11, 97, 117, 0.01)' },
                                ]),
                            };
                        }

                        if (i === 0 && targetMarkLine) {
                            item.markLine = targetMarkLine;
                        }

                        return item;
                    });

                    performanceChartInstance.setOption({
                        animation: true,
                        animationDuration: 550,
                        animationEasing: 'cubicOut',
                        textStyle: { fontFamily: chartFont },
                        tooltip: {
                            trigger: 'axis',
                            axisPointer: isBar ? { type: 'shadow' } : { type: 'line' },
                            order: 'valueDesc',
                            backgroundColor: '#ffffff',
                            borderColor: '#e2e8f0',
                            borderWidth: 1,
                            textStyle: { fontFamily: chartFont, fontSize: 12, color: '#0f172a' },
                            valueFormatter: fmtTooltipNumber,
                        },
                        legend: {
                            bottom: 0,
                            type: 'scroll',
                            show: payload.series.length > 1,
                            textStyle: { color: tokens.axisText, fontFamily: chartFont, fontSize: 11 },
                        },
                        grid: {
                            left: 50,
                            right: 20,
                            top: 24,
                            bottom: payload.series.length > 1 ? 42 : 28,
                        },
                        xAxis: {
                            type: 'category',
                            boundaryGap: isBar,
                            data: formattedLabels,
                            axisLine: { lineStyle: { color: tokens.gridLine } },
                            axisTick: { show: false },
                            axisLabel: { color: tokens.axisText, fontFamily: chartFont, fontSize: 11 },
                        },
                        yAxis: {
                            type: 'value',
                            min: payload.y_axis_min ?? 0,
                            axisLabel: { color: tokens.axisText, fontFamily: chartFont, fontSize: 11 },
                            splitLine: { lineStyle: { color: tokens.gridLine } },
                        },
                        series: chartSeries,
                    }, true);
                }

                if (this.resizeObserver) {
                    this.resizeObserver.disconnect();
                }
                this.resizeObserver = new ResizeObserver(() => {
                    performanceChartInstance && performanceChartInstance.resize();
                });
                this.resizeObserver.observe(el);
            },
        };
    }
</script>
@endsection
