@props(['widget' => [], 'data' => null])

@php
    $visibleUnitsLabel = !empty(config('kpi.visible_units')) ? implode(', ', config('kpi.visible_units')) : 'Semua Unit';
    $avgPencapaian = $data['avg_pencapaian'] ?? null;
    $avgDisplay = $avgPencapaian !== null ? number_format($avgPencapaian, 1, ',', '.') . '%' : 'Tidak tercatat';
    $achievedCount = $data['achieved_count'] ?? null;
    $totalCount = $data['total_count'] ?? null;
    $tercapaiDisplay = ($achievedCount !== null && $totalCount !== null && $totalCount > 0)
        ? $achievedCount . ' / ' . $totalCount
        : 'Tidak tercatat';
    $ragThresholds = \App\Support\KpiScoreNormalizer::ragThresholds();
    $tercapaiMinLabel = rtrim(rtrim(number_format($ragThresholds['tercapai_min'], 1, ',', '.'), '0'), ',');
    $ssrAvgNko = isset($data['average_nko']) && $data['average_nko'] !== null ? number_format((float) $data['average_nko'], 2, ',', '.') : 'Tidak tercatat';
    $ssrReadyDatasets = isset($data['ready_datasets']) ? (string) $data['ready_datasets'] : 'Tidak tercatat';
    $ssrMetricCount = isset($data['metric_count']) ? (string) $data['metric_count'] : 'Tidak tercatat';
@endphp

<div class="ibs-card overflow-hidden">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 divide-y sm:divide-y-0 sm:divide-x divide-slate-100">
        {{-- Card 1: Rata-rata NKO --}}
        <div class="p-4 sm:p-5">
            <div class="flex items-center justify-between gap-2">
                <p class="ibs-metric-label uppercase tracking-wider">Rata-rata NKO</p>
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[#e6f4f6] text-[#0b6175]">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <circle cx="12" cy="8" r="6"/>
                        <path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
            </div>
            <p class="ibs-metric-value mt-3 text-2xl sm:text-3xl leading-none" x-text="kpi ? formatNumber(kpi.average_nko) : '{{ $ssrAvgNko }}'">{{ $ssrAvgNko }}</p>
            <div class="mt-3 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-500">
                <span class="inline-flex items-center gap-1.5 rounded-md bg-slate-100 px-2 py-0.5 font-semibold text-slate-700">
                    <span class="h-1.5 w-1.5 rounded-full bg-[#0b6175]" aria-hidden="true"></span>
                    Target &ge; 100
                </span>
                <span class="sr-only">Skor Utama</span>
            </div>
        </div>

        {{-- Card 2: Dataset READY --}}
        <div class="p-4 sm:p-5">
            <div class="flex items-center justify-between gap-2">
                <p class="ibs-metric-label uppercase tracking-wider">Dataset READY (Divisi)</p>
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[#e6f4f6] text-[#0b6175]">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <ellipse cx="12" cy="5" rx="9" ry="3"/>
                        <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>
                        <path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"/>
                    </svg>
                </div>
            </div>
            <p class="ibs-metric-value mt-3 text-2xl sm:text-3xl leading-none" x-text="kpi ? kpi.ready_datasets : '{{ $ssrReadyDatasets }}'">{{ $ssrReadyDatasets }}</p>
            <div class="mt-3 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-500">
                <span class="inline-flex items-center gap-1.5 rounded-md bg-slate-100 px-2 py-0.5 font-semibold text-slate-700">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                    Status Aktif
                </span>
                <span class="sr-only">Dataset Terverifikasi</span>
            </div>
        </div>

        {{-- Card 3: Baris Data Aktif --}}
        <div class="p-4 sm:p-5">
            <div class="flex items-center justify-between gap-2">
                <p class="ibs-metric-label uppercase tracking-wider">Baris Data Aktif ({{ $visibleUnitsLabel }})</p>
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[#e6f4f6] text-[#0b6175]">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M3 3v18h18" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="m7 16 4-6 4 2 5-6" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
            </div>
            <p class="ibs-metric-value mt-3 text-2xl sm:text-3xl leading-none" x-text="kpi ? kpi.metric_count : '{{ $ssrMetricCount }}'">{{ $ssrMetricCount }}</p>
            <div class="mt-3 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-500">
                <span class="inline-flex items-center gap-1.5 rounded-md bg-slate-100 px-2 py-0.5 font-semibold text-slate-700">
                    <span class="h-1.5 w-1.5 rounded-full bg-[#16a3b8]" aria-hidden="true"></span>
                    Unit {{ $visibleUnitsLabel }}
                </span>
                <span class="sr-only">Parameter Pantau</span>
            </div>
        </div>

        {{-- Card 4: Rata-rata Pencapaian Indikator --}}
        <div class="p-4 sm:p-5">
            <div class="flex items-center justify-between gap-2">
                <p class="ibs-metric-label uppercase tracking-wider">Rata-rata Pencapaian Indikator</p>
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[#e6f4f6] text-[#0b6175]">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M18 20V10M12 20V4M6 20v-6" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
            </div>
            <p class="ibs-metric-value mt-3 text-2xl sm:text-3xl leading-none">{{ $avgDisplay }}</p>
            <div class="mt-3 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-500">
                <span class="inline-flex items-center gap-1.5 rounded-md bg-slate-100 px-2 py-0.5 font-semibold text-slate-700">
                    <span class="h-1.5 w-1.5 rounded-full bg-[#0b6175]" aria-hidden="true"></span>
                    Maks 110%
                </span>
                <span class="sr-only">Dihitung (Maks 110%) Beda dari NKO workbook</span>
            </div>
        </div>

        {{-- Card 5: Indikator Tercapai --}}
        <div class="p-4 sm:p-5">
            <div class="flex items-center justify-between gap-2">
                <p class="ibs-metric-label uppercase tracking-wider">Indikator Tercapai</p>
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[#e6f4f6] text-[#0b6175]">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="m9 12 2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
            </div>
            <p class="ibs-metric-value mt-3 text-2xl sm:text-3xl leading-none">{{ $tercapaiDisplay }}</p>
            <div class="mt-3 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-500">
                <span class="inline-flex items-center gap-1.5 rounded-md bg-slate-100 px-2 py-0.5 font-semibold text-slate-700 tabular-nums">
                    <span class="h-1.5 w-1.5 rounded-full bg-amber-500" aria-hidden="true"></span>
                    Skor &ge; {{ $tercapaiMinLabel }}%
                </span>
                <span class="sr-only">Daun berskor</span>
            </div>
        </div>
    </div>
</div>
