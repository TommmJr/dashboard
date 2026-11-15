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

    $formattedPeriod = 'Tidak tercatat';
    if ($latestPeriod !== null) {
        try {
            $months = [
                1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
                7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
            ];
            $dt = \Carbon\Carbon::parse($latestPeriod);
            $formattedPeriod = $dt->day.' '.($months[$dt->month] ?? $dt->format('M')).' '.$dt->year;
        } catch (\Throwable $e) {
            $formattedPeriod = 'Tidak tercatat';
        }
    }
@endphp

<div class="ibs-card flex h-full flex-col justify-between p-5 card-interactive">
    <div>
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-2.5">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-[#e6f4f6] text-[#0b6175]">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M4 7c0-1.1 3.6-2 8-2s8 .9 8 2-3.6 2-8 2-8-.9-8-2Z"/>
                        <path d="M4 7v10c0 1.1 3.6 2 8 2s8-.9 8-2V7"/>
                        <path d="M4 12c0 1.1 3.6 2 8 2s8-.9 8-2"/>
                    </svg>
                </div>
                <h3 class="ibs-section-title">{{ $widget['label'] ?? 'Status Dataset' }}</h3>
            </div>
            @if ($latestPeriod !== null)
                <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-semibold {{ $fresh ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-amber-200 bg-amber-50 text-amber-700' }}">
                    <span class="h-1.5 w-1.5 rounded-full {{ $fresh ? 'bg-emerald-500' : 'bg-amber-500' }}" aria-hidden="true"></span>
                    {{ $fresh ? 'Baru' : 'Sudah Lama' }}
                </span>
            @endif
        </div>

        @if ($latestPeriod === null)
            <p class="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">Belum ada data KPI aktif.</p>
        @else
            <div class="mt-4 grid grid-cols-2 {{ $cols >= 2 ? 'sm:grid-cols-4' : '' }} gap-3">
                <div class="rounded-xl border border-slate-100 bg-slate-50/50 p-3.5 transition hover:border-slate-200 hover:bg-white">
                    <p class="ibs-metric-label">Dataset {{ \App\Enums\DatasetStatus::READY->label() }} (Divisi)</p>
                    <p class="ibs-metric-value mt-1.5 text-2xl leading-none text-emerald-700"><span x-data="numberTicker('{{ $ready }}')" x-text="display">{{ $ready }}</span></p>
                </div>
                <div class="rounded-xl border border-slate-100 bg-slate-50/50 p-3.5 transition hover:border-slate-200 hover:bg-white">
                    <p class="ibs-metric-label">Dataset {{ \App\Enums\DatasetStatus::ARCHIVED->label() }}</p>
                    <p class="ibs-metric-value mt-1.5 text-2xl leading-none text-slate-700"><span x-data="numberTicker('{{ $archived }}')" x-text="display">{{ $archived }}</span></p>
                </div>
                <div class="rounded-xl border border-slate-100 bg-slate-50/50 p-3.5 transition hover:border-slate-200 hover:bg-white">
                    <p class="ibs-metric-label">Dataset {{ \App\Enums\DatasetStatus::FAILED->label() }}</p>
                    <p class="ibs-metric-value mt-1.5 text-2xl leading-none text-rose-600"><span x-data="numberTicker('{{ $failed }}')" x-text="display">{{ $failed }}</span></p>
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
                <div class="rounded-xl border border-slate-100 bg-slate-50/50 p-3.5 transition hover:border-slate-200 hover:bg-white">
                    <p class="ibs-metric-label">Baris Data Aktif{{ $isNonKpi ? '' : ' ('.$visibleUnitsLabel.')' }}</p>
                    <p class="ibs-metric-value mt-1.5 text-2xl leading-none text-[#0b6175]"><span x-data="numberTicker('{{ $latestRows }}')" x-text="display">{{ $latestRows }}</span></p>
                </div>
            </div>
        @endif
    </div>

    @if ($latestPeriod !== null)
        <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-3 text-xs text-slate-500">
            <span>Periode {{ $formattedPeriod }}</span>
            <span class="sr-only">Periode data terbaru: {{ $latestPeriod }}</span>
            <a href="{{ route('datasets.index') }}" class="inline-flex items-center gap-1 font-semibold text-[#0b6175] transition hover:text-[#16a3b8]">
                Kelola Dataset
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M5 12h14m-6-6 6 6-6 6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </a>
        </div>
    @endif
</div>
