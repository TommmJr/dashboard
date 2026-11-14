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

<div class="ibs-card h-full p-5 flex flex-col justify-between">
    <div>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="ibs-section-title text-base font-bold text-slate-900">{{ $widget['label'] ?? 'Status RAG Indikator' }}</h3>
            @if ($period !== null)
                <span class="inline-flex items-center rounded-full bg-[#e6f4f6] px-2.5 py-0.5 text-[11px] font-semibold text-[#0b6175]">
                    Periode {{ $period }}
                </span>
            @endif
        </div>

        @if (! $hasData)
            <p class="mt-4 rounded-xl border border-slate-200/70 bg-slate-50 p-4 text-xs text-slate-500">
                Belum ada data indikator pada periode ini.
            </p>
        @else
            <div class="mt-4 space-y-2.5">
                {{-- Tile 1: Tercapai --}}
                <div class="rounded-xl border border-slate-200/80 bg-white p-3 text-xs transition hover:border-slate-300">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="h-1.5 w-1.5 rounded-full shrink-0 bg-emerald-500" aria-hidden="true"></span>
                            <div class="truncate">
                                <span class="font-semibold block text-slate-800">Tercapai</span>
                                <span class="text-[11px] text-slate-500 block">Pencapaian &ge; {{ $tercapaiMinLabel }}%</span>
                            </div>
                        </div>
                        <div class="text-right shrink-0 pl-2 tabular-nums">
                            <span class="font-bold text-xs text-slate-900 block">{{ $tercapai }} indikator</span>
                            <span class="text-[11px] font-semibold text-emerald-600 block">{{ $fmtPct($pctTercapai) }}%</span>
                        </div>
                    </div>
                    <div class="mt-2 h-1 w-full rounded-full bg-slate-100 overflow-hidden">
                        <div class="h-full rounded-full bg-emerald-500" style="width: {{ min(100, $pctTercapai) }}%"></div>
                    </div>
                </div>

                {{-- Tile 2: Hampir Tercapai --}}
                <div class="rounded-xl border border-slate-200/80 bg-white p-3 text-xs transition hover:border-slate-300">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="h-1.5 w-1.5 rounded-full shrink-0 bg-amber-500" aria-hidden="true"></span>
                            <div class="truncate">
                                <span class="font-semibold block text-slate-800">Hampir Tercapai</span>
                                <span class="text-[11px] text-slate-500 block">{{ $hampirMinLabel }}% &le; Skor &lt; {{ $tercapaiMinLabel }}%</span>
                            </div>
                        </div>
                        <div class="text-right shrink-0 pl-2 tabular-nums">
                            <span class="font-bold text-xs text-slate-900 block">{{ $hampir }} indikator</span>
                            <span class="text-[11px] font-semibold text-amber-600 block">{{ $fmtPct($pctHampir) }}%</span>
                        </div>
                    </div>
                    <div class="mt-2 h-1 w-full rounded-full bg-slate-100 overflow-hidden">
                        <div class="h-full rounded-full bg-amber-500" style="width: {{ min(100, $pctHampir) }}%"></div>
                    </div>
                </div>

                {{-- Tile 3: Perlu Peningkatan --}}
                <div class="rounded-xl border border-slate-200/80 bg-white p-3 text-xs transition hover:border-slate-300">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="h-1.5 w-1.5 rounded-full shrink-0 bg-rose-500" aria-hidden="true"></span>
                            <div class="truncate">
                                <span class="font-semibold block text-slate-800">Perlu Peningkatan</span>
                                <span class="text-[11px] text-slate-500 block">Skor &lt; {{ $hampirMinLabel }}%</span>
                            </div>
                        </div>
                        <div class="text-right shrink-0 pl-2 tabular-nums">
                            <span class="font-bold text-xs text-slate-900 block">{{ $perlu }} indikator</span>
                            <span class="text-[11px] font-semibold text-rose-600 block">{{ $fmtPct($pctPerlu) }}%</span>
                        </div>
                    </div>
                    <div class="mt-2 h-1 w-full rounded-full bg-slate-100 overflow-hidden">
                        <div class="h-full rounded-full bg-rose-500" style="width: {{ min(100, $pctPerlu) }}%"></div>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <div class="mt-4 space-y-2.5 border-t border-slate-100 pt-3">
        @if ($hasData)
            <div class="flex items-center justify-between text-[11px] text-slate-500 tabular-nums">
                <span>Total Indikator Daun Berskor</span>
                <span class="font-semibold text-slate-700">{{ $total }} indikator</span>
            </div>
        @endif

        @if ($canAccessAnalytics)
            <div>
                <a
                    href="{{ route('dashboard.kpi-analytics') }}"
                    class="flex w-full items-center justify-center gap-1.5 rounded-xl border border-[#0b6175]/20 bg-white px-3 py-2 text-xs font-semibold text-[#0b6175] transition hover:bg-[#e6f4f6]/60 shadow-2xs"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M3 3v18h18" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="m19 9-5 5-4-4-3 3" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    Buka Analitik KPI &rarr;
                </a>
            </div>
        @endif
    </div>
</div>
