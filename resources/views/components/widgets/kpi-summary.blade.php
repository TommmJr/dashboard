@props(['widget' => [], 'data' => null])

@php
    $visibleUnitsLabel = !empty(config('kpi.visible_units')) ? implode(', ', config('kpi.visible_units')) : 'Semua Unit';
    $avgPencapaian = $data['avg_pencapaian'] ?? null;
    $avgDisplay = $avgPencapaian !== null ? number_format($avgPencapaian, 1, ',', '.') . '%' : '—';
    $achievedCount = $data['achieved_count'] ?? null;
    $totalCount = $data['total_count'] ?? null;
    $tercapaiDisplay = ($achievedCount !== null && $totalCount !== null && $totalCount > 0)
        ? $achievedCount . ' / ' . $totalCount
        : '—';
    $ragThresholds = \App\Support\KpiScoreNormalizer::ragThresholds();
    $tercapaiMinLabel = rtrim(rtrim(number_format($ragThresholds['tercapai_min'], 1, ',', '.'), '0'), ',');
@endphp

<div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
    {{-- Card 1: Rata-rata NKO --}}
    <div class="relative overflow-hidden rounded-xl border border-slate-200/80 bg-white p-4 sm:p-5 shadow-[0_1px_3px_0_rgba(0,0,0,0.04)] transition hover:shadow-xs">
        <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-teal-500 to-teal-400"></div>
        <div class="flex items-center justify-between">
            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Rata-rata NKO</p>
            <div class="flex h-8 w-8 items-center justify-center rounded-lg border border-teal-100/80 bg-teal-50 text-teal-700 shadow-2xs">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="12" cy="8" r="6"/>
                    <path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
        </div>
        <p class="mt-3 text-2xl sm:text-3xl font-extrabold leading-none tracking-tight text-slate-800" x-text="kpi ? formatNumber(kpi.average_nko) : '—'"></p>
        <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
            <span class="inline-flex items-center rounded-md border border-teal-200/80 bg-teal-50 px-2 py-0.5 text-[11px] font-semibold text-teal-800">
                Skor Utama
            </span>
            <span class="text-[11px]">Target &ge; 100</span>
        </div>
    </div>

    {{-- Card 2: Dataset READY --}}
    <div class="relative overflow-hidden rounded-xl border border-slate-200/80 bg-white p-4 sm:p-5 shadow-[0_1px_3px_0_rgba(0,0,0,0.04)] transition hover:shadow-xs">
        <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-emerald-500 to-emerald-400"></div>
        <div class="flex items-center justify-between">
            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Dataset READY (Divisi)</p>
            <div class="flex h-8 w-8 items-center justify-center rounded-lg border border-emerald-100/80 bg-emerald-50 text-emerald-700 shadow-2xs">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <ellipse cx="12" cy="5" rx="9" ry="3"/>
                    <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>
                    <path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"/>
                </svg>
            </div>
        </div>
        <p class="mt-3 text-2xl sm:text-3xl font-extrabold leading-none tracking-tight text-slate-800" x-text="kpi ? kpi.ready_datasets : '—'"></p>
        <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
            <span class="inline-flex items-center rounded-md border border-emerald-200/80 bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-800">
                Dataset Terverifikasi
            </span>
            <span class="text-[11px]">Status Aktif</span>
        </div>
    </div>

    {{-- Card 3: Baris Data Aktif --}}
    <div class="relative overflow-hidden rounded-xl border border-slate-200/80 bg-white p-4 sm:p-5 shadow-[0_1px_3px_0_rgba(0,0,0,0.04)] transition hover:shadow-xs">
        <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-blue-500 to-blue-400"></div>
        <div class="flex items-center justify-between">
            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Baris Data Aktif ({{ $visibleUnitsLabel }})</p>
            <div class="flex h-8 w-8 items-center justify-center rounded-lg border border-blue-100/80 bg-blue-50 text-blue-700 shadow-2xs">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M3 3v18h18" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="m7 16 4-6 4 2 5-6" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
        </div>
        <p class="mt-3 text-2xl sm:text-3xl font-extrabold leading-none tracking-tight text-slate-800" x-text="kpi ? kpi.metric_count : '—'"></p>
        <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
            <span class="inline-flex items-center rounded-md border border-blue-200/80 bg-blue-50 px-2 py-0.5 text-[11px] font-semibold text-blue-800">
                Parameter Pantau
            </span>
            <span class="text-[11px]">Unit {{ $visibleUnitsLabel }}</span>
        </div>
    </div>

    {{-- Card 4: Rata-rata Pencapaian Indikator --}}
    <div class="relative overflow-hidden rounded-xl border border-slate-200/80 bg-white p-4 sm:p-5 shadow-[0_1px_3px_0_rgba(0,0,0,0.04)] transition hover:shadow-xs">
        <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-indigo-500 to-indigo-400"></div>
        <div class="flex items-center justify-between">
            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Rata-rata Pencapaian Indikator</p>
            <div class="flex h-8 w-8 items-center justify-center rounded-lg border border-indigo-100/80 bg-indigo-50 text-indigo-700 shadow-2xs">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M18 20V10M12 20V4M6 20v-6" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
        </div>
        <p class="mt-3 text-2xl sm:text-3xl font-extrabold leading-none tracking-tight text-slate-800">{{ $avgDisplay }}</p>
        <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
            <span class="inline-flex items-center rounded-md border border-indigo-200/80 bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold text-indigo-800">
                Dihitung (Maks 110%)
            </span>
            <span class="text-[11px]">Beda dari NKO workbook</span>
        </div>
    </div>

    {{-- Card 5: Indikator Tercapai --}}
    <div class="relative overflow-hidden rounded-xl border border-slate-200/80 bg-white p-4 sm:p-5 shadow-[0_1px_3px_0_rgba(0,0,0,0.04)] transition hover:shadow-xs">
        <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-amber-500 to-amber-400"></div>
        <div class="flex items-center justify-between">
            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Indikator Tercapai</p>
            <div class="flex h-8 w-8 items-center justify-center rounded-lg border border-amber-100/80 bg-amber-50 text-amber-700 shadow-2xs">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="m9 12 2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
        </div>
        <p class="mt-3 text-2xl sm:text-3xl font-extrabold leading-none tracking-tight text-slate-800">{{ $tercapaiDisplay }}</p>
        <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
            <span class="inline-flex items-center rounded-md border border-amber-200/80 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-800">
                Skor &ge; {{ $tercapaiMinLabel }}%
            </span>
            <span class="text-[11px]">Daun berskor</span>
        </div>
    </div>
</div>
