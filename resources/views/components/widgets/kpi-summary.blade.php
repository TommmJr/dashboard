@props(['widget' => [], 'data' => null])

<div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
    {{-- Card 1: Rata-rata NKO --}}
    <div class="relative overflow-hidden rounded-xl border border-slate-200 bg-white p-5 shadow-xs transition hover:shadow-sm">
        <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-teal-500 to-teal-400"></div>
        <div class="flex items-center justify-between">
            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Rata-rata NKO</p>
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-teal-50 text-teal-700">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
        </div>
        <p class="mt-3 text-3xl font-extrabold leading-none tracking-tight text-primary" x-text="kpi ? formatNumber(kpi.average_nko) : '—'"></p>
        <div class="mt-2.5 flex items-center gap-1.5 text-xs text-slate-500">
            <span class="inline-flex items-center rounded-full border border-teal-200 bg-teal-50 px-2 py-0.5 font-semibold text-teal-800">
                Skor Utama
            </span>
            <span>Target &ge; 100</span>
        </div>
    </div>

    {{-- Card 2: Dataset READY --}}
    <div class="relative overflow-hidden rounded-xl border border-slate-200 bg-white p-5 shadow-xs transition hover:shadow-sm">
        <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-emerald-500 to-emerald-400"></div>
        <div class="flex items-center justify-between">
            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Dataset READY (Divisi)</p>
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-700">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
        </div>
        <p class="mt-3 text-3xl font-extrabold leading-none tracking-tight text-primary" x-text="kpi ? kpi.ready_datasets : '—'"></p>
        <div class="mt-2.5 flex items-center gap-1.5 text-xs text-slate-500">
            <span class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 font-semibold text-emerald-800">
                Dataset Terverifikasi
            </span>
            <span>Status Aktif</span>
        </div>
    </div>

    {{-- Card 3: Baris Data Aktif --}}
    <div class="relative overflow-hidden rounded-xl border border-slate-200 bg-white p-5 shadow-xs transition hover:shadow-sm">
        <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-blue-500 to-blue-400"></div>
        <div class="flex items-center justify-between">
            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Baris Data Aktif (TJBT)</p>
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-700">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
        </div>
        <p class="mt-3 text-3xl font-extrabold leading-none tracking-tight text-primary" x-text="kpi ? kpi.metric_count : '—'"></p>
        <div class="mt-2.5 flex items-center gap-1.5 text-xs text-slate-500">
            <span class="inline-flex items-center rounded-full border border-blue-200 bg-blue-50 px-2 py-0.5 font-semibold text-blue-800">
                Parameter Pantau
            </span>
            <span>Unit TJBT</span>
        </div>
    </div>
</div>
