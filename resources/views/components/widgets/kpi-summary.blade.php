@props(['widget' => [], 'data' => null, 'executiveMeta' => null])

@php
    $avgPencapaian = $data['avg_pencapaian'] ?? null;
    $avgDisplay = $avgPencapaian !== null ? number_format($avgPencapaian, 1, ',', '.') . '%' : 'Tidak tercatat';
    $achievedCount = $data['achieved_count'] ?? null;
    $totalCount = $data['total_count'] ?? null;
    $hasLeafCounts = $achievedCount !== null && $totalCount !== null && $totalCount > 0;
    $tercapaiDisplay = $hasLeafCounts
        ? $achievedCount . ' / ' . $totalCount
        : 'Tidak tercatat';
    $attentionCount = $hasLeafCounts
        ? max(0, (int) $totalCount - (int) $achievedCount)
        : null;
    $attentionDisplay = $attentionCount !== null
        ? $attentionCount . ' / ' . $totalCount
        : 'Tidak tercatat';

    $rawAvgNko = $data['average_nko'] ?? ($executiveMeta['average_nko'] ?? null);
    $ssrAvgNko = $rawAvgNko !== null ? number_format((float) $rawAvgNko, 2, ',', '.') : 'Tidak tercatat';
    $nkoOnTarget = $rawAvgNko !== null && (float) $rawAvgNko >= 100.0;
@endphp

<div class="ibs-card overflow-hidden">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 divide-y sm:divide-y-0 sm:divide-x divide-slate-100">
        {{-- Card 1: NKO (Nilai Kinerja Organisasi) --}}
        <div class="p-4 sm:p-5">
            <p class="ibs-metric-label uppercase tracking-wider">NKO (Nilai Kinerja Organisasi)</p>
            <p class="ibs-metric-value mt-2.5 text-2xl sm:text-3xl leading-none tabular-nums" x-text="(kpi && periodLabels.length > 0) ? formatNumber(kpi.average_nko) : '{{ $ssrAvgNko }}'">{{ $ssrAvgNko }}</p>
            <div class="mt-2.5">
                <span class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap {{ $nkoOnTarget ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-700' }}">
                    @if ($nkoOnTarget)
                        <svg class="h-3.5 w-3.5 shrink-0 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="m9 12 2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    @else
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-[#0b6175]" aria-hidden="true"></span>
                    @endif
                    Target &ge; 100
                </span>
            </div>
        </div>

        {{-- Card 2: Rata-rata Capaian --}}
        <div class="p-4 sm:p-5">
            <p class="ibs-metric-label uppercase tracking-wider">Rata-rata Capaian</p>
            <p class="ibs-metric-value mt-2.5 text-2xl sm:text-3xl leading-none tabular-nums">{{ $avgDisplay }}</p>
            <div class="mt-2.5">
                <span class="inline-flex items-center gap-1.5 rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-700 whitespace-nowrap">
                    <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-[#0b6175]" aria-hidden="true"></span>
                    Maks 110%
                </span>
            </div>
        </div>

        {{-- Card 3: Memenuhi Target --}}
        <div class="p-4 sm:p-5">
            <p class="ibs-metric-label uppercase tracking-wider">Memenuhi Target</p>
            <p class="ibs-metric-value mt-2.5 text-2xl sm:text-3xl leading-none tabular-nums">{{ $tercapaiDisplay }}</p>
            <div class="mt-2.5">
                @if ($hasLeafCounts)
                    <span class="inline-flex items-center gap-1.5 rounded-md bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-800 whitespace-nowrap">
                        <svg class="h-3.5 w-3.5 shrink-0 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="m5 13 4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        Tercapai
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-700 whitespace-nowrap">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-slate-400" aria-hidden="true"></span>
                        Belum ada data
                    </span>
                @endif
            </div>
        </div>

        {{-- Card 4: Perlu Perhatian --}}
        <div class="p-4 sm:p-5">
            <p class="ibs-metric-label uppercase tracking-wider">Perlu Perhatian</p>
            <p class="ibs-metric-value mt-2.5 text-2xl sm:text-3xl leading-none tabular-nums {{ $attentionCount !== null && $attentionCount > 0 ? 'text-amber-700' : '' }}">{{ $attentionDisplay }}</p>
            <div class="mt-2.5">
                @if ($attentionCount === null)
                    <span class="inline-flex items-center gap-1.5 rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-700 whitespace-nowrap">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-slate-400" aria-hidden="true"></span>
                        Belum ada data
                    </span>
                @elseif ($attentionCount === 0)
                    <span class="inline-flex items-center gap-1.5 rounded-md bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-800 whitespace-nowrap tabular-nums">
                        <svg class="h-3.5 w-3.5 shrink-0 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="m5 13 4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        0 Indikator
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 rounded-md bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-800 whitespace-nowrap tabular-nums">
                        <svg class="h-3.5 w-3.5 shrink-0 text-amber-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M12 9v4m0 4h.01" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        {{ $attentionCount }} Indikator
                    </span>
                @endif
            </div>
        </div>
    </div>
</div>
