@props(['widget' => [], 'data' => null])

@php
    $d = is_array($data) ? $data : [];
    $latestPeriod = $d['latest_period'] ?? null;
    $ready = (int) ($d['ready'] ?? 0);
    $archived = (int) ($d['archived'] ?? 0);
    $failed = (int) ($d['failed'] ?? 0);
    $latestRows = (int) ($d['latest_rows'] ?? 0);
    $fresh = (bool) ($d['fresh'] ?? false);
    $cols = (int) ($widget['cols'] ?? 1);
@endphp

<div class="flex h-full flex-col justify-between rounded-xl border border-slate-200/80 bg-white p-5 shadow-[0_1px_3px_0_rgba(0,0,0,0.04)] transition hover:shadow-xs">
    <div>
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2.5">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg border border-teal-100/80 bg-teal-50 text-teal-700 shadow-2xs">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M4 7c0-1.1 3.6-2 8-2s8 .9 8 2-3.6 2-8 2-8-.9-8-2Z"/>
                        <path d="M4 7v10c0 1.1 3.6 2 8 2s8-.9 8-2V7"/>
                        <path d="M4 12c0 1.1 3.6 2 8 2s8-.9 8-2"/>
                    </svg>
                </div>
                <h3 class="text-base font-bold text-slate-800">{{ $widget['label'] ?? 'Status Dataset' }}</h3>
            </div>
            @if ($latestPeriod !== null)
                <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-semibold {{ $fresh ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-amber-200 bg-amber-50 text-amber-700' }}">
                    <span class="h-1.5 w-1.5 rounded-full {{ $fresh ? 'bg-emerald-500' : 'bg-amber-500' }}" aria-hidden="true"></span>
                    {{ $fresh ? 'Baru' : 'Sudah Lama' }}
                </span>
            @endif
        </div>

        @if ($latestPeriod === null)
            <p class="mt-4 rounded-lg bg-slate-50 p-5 text-sm text-gray-600">Belum ada data KPI aktif.</p>
        @else
            <div class="mt-4 grid grid-cols-2 {{ $cols >= 2 ? 'sm:grid-cols-4' : '' }} gap-3">
                <div class="rounded-xl border border-slate-200/80 bg-white p-3.5 shadow-2xs transition hover:border-slate-300 hover:bg-slate-50/50">
                    <p class="text-xs font-medium text-slate-500">Dataset READY (Divisi)</p>
                    <p class="mt-1.5 text-2xl font-extrabold tracking-tight text-emerald-600 leading-none"><span x-data="numberTicker('{{ $ready }}')" x-text="display">{{ $ready }}</span></p>
                </div>
                <div class="rounded-xl border border-slate-200/80 bg-white p-3.5 shadow-2xs transition hover:border-slate-300 hover:bg-slate-50/50">
                    <p class="text-xs font-medium text-slate-500">Dataset ARCHIVED</p>
                    <p class="mt-1.5 text-2xl font-extrabold tracking-tight text-slate-700 leading-none"><span x-data="numberTicker('{{ $archived }}')" x-text="display">{{ $archived }}</span></p>
                </div>
                <div class="rounded-xl border border-slate-200/80 bg-white p-3.5 shadow-2xs transition hover:border-slate-300 hover:bg-slate-50/50">
                    <p class="text-xs font-medium text-slate-500">Dataset GAGAL</p>
                    <p class="mt-1.5 text-2xl font-extrabold tracking-tight text-rose-600 leading-none"><span x-data="numberTicker('{{ $failed }}')" x-text="display">{{ $failed }}</span></p>
                </div>
                @php
                    $visibleUnitsLabel = !empty(config('kpi.visible_units')) ? implode(', ', config('kpi.visible_units')) : 'Semua Unit';
                    $isNonKpi = (bool) ($widget['is_non_kpi'] ?? false);
                    if (! $isNonKpi && auth()->check()) {
                        $authUser = auth()->user();
                        if ($authUser->hasRole(\App\Models\Role::STAFF_DIVISI) && $authUser->division?->slug !== 'perencanaan-pengusahaan') {
                            $isNonKpi = true;
                        }
                    }
                @endphp
                <div class="rounded-xl border border-slate-200/80 bg-white p-3.5 shadow-2xs transition hover:border-slate-300 hover:bg-slate-50/50">
                    <p class="text-xs font-medium text-slate-500">Baris Data Aktif{{ $isNonKpi ? '' : ' ('.$visibleUnitsLabel.')' }}</p>
                    <p class="mt-1.5 text-2xl font-extrabold tracking-tight text-blue-600 leading-none"><span x-data="numberTicker('{{ $latestRows }}')" x-text="display">{{ $latestRows }}</span></p>
                </div>
            </div>
        @endif
    </div>

    @if ($latestPeriod !== null)
        <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-3 text-xs text-slate-500">
            <span>Periode data terbaru: {{ $latestPeriod }}</span>
            <a href="{{ route('datasets.index') }}" class="inline-flex items-center gap-1 font-semibold text-accent transition hover:text-primary">
                Kelola Dataset &rarr;
            </a>
        </div>
    @endif
</div>
