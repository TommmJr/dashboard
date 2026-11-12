@props(['widget' => [], 'data' => null])

@php
    $period = $data['period'] ?? null;
    $counts = $data['counts'] ?? ['tercapai' => 0, 'hampir' => 0, 'perlu' => 0, 'total' => 0];
    $total = (int) ($counts['total'] ?? 0);
    $tercapai = (int) ($counts['tercapai'] ?? 0);
    $hampir = (int) ($counts['hampir'] ?? 0);
    $perlu = (int) ($counts['perlu'] ?? 0);
    $hasData = ($data['has_data'] ?? false) && $total > 0;

    $pctTercapai = $total > 0 ? round(($tercapai / $total) * 100, 1) : 0;
    $pctHampir = $total > 0 ? round(($hampir / $total) * 100, 1) : 0;
    $pctPerlu = $total > 0 ? round(($perlu / $total) * 100, 1) : 0;

    $fmtPct = fn (float $val): string => rtrim(rtrim(number_format($val, 1, ',', '.'), '0'), ',');
    $ragThresholds = \App\Support\KpiScoreNormalizer::ragThresholds();
    $tercapaiMinLabel = $fmtPct($ragThresholds['tercapai_min']);
    $hampirMinLabel = $fmtPct($ragThresholds['hampir_min']);
    $canAccessAnalytics = \App\Http\Controllers\KpiAnalyticsController::canAccessKpiAnalytics(auth()->user());
@endphp

<div class="h-full rounded-xl bg-white border border-slate-200/80 shadow-xs p-4 sm:p-5 flex flex-col justify-between transition-all duration-200 ease-out hover:shadow-md">
    <div>
        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            <h3 class="text-base font-bold text-primary">{{ $widget['label'] ?? 'Status RAG Indikator' }}</h3>
            @if ($period !== null)
                <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold text-slate-600">
                    Periode {{ $period }}
                </span>
            @endif
        </div>

        @if (! $hasData)
            <p class="mt-4 rounded-lg bg-slate-50 p-4 text-xs text-slate-500">
                Belum ada data indikator pada periode ini.
            </p>
        @else
            <div class="mt-3.5 space-y-2">
                {{-- Tile 1: Tercapai --}}
                <div class="flex items-center justify-between rounded-lg border border-slate-200/80 bg-white p-2.5 text-xs transition hover:border-emerald-300 hover:bg-emerald-50/30">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="h-2.5 w-2.5 rounded-full shrink-0 bg-emerald-500"></span>
                        <div class="truncate">
                            <span class="font-semibold block text-slate-800">Tercapai</span>
                            <span class="text-[11px] text-slate-500 block">Pencapaian &ge; {{ $tercapaiMinLabel }}%</span>
                        </div>
                    </div>
                    <div class="text-right shrink-0 pl-2">
                        <span class="font-bold text-xs text-slate-800 block">{{ $tercapai }} indikator</span>
                        <span class="text-[11px] font-semibold text-emerald-600 block">{{ $fmtPct($pctTercapai) }}%</span>
                    </div>
                </div>

                {{-- Tile 2: Hampir Tercapai --}}
                <div class="flex items-center justify-between rounded-lg border border-slate-200/80 bg-white p-2.5 text-xs transition hover:border-amber-300 hover:bg-amber-50/30">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="h-2.5 w-2.5 rounded-full shrink-0 bg-amber-500"></span>
                        <div class="truncate">
                            <span class="font-semibold block text-slate-800">Hampir Tercapai</span>
                            <span class="text-[11px] text-slate-500 block">{{ $hampirMinLabel }}% &le; Skor &lt; {{ $tercapaiMinLabel }}%</span>
                        </div>
                    </div>
                    <div class="text-right shrink-0 pl-2">
                        <span class="font-bold text-xs text-slate-800 block">{{ $hampir }} indikator</span>
                        <span class="text-[11px] font-semibold text-amber-600 block">{{ $fmtPct($pctHampir) }}%</span>
                    </div>
                </div>

                {{-- Tile 3: Perlu Peningkatan --}}
                <div class="flex items-center justify-between rounded-lg border border-slate-200/80 bg-white p-2.5 text-xs transition hover:border-rose-300 hover:bg-rose-50/30">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="h-2.5 w-2.5 rounded-full shrink-0 bg-rose-500"></span>
                        <div class="truncate">
                            <span class="font-semibold block text-slate-800">Perlu Peningkatan</span>
                            <span class="text-[11px] text-slate-500 block">Skor &lt; {{ $hampirMinLabel }}%</span>
                        </div>
                    </div>
                    <div class="text-right shrink-0 pl-2">
                        <span class="font-bold text-xs text-slate-800 block">{{ $perlu }} indikator</span>
                        <span class="text-[11px] font-semibold text-rose-600 block">{{ $fmtPct($pctPerlu) }}%</span>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <div class="mt-3.5 space-y-2 border-t border-slate-100 pt-3">
        @if ($hasData)
            <div class="flex items-center justify-between text-[11px] text-slate-500">
                <span>Total Indikator Daun Berskor</span>
                <span class="font-semibold text-slate-700">{{ $total }} indikator</span>
            </div>
        @endif

        @if ($canAccessAnalytics)
            <div class="pt-1">
                <a
                    href="{{ route('dashboard.kpi-analytics') }}"
                    class="flex w-full items-center justify-center gap-1.5 rounded-lg border border-teal-200 bg-teal-50/60 px-3 py-1.5 text-xs font-semibold text-teal-800 transition hover:bg-teal-100/70"
                >
                    <svg class="h-3.5 w-3.5 text-teal-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M3 3v18h18" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="m19 9-5 5-4-4-3 3" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    Buka Analitik KPI &rarr;
                </a>
            </div>
        @endif
    </div>
</div>
