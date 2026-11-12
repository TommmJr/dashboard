@extends('layouts.app')

@section('title', 'Rincian Kinerja KPI')

@section('content')
<div class="mx-auto max-w-7xl space-y-4">
    <div id="kpi-detail-header" class="flex flex-col gap-3 rounded-xl border border-slate-200/80 bg-white px-4 py-3 shadow-xs sm:flex-row sm:items-center sm:justify-between transition-all duration-200">
        <div class="flex flex-wrap items-center gap-3">
            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-teal-500/20 bg-teal-50 text-primary shadow-2xs">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M9 17v-6m3 6V7m3 10v-4M5 21h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2Z" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <div>
                <h1 class="text-base font-bold tracking-tight text-primary">Rincian Kinerja KPI</h1>
            </div>
            <div class="flex items-center gap-2 text-xs">
                <span class="inline-flex items-center gap-1 text-slate-500">
                    <span>Cakupan Unit:</span>
                    <strong class="font-semibold text-slate-800">{{ !empty(config('kpi.visible_units')) ? implode(', ', config('kpi.visible_units')) : 'Semua Unit' }}</strong>
                </span>
            </div>
        </div>

        <div class="flex flex-wrap shrink-0 items-center gap-2">
            @if (\App\Http\Controllers\KpiAnalyticsController::canAccessKpiAnalytics(auth()->user()))
                <a href="{{ route('dashboard.kpi-analytics') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-teal-200 bg-teal-50 px-3 py-1.5 text-xs font-semibold text-teal-800 transition hover:bg-teal-100">
                    <svg class="h-3.5 w-3.5 text-teal-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M3 3v18h18" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="m19 9-5 5-4-4-3 3" stroke-linecap="round" stroke-linejoin="round" />
                        <circle cx="19" cy="9" r="1.5" fill="currentColor" stroke="none" />
                    </svg>
                    Analitik & Bottleneck KPI
                </a>
            @endif
            <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50">
                &larr; Kembali ke Ringkasan
            </a>
        </div>
    </div>

    <div>
        <x-widgets.target-realisasi-per-indikator
            :widget="$targetRealisasiWidget"
            :data="$targetRealisasiData"
        />
    </div>

    <div id="kinerja-detail">
        <x-widgets.kinerja-detail
            :widget="$kinerjaDetailWidget"
            :data="$kinerjaDetailData"
        />
    </div>
</div>
@endsection
