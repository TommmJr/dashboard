@props(['widget' => [], 'data' => null, 'executiveMeta' => null])

@php
    $avgPencapaian = $data['avg_pencapaian'] ?? null;
    $avgDisplay = $avgPencapaian !== null ? number_format($avgPencapaian, 1, ',', '.') . '%' : 'Tidak tercatat';
    $avgBarWidth = $avgPencapaian !== null ? max(0, min(100, round(((float) $avgPencapaian / 110) * 100, 1))) : 0;

    $achievedCount = $data['achieved_count'] ?? null;
    $totalCount = $data['total_count'] ?? null;
    $hasLeafCounts = $achievedCount !== null && $totalCount !== null && $totalCount > 0;
    $tercapaiDisplay = $hasLeafCounts ? $achievedCount . ' / ' . $totalCount : 'Tidak tercatat';
    $achievedRatio = $hasLeafCounts ? round(((int) $achievedCount / (int) $totalCount) * 100) : 0;

    $attentionCount = $hasLeafCounts ? max(0, (int) $totalCount - (int) $achievedCount) : null;
    $attentionDisplay = $attentionCount !== null ? $attentionCount . ' / ' . $totalCount : 'Tidak tercatat';

    $rawAvgNko = $data['average_nko'] ?? ($executiveMeta['average_nko'] ?? null);
    $ssrAvgNko = $rawAvgNko !== null ? number_format((float) $rawAvgNko, 2, ',', '.') : 'Tidak tercatat';
    $nkoOnTarget = $rawAvgNko !== null && (float) $rawAvgNko >= 100.0;
    $nkoRingPct = $rawAvgNko !== null ? max(0, min(100, round(((float) $rawAvgNko / 110.0) * 100, 1))) : 0;
    $ringCircumference = 125.66;
    $ringDashOffset = round($ringCircumference * (1 - ($nkoRingPct / 100)), 2);

    $canAccessDetail = \App\Http\Controllers\DashboardController::canAccessKpiDetail(auth()->user());
@endphp

<div class="ibs-card overflow-hidden">
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-[1.35fr_1fr_1fr_1fr] divide-y sm:divide-y-0 sm:divide-x divide-slate-100">
        {{-- Card 1 (Focal Point): NKO Organisasi + Radial Target Ring --}}
        <div class="flex items-center justify-between gap-4 bg-[#f8fbfc] p-5">
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.06em] text-[#0b6175]">
                    NKO (Nilai Kinerja Organisasi)
                </p>
                <div class="mt-2 flex flex-wrap items-baseline gap-2.5">
                    <p
                        class="text-3xl sm:text-4xl font-bold tracking-tight leading-none text-slate-900 tabular-nums"
                        x-text="(kpi && periodLabels.length > 0) ? formatNumber(kpi.average_nko) : '{{ $ssrAvgNko }}'"
                    >{{ $ssrAvgNko }}</p>

                    <template x-if="trendDelta">
                        <span
                            class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold tabular-nums whitespace-nowrap"
                            :class="trendDelta.positive ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800'"
                            x-text="trendDelta.label"
                        ></span>
                    </template>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-[11px] font-semibold whitespace-nowrap {{ $nkoOnTarget ? 'border-emerald-200/80 bg-emerald-50 text-emerald-800' : 'border-slate-200 bg-white text-slate-700' }}">
                        @if ($nkoOnTarget)
                            <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-emerald-500" aria-hidden="true"></span>
                        @else
                            <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-[#0b6175]" aria-hidden="true"></span>
                        @endif
                        Target &ge; 100
                    </span>
                </div>
            </div>

            {{-- Compact Radial Progress Arc --}}
            <div class="relative hidden sm:flex h-14 w-14 shrink-0 items-center justify-center" aria-hidden="true">
                <svg class="h-14 w-14 -rotate-90" viewBox="0 0 48 48">
                    <circle cx="24" cy="24" r="20" fill="none" stroke="#e2e8f0" stroke-width="4" />
                    <circle
                        cx="24"
                        cy="24"
                        r="20"
                        fill="none"
                        stroke="{{ $nkoOnTarget ? '#10b981' : '#0b6175' }}"
                        stroke-width="4"
                        stroke-linecap="round"
                        stroke-dasharray="{{ $ringCircumference }}"
                        stroke-dashoffset="{{ $ringDashOffset }}"
                    />
                </svg>
                <span class="absolute text-[10px] font-bold text-slate-700 tabular-nums">{{ round($nkoRingPct) }}%</span>
            </div>
        </div>

        {{-- Card 2: Rata-rata Capaian --}}
        <div class="flex flex-col justify-between p-5">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.06em] text-slate-500">Rata-rata Capaian</p>
                <p class="mt-2 text-2xl sm:text-[28px] font-bold tracking-tight leading-none text-slate-900 tabular-nums" x-data="numberTicker('{{ $avgDisplay }}')" x-text="display">{{ $avgDisplay }}</p>
            </div>
            <div class="mt-3.5 space-y-1.5">
                <div class="relative h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full rounded-full bg-[#16a3b8] animate-bar-reveal" style="width: {{ $avgBarWidth }}%"></div>
                    <span class="absolute top-0 bottom-0 w-px bg-slate-400" style="left: 90.9%" aria-hidden="true"></span>
                </div>
                <div class="flex items-center justify-between text-[11px] text-slate-500 tabular-nums">
                    <span>Skala NKO</span>
                    <span class="font-semibold text-slate-700">Maks 110%</span>
                </div>
            </div>
        </div>

        {{-- Card 3: Memenuhi Target (Interactive deep link when permitted) --}}
        @if ($canAccessDetail)
            <a href="{{ route('dashboard.kpi-detail', ['rag' => 'tercapai']) }}" class="group flex flex-col justify-between p-5 transition-colors hover:bg-slate-50/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[#16a3b8]">
        @else
            <div class="flex flex-col justify-between p-5">
        @endif
                <div>
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.06em] text-slate-500">Memenuhi Target</p>
                        @if ($canAccessDetail)
                            <svg class="h-3.5 w-3.5 text-slate-300 transition-transform group-hover:translate-x-0.5 group-hover:text-[#0b6175]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M5 12h14m-6-6 6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        @endif
                    </div>
                    <p class="mt-2 text-2xl sm:text-[28px] font-bold tracking-tight leading-none text-slate-900 tabular-nums">{{ $tercapaiDisplay }}</p>
                </div>
                <div class="mt-3.5 flex items-center justify-between gap-2">
                    @if ($hasLeafCounts)
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-200/80 bg-emerald-50 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-800 whitespace-nowrap">
                            <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-emerald-500" aria-hidden="true"></span>
                            Tercapai
                        </span>
                        <span class="text-[11px] font-semibold text-slate-500 tabular-nums">{{ $achievedRatio }}% porsi</span>
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-slate-50 px-2.5 py-0.5 text-[11px] font-semibold text-slate-600 whitespace-nowrap">
                            <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-slate-400" aria-hidden="true"></span>
                            Belum ada data
                        </span>
                    @endif
                </div>
        @if ($canAccessDetail)
            </a>
        @else
            </div>
        @endif

        {{-- Card 4: Perlu Perhatian (Interactive deep link when permitted) --}}
        @if ($canAccessDetail)
            <a href="{{ route('dashboard.kpi-detail', ['rag' => 'perlu']) }}" class="group flex flex-col justify-between p-5 transition-colors hover:bg-slate-50/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[#16a3b8]">
        @else
            <div class="flex flex-col justify-between p-5">
        @endif
                <div>
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.06em] text-slate-500">Perlu Perhatian</p>
                        @if ($canAccessDetail)
                            <svg class="h-3.5 w-3.5 text-slate-300 transition-transform group-hover:translate-x-0.5 group-hover:text-[#0b6175]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M5 12h14m-6-6 6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        @endif
                    </div>
                    <p class="mt-2 text-2xl sm:text-[28px] font-bold tracking-tight leading-none tabular-nums {{ $attentionCount !== null && $attentionCount > 0 ? 'text-amber-700' : 'text-slate-900' }}">{{ $attentionDisplay }}</p>
                </div>
                <div class="mt-3.5 flex items-center justify-between gap-2">
                    @if ($attentionCount === null)
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-slate-50 px-2.5 py-0.5 text-[11px] font-semibold text-slate-600 whitespace-nowrap">
                            <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-slate-400" aria-hidden="true"></span>
                            Belum ada data
                        </span>
                    @elseif ($attentionCount === 0)
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-200/80 bg-emerald-50 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-800 whitespace-nowrap tabular-nums">
                            <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-emerald-500" aria-hidden="true"></span>
                            0 Indikator
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-amber-200/80 bg-amber-50 px-2.5 py-0.5 text-[11px] font-semibold text-amber-800 whitespace-nowrap tabular-nums">
                            <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500" aria-hidden="true"></span>
                            {{ $attentionCount }} Indikator
                        </span>
                        @if ($canAccessDetail)
                            <span class="text-[11px] font-semibold text-[#0b6175] group-hover:underline">Tinjau</span>
                        @endif
                    @endif
                </div>
        @if ($canAccessDetail)
            </a>
        @else
            </div>
        @endif
    </div>
</div>
