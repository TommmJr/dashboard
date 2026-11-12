@extends('layouts.app')

@section('title', 'Rincian Kinerja KPI')

@section('content')
@php
    $csvRows = [];
    $panelMap = [];
    foreach (($targetRealisasiData['sections'] ?? []) as $section) {
        foreach (($section['panels'] ?? []) as $panel) {
            $panelMap[$panel['name']] = $panel;
        }
    }

    $sourceRows = $kinerjaDetailData['rows'] ?? [];
    if (! empty($sourceRows)) {
        foreach ($sourceRows as $row) {
            $name = $row['indikator'] ?? '';
            $panel = $panelMap[$name] ?? null;
            $pct = $row['pencapaian'] ?? null;
            $status = '—';
            if ($pct !== null) {
                $tier = \App\Support\KpiScoreNormalizer::ragTier((float) $pct);
                $status = match ($tier) {
                    \App\Support\KpiScoreNormalizer::TIER_TERCAPAI => 'Tercapai',
                    \App\Support\KpiScoreNormalizer::TIER_HAMPIR => 'Mendekati',
                    default => 'Perlu Perhatian',
                };
            }
            $targetVal = $row['target_display'] ?? ($row['target'] !== null ? $row['target'] : ($panel['numeric']['target'] ?? ($panel['date']['target'] ?? '—')));
            $realisasiVal = $row['realisasi_display'] ?? ($row['realisasi'] !== null ? $row['realisasi'] : ($panel['numeric']['realisasi'] ?? ($panel['date']['realisasi'] ?? '—')));
            $satuanVal = $panel['satuan'] ?? ($row['satuan'] ?? '—');
            $pctStr = $pct !== null ? number_format((float) $pct, 1, ',', '') : ($panel['score']['percent'] !== null ? number_format((float) $panel['score']['percent'], 1, ',', '') : '—');

            $csvRows[] = [
                'indikator' => $name,
                'polaritas' => 'Positif',
                'satuan' => $satuanVal ?: '—',
                'bobot' => '—',
                'target' => $targetVal,
                'realisasi' => $realisasiVal,
                'pencapaian' => $pctStr,
                'status' => $status,
            ];
        }
    } else {
        foreach ($panelMap as $name => $panel) {
            $pct = $panel['score']['percent'] ?? null;
            $status = '—';
            if ($pct !== null) {
                $tier = \App\Support\KpiScoreNormalizer::ragTier((float) $pct);
                $status = match ($tier) {
                    \App\Support\KpiScoreNormalizer::TIER_TERCAPAI => 'Tercapai',
                    \App\Support\KpiScoreNormalizer::TIER_HAMPIR => 'Mendekati',
                    default => 'Perlu Perhatian',
                };
            }
            $targetVal = $panel['numeric']['target'] ?? ($panel['date']['target'] ?? '—');
            $realisasiVal = $panel['numeric']['realisasi'] ?? ($panel['date']['realisasi'] ?? '—');
            $pctStr = $pct !== null ? number_format((float) $pct, 1, ',', '') : '—';

            $csvRows[] = [
                'indikator' => $name,
                'polaritas' => 'Positif',
                'satuan' => $panel['satuan'] ?? '—',
                'bobot' => '—',
                'target' => $targetVal,
                'realisasi' => $realisasiVal,
                'pencapaian' => $pctStr,
                'status' => $status,
            ];
        }
    }
@endphp

<style>
@media print {
    @page {
        size: landscape;
        margin: 8mm;
    }
    aside,
    nav,
    header,
    #header-breadcrumb,
    #kpi-detail-actions,
    #kpi-view-switcher,
    #target-realisasi-toolbar,
    button,
    .no-print {
        display: none !important;
    }
    body, main, #kpi-detail-container {
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
        width: 100% !important;
        max-width: 100% !important;
        overflow: visible !important;
    }
    .shadow-xs, .shadow-2xs, .shadow-md, .shadow-sm {
        box-shadow: none !important;
    }
    .border {
        border-color: #cbd5e1 !important;
    }
}
</style>

<script>
if (typeof window.kpiDetailManager !== 'function') {
    window.kpiDetailManager = function() {
        return {
            viewMode: 'split',
            isFullscreen: false,
            init() {
                document.addEventListener('fullscreenchange', () => {
                    this.isFullscreen = !!document.fullscreenElement;
                });
            },
            toggleFullscreen() {
                const el = document.getElementById('kpi-detail-container') || document.documentElement;
                if (!this.isFullscreen && !document.fullscreenElement) {
                    if (el.requestFullscreen) {
                        el.requestFullscreen().catch(() => {});
                    } else if (el.webkitRequestFullscreen) {
                        el.webkitRequestFullscreen();
                    }
                    this.isFullscreen = true;
                } else {
                    if (document.fullscreenElement) {
                        if (document.exitFullscreen) {
                            document.exitFullscreen().catch(() => {});
                        } else if (document.webkitExitFullscreen) {
                            document.webkitExitFullscreen();
                        }
                    }
                    this.isFullscreen = false;
                }
            },
            downloadCsv() {
                const rawData = JSON.parse(document.getElementById('kpiExportData')?.textContent || '[]');
                let csv = '\uFEFFsep=;\r\n';
                csv += 'Indikator;Polaritas;Satuan;Bobot;Target;Realisasi;Pencapaian (%);Status\r\n';
                rawData.forEach(row => {
                    const clean = (val) => '"' + String(val ?? '').replace(/"/g, '""') + '"';
                    csv += [
                        clean(row.indikator),
                        clean(row.polaritas),
                        clean(row.satuan),
                        clean(row.bobot),
                        clean(row.target),
                        clean(row.realisasi),
                        clean(row.pencapaian),
                        clean(row.status)
                    ].join(';') + '\r\n';
                });
                const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.setAttribute('href', url);
                link.setAttribute('download', 'Rincian_Kinerja_KPI_' + new Date().toISOString().slice(0, 10) + '.csv');
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                URL.revokeObjectURL(url);
            }
        };
    };
}
</script>

<script type="application/json" id="kpiExportData">
{!! json_encode($csvRows) !!}
</script>

<div
    id="kpi-detail-container"
    x-data="kpiDetailManager()"
    :class="isFullscreen ? 'fixed inset-0 z-50 bg-slate-50 p-4 overflow-auto' : 'mx-auto max-w-[1600px] -mt-2 sm:-mt-4 space-y-1.5'"
>
    <div id="kpi-detail-header" class="flex flex-col gap-2 rounded-xl border border-slate-200/80 bg-white px-3.5 py-2 shadow-2xs transition-all duration-200">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-2.5">
                <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg border border-teal-500/20 bg-teal-50 text-primary shadow-2xs">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M9 17v-6m3 6V7m3 10v-4M5 21h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2Z" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div>
                    <h1 class="text-sm font-bold tracking-tight text-primary whitespace-nowrap">Rincian Kinerja KPI</h1>
                </div>
                <div class="flex items-center gap-1.5 text-xs text-slate-500 whitespace-nowrap">
                    <span>Cakupan Unit:</span>
                    <strong class="font-semibold text-slate-800">{{ !empty(config('kpi.visible_units')) ? implode(', ', config('kpi.visible_units')) : 'Semua Unit' }}</strong>
                </div>
            </div>

            <div id="kpi-detail-actions" class="flex flex-wrap shrink-0 items-center gap-1.5">
                <button
                    type="button"
                    id="kpi-detail-fullscreen-btn"
                    @click="toggleFullscreen()"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 shadow-2xs cursor-pointer whitespace-nowrap"
                    :title="isFullscreen ? 'Keluar Layar Penuh' : 'Mode Layar Penuh'"
                >
                    <svg class="h-3.5 w-3.5 text-slate-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span x-text="isFullscreen ? 'Keluar Penuh' : 'Layar Penuh'">Layar Penuh</span>
                </button>

                <button
                    type="button"
                    id="kpi-detail-download-btn"
                    @click="downloadCsv()"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 shadow-2xs cursor-pointer whitespace-nowrap"
                    title="Download CSV untuk Microsoft Excel"
                >
                    <svg class="h-3.5 w-3.5 text-slate-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4m4-5 5 5 5-5m-5 5V3" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span>Download CSV/Excel</span>
                </button>

                <button
                    type="button"
                    id="kpi-detail-print-btn"
                    onclick="window.print()"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 shadow-2xs cursor-pointer whitespace-nowrap"
                    title="Cetak atau Ekspor PDF Halaman"
                >
                    <svg class="h-3.5 w-3.5 text-slate-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M6 14h12v8H6z" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span>Cetak / PDF</span>
                </button>

                @if (\App\Http\Controllers\KpiAnalyticsController::canAccessKpiAnalytics(auth()->user()))
                    <a href="{{ route('dashboard.kpi-analytics') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-teal-200 bg-teal-50 px-2.5 py-1 text-xs font-semibold text-teal-800 transition hover:bg-teal-100 whitespace-nowrap">
                        <svg class="h-3.5 w-3.5 text-teal-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M3 3v18h18" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="m19 9-5 5-4-4-3 3" stroke-linecap="round" stroke-linejoin="round" />
                            <circle cx="19" cy="9" r="1.5" fill="currentColor" stroke="none" />
                        </svg>
                        Analitik & Bottleneck KPI
                    </a>
                @endif

                <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 whitespace-nowrap">
                    &larr; Kembali ke Ringkasan
                </a>
            </div>
        </div>

        <div class="flex items-center justify-between border-t border-slate-100 pt-1.5">
            <div id="kpi-view-switcher" class="inline-flex items-center rounded-lg border border-slate-200 bg-slate-100 p-0.5 text-xs font-medium shrink-0">
                <button
                    type="button"
                    @click="viewMode = 'split'; $dispatch('kpi-view-changed', 'split')"
                    :class="viewMode === 'split' ? 'bg-white text-primary font-bold shadow-2xs' : 'text-slate-600 hover:text-slate-900'"
                    class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 transition cursor-pointer whitespace-nowrap"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <rect x="3" y="3" width="8" height="18" rx="1"/>
                        <rect x="13" y="3" width="8" height="18" rx="1"/>
                    </svg>
                    <span>Semua Berdampingan Compact</span>
                </button>
                <button
                    type="button"
                    @click="viewMode = 'cards'; $dispatch('kpi-view-changed', 'cards')"
                    :class="viewMode === 'cards' ? 'bg-white text-primary font-bold shadow-2xs' : 'text-slate-600 hover:text-slate-900'"
                    class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 transition cursor-pointer whitespace-nowrap"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <rect x="3" y="3" width="7" height="7" rx="1"/>
                        <rect x="14" y="3" width="7" height="7" rx="1"/>
                        <rect x="3" y="14" width="7" height="7" rx="1"/>
                        <rect x="14" y="14" width="7" height="7" rx="1"/>
                    </svg>
                    <span>Fokus Kartu Visual (Grid 4 Kolom)</span>
                </button>
                <button
                    type="button"
                    @click="viewMode = 'table'; $dispatch('kpi-view-changed', 'table')"
                    :class="viewMode === 'table' ? 'bg-white text-primary font-bold shadow-2xs' : 'text-slate-600 hover:text-slate-900'"
                    class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 transition cursor-pointer whitespace-nowrap"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M3 6h18M3 12h18M3 18h18" stroke-linecap="round"/>
                    </svg>
                    <span>Fokus Tabel Matriks Padat</span>
                </button>
            </div>
            <div class="hidden sm:block text-[11px] text-slate-400">
                Mode Tampilan: <span class="font-medium text-slate-600" x-text="viewMode === 'split' ? 'Berdampingan' : (viewMode === 'cards' ? 'Kartu Visual' : 'Tabel Matriks')"></span>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-3 lg:grid-cols-12 items-start w-full">
        <div
            id="kpi-target-realisasi-container"
            x-show="viewMode === 'split' || viewMode === 'cards'"
            :class="{ 'lg:!col-span-12': viewMode === 'cards', 'hidden': viewMode === 'table' }"
            class="h-full transition-all duration-200 lg:col-span-6 w-full"
        >
            <x-widgets.target-realisasi-per-indikator
                :widget="$targetRealisasiWidget"
                :data="$targetRealisasiData"
            />
        </div>

        <div
            id="kinerja-detail"
            x-show="viewMode === 'split' || viewMode === 'table'"
            :class="{ 'lg:!col-span-12': viewMode === 'table', 'hidden': viewMode === 'cards' }"
            class="h-full transition-all duration-200 lg:col-span-6 w-full"
        >
            <x-widgets.kinerja-detail
                :widget="$kinerjaDetailWidget"
                :data="$kinerjaDetailData"
            />
        </div>
    </div>
</div>
@endsection
