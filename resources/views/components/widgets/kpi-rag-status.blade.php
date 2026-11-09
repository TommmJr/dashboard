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
@endphp

<div class="h-full rounded-lg bg-white border border-slate-200 shadow-sm p-4 sm:p-5 flex flex-col justify-between">
    <div>
        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            <h3 class="text-base font-bold text-primary">{{ $widget['label'] ?? 'Status RAG Indikator' }}</h3>
            @if ($period !== null)
                <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">
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
                <div class="flex items-center justify-between rounded-lg border border-emerald-200 bg-emerald-50/70 p-2.5 text-xs text-emerald-900 transition hover:bg-emerald-50">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="h-2.5 w-2.5 rounded-full shrink-0 bg-emerald-500"></span>
                        <div class="truncate">
                            <span class="font-semibold block text-slate-900">Tercapai</span>
                            <span class="text-[11px] text-emerald-700 block">Pencapaian &ge; {{ $tercapaiMinLabel }}%</span>
                        </div>
                    </div>
                    <div class="text-right shrink-0 pl-2">
                        <span class="font-bold text-xs text-slate-900 block">{{ $tercapai }} indikator</span>
                        <span class="text-[11px] font-medium text-emerald-700 block">{{ $fmtPct($pctTercapai) }}%</span>
                    </div>
                </div>

                {{-- Tile 2: Hampir Tercapai --}}
                <div class="flex items-center justify-between rounded-lg border border-amber-200 bg-amber-50/70 p-2.5 text-xs text-amber-900 transition hover:bg-amber-50">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="h-2.5 w-2.5 rounded-full shrink-0 bg-amber-500"></span>
                        <div class="truncate">
                            <span class="font-semibold block text-slate-900">Hampir Tercapai</span>
                            <span class="text-[11px] text-amber-700 block">{{ $hampirMinLabel }}% &le; Skor &lt; {{ $tercapaiMinLabel }}%</span>
                        </div>
                    </div>
                    <div class="text-right shrink-0 pl-2">
                        <span class="font-bold text-xs text-slate-900 block">{{ $hampir }} indikator</span>
                        <span class="text-[11px] font-medium text-amber-700 block">{{ $fmtPct($pctHampir) }}%</span>
                    </div>
                </div>

                {{-- Tile 3: Perlu Peningkatan --}}
                <div class="flex items-center justify-between rounded-lg border border-rose-200 bg-rose-50/70 p-2.5 text-xs text-rose-900 transition hover:bg-rose-50">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="h-2.5 w-2.5 rounded-full shrink-0 bg-rose-500"></span>
                        <div class="truncate">
                            <span class="font-semibold block text-slate-900">Perlu Peningkatan</span>
                            <span class="text-[11px] text-rose-700 block">Skor &lt; {{ $hampirMinLabel }}%</span>
                        </div>
                    </div>
                    <div class="text-right shrink-0 pl-2">
                        <span class="font-bold text-xs text-slate-900 block">{{ $perlu }} indikator</span>
                        <span class="text-[11px] font-medium text-rose-700 block">{{ $fmtPct($pctPerlu) }}%</span>
                    </div>
                </div>
            </div>
        @endif
    </div>

    @if ($hasData)
        <div class="mt-3 pt-2.5 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-500">
            <span>Total Indikator Daun Berskor</span>
            <span class="font-semibold text-slate-700">{{ $total }} indikator</span>
        </div>
    @endif
</div>
