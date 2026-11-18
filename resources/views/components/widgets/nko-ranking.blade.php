@props(['widget' => [], 'data' => null])

@php
    $rows = collect($data ?? []);

    $category = function (float $nko): array {
        return match (\App\Support\KpiScoreNormalizer::ragTier($nko)) {
            \App\Support\KpiScoreNormalizer::TIER_TERCAPAI => ['tercapai', 'Tercapai', 'border border-emerald-200 bg-emerald-50 text-emerald-800', 'bg-emerald-500', 'bg-emerald-500/10'],
            \App\Support\KpiScoreNormalizer::TIER_HAMPIR => ['hampir', 'Hampir Tercapai', 'border border-amber-200 bg-amber-50 text-amber-800', 'bg-amber-500', 'bg-amber-500/10'],
            default => ['perlu', 'Perlu Peningkatan', 'border border-rose-200 bg-rose-50 text-rose-800', 'bg-rose-500', 'bg-rose-500/10'],
        };
    };
@endphp

<div
    class="ibs-card flex h-full flex-col justify-between p-5"
    x-data="{ tierFilter: 'all' }"
>
    <div>
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3">
            <div class="flex items-center gap-2.5 min-w-0">
                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-[#e6f4f6] text-[#0b6175]">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6M18 9h1.5a2.5 2.5 0 0 0 0-5H18" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M4 22h16M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22M14 14.66V17c0 .55.47.98.97 1.21 1.18.54 2.03 2.03 2.03 3.79M18 2H6v7a6 6 0 0 0 12 0V2Z" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>
                <div class="min-w-0">
                    <h3 class="ibs-section-title truncate text-base font-bold text-slate-900">{{ $widget['label'] ?? 'Peringkat NKO' }}</h3>
                    <p class="text-[11px] text-slate-500">Periode terkini</p>
                </div>
            </div>

            @if ($rows->count() > 2)
                <div class="inline-flex items-center rounded-xl border border-slate-200/75 bg-slate-100/80 p-0.5 text-[11px]">
                    <button
                        type="button"
                        @click="tierFilter = 'all'"
                        :class="tierFilter === 'all' ? 'bg-white text-[#0b6175] font-semibold shadow-2xs' : 'text-slate-600 font-medium hover:text-slate-900'"
                        class="pill-tab-transition rounded-lg px-2 py-0.5 cursor-pointer"
                    >
                        Semua
                    </button>
                    <button
                        type="button"
                        @click="tierFilter = 'attention'"
                        :class="tierFilter === 'attention' ? 'bg-white text-rose-700 font-semibold shadow-2xs' : 'text-slate-600 font-medium hover:text-slate-900'"
                        class="pill-tab-transition rounded-lg px-2 py-0.5 cursor-pointer"
                    >
                        Fokus
                    </button>
                </div>
            @endif
        </div>

        @if ($rows->isEmpty())
            <p class="mt-4 rounded-xl border border-dashed border-slate-200 bg-slate-50/70 p-5 text-xs text-slate-600">
                Belum ada data NKO. Konversi laporan KPI lalu unggah berkasnya lewat menu Upload Dataset.
            </p>
        @else
            {{-- Tremor-inspired Interactive BarList --}}
            <div class="mt-3.5 max-h-[340px] space-y-2 overflow-y-auto pr-0.5">
                @foreach ($rows as $index => $row)
                    @php
                        $nkoVal = (float) $row->nko;
                        [$tier, $label, $badgeClass, $dotColor, $barBg] = $category($nkoVal);
                        $nkoFormatted = number_format($nkoVal, 2, ',', '.');
                        $barWidth = max(0, min(100, round(($nkoVal / 110) * 100, 1)));
                        $isAttention = $tier !== \App\Support\KpiScoreNormalizer::TIER_TERCAPAI;
                        $isUnitFiltered = in_array($row->unit, (array) request('unit', []), true);
                        $unitFilterUrl = $isUnitFiltered
                            ? route('dashboard')
                            : route('dashboard', array_filter([
                                'start_date' => request('start_date'),
                                'end_date' => request('end_date'),
                                'unit' => [$row->unit],
                            ]));
                    @endphp
                    <a
                        href="{{ $unitFilterUrl }}"
                        x-show="tierFilter === 'all' || {{ $isAttention ? 'true' : 'false' }}"
                        class="group relative block overflow-hidden rounded-xl border {{ $isUnitFiltered ? 'border-[#0b6175]/45 ring-1 ring-[#0b6175]/15' : 'border-slate-200/75' }} bg-white px-3 py-2.5 transition hover:border-[#0b6175]/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]"
                        title="{{ $isUnitFiltered ? 'Tampilkan semua unit' : 'Filter dashboard ke unit '.$row->unit }}"
                    >
                        {{-- Soft Proportional Bar Fill Behind Row --}}
                        <div
                            class="pointer-events-none absolute inset-y-0 left-0 transition-all duration-500 {{ $barBg }}"
                            style="width: {{ $barWidth }}%"
                            aria-hidden="true"
                        ></div>

                        <div class="relative flex items-center justify-between gap-2.5">
                            <div class="flex min-w-0 items-center gap-2.5">
                                <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-lg text-[11px] font-bold tabular-nums {{ $index < 3 ? 'bg-[#e6f4f6] text-[#0b6175]' : 'bg-slate-100 text-slate-500' }}">
                                    {{ $index + 1 }}
                                </span>
                                <div class="min-w-0">
                                    <div class="truncate text-xs font-semibold text-slate-900">{{ $row->unit }}</div>
                                    <div class="mt-0.5 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold {{ $badgeClass }}">
                                        <span class="h-1.5 w-1.5 rounded-full {{ $dotColor }}" aria-hidden="true"></span>
                                        <span>{{ $label }}</span>
                                    </div>
                                </div>
                            </div>

                            <div class="flex shrink-0 items-center gap-2 text-right">
                                <span class="text-sm font-bold text-[#0b6175] tabular-nums" x-data="numberTicker('{{ $nkoFormatted }}')" x-text="display">{{ $nkoFormatted }}</span>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
