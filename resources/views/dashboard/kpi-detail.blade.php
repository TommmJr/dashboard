@extends('layouts.app')

@section('title', 'Rincian Kinerja KPI')

@section('content')
<div class="mx-auto max-w-7xl space-y-4">
    <div id="kpi-detail-header" class="flex flex-col gap-3 rounded-lg border border-slate-200/80 bg-white px-4 py-3 shadow-[0_1px_2px_0_rgba(0,0,0,0.03)] sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-wrap items-center gap-3">
            <div class="flex items-center gap-2">
                <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-teal-500/20 bg-teal-50 text-primary">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M9 17v-6m3 6V7m3 10v-4M5 21h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2Z" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <h1 class="text-base font-bold tracking-tight text-primary">Rincian Kinerja KPI</h1>
            </div>
            <div class="flex items-center gap-2 text-xs">
                <span class="inline-flex items-center gap-1 text-slate-500">
                    <span>Cakupan Unit:</span>
                    <strong class="font-semibold text-slate-800">{{ !empty(config('kpi.visible_units')) ? implode(', ', config('kpi.visible_units')) : 'Semua Unit' }}</strong>
                </span>
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50">
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
