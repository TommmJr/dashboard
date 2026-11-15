@props(['widget' => [], 'data' => null, 'embeddedInKpiDetail' => false])

{{-- Panel Target vs Realisasi PER INDIKATOR (Quiet Luxury Executive Report Card).
     Menampilkan daftar 1 baris per indikator dengan micro-bar % pencapaian.
     Target, realisasi, dan satuan mentah tersedia di inline compact & tooltip.
     Milestone tanggal dan skor unverified disajikan dalam chip padat.

     Data: App\Services\TargetRealisasiPerIndicatorReader (selalu terstruktur).
--}}
@php
    $isEmbedded = (bool) ($embeddedInKpiDetail ?? false);
    $period = $data['period'] ?? null;
    $sections = $data['sections'] ?? [];
    $units = $data['units'] ?? [];

    $fmt = fn (?float $value): string => \App\Support\KpiScoreNormalizer::formatNumber($value, 1);

    $fmtPercent = fn (?float $value): string => \App\Support\KpiScoreNormalizer::formatNumber(
        $value !== null ? round($value, 1) : null,
        1,
        '%',
    );

    $instanceId = $widget['instance'] ?? 0;

    $ragThresholds = \App\Support\KpiScoreNormalizer::ragThresholds();
    $tercapaiMinLabel = $fmt($ragThresholds['tercapai_min']);
    $hampirMinLabel = $fmt($ragThresholds['hampir_min']);
    $hampirMaxLabel = $fmt(max($ragThresholds['hampir_min'], $ragThresholds['tercapai_min'] - 1));

    // Summary counts across all sections
    $totalCount = 0;
    $achievedCount = 0;
    $nearCount = 0;
    $belowCount = 0;

    $allItems = [];

    foreach ($sections as $section) {
        foreach ($section['panels'] ?? [] as $panel) {
            $totalCount++;
            $pct = $panel['score']['percent'] ?? null;
            $tier = 'all';
            if ($pct !== null) {
                $tier = \App\Support\KpiScoreNormalizer::ragTier((float) $pct);
                if ($tier === \App\Support\KpiScoreNormalizer::TIER_TERCAPAI) {
                    $achievedCount++;
                } elseif ($tier === \App\Support\KpiScoreNormalizer::TIER_HAMPIR) {
                    $nearCount++;
                } else {
                    $belowCount++;
                }
            } elseif ($panel['mode'] === 'date') {
                $tier = 'all';
            } else {
                $tier = 'unverified';
            }

            $allItems[] = [
                'id' => $panel['indicator_id'],
                'tier' => $tier,
                'haystack' => strtolower($panel['name'].' '.($panel['satuan'] ?? '')),
            ];
        }
    }
@endphp

<script>
if (typeof window.targetRealisasiWidget !== 'function') {
    window.targetRealisasiWidget = function(initialItems) {
        return {
            searchQuery: '',
            activeRag: 'all',
            viewMode: 'split',
            items: initialItems || [],
            matches(haystack, tier) {
                return (this.activeRag === 'all' || this.activeRag === tier) &&
                    (this.searchQuery.trim() === '' || (haystack || '').includes(this.searchQuery.trim().toLowerCase()));
            },
            get visibleCount() {
                return this.items.filter(item => this.matches(item.haystack, item.tier)).length;
            }
        };
    };
}
</script>
<script type="application/json" id="targetRealisasiItems_{{ $instanceId }}">
{!! json_encode($allItems) !!}
</script>

<div
    class="ibs-card min-w-0 max-w-full h-full rounded-2xl border border-slate-200/80 bg-white p-5 shadow-[0_1px_3px_rgba(15,23,42,0.03)] flex flex-col justify-start"
    x-data="targetRealisasiWidget(JSON.parse(document.getElementById('targetRealisasiItems_{{ $instanceId }}').textContent))"
    @kpi-view-changed.window="viewMode = $event.detail"
    @kpi-page-filter-changed.window="searchQuery = $event.detail.search ?? ''; activeRag = $event.detail.rag ?? 'all'"
>
    @if ($isEmbedded)
        <div class="flex items-center justify-between gap-2 pb-3 border-b border-slate-100">
            <h3 class="ibs-section-title text-sm font-semibold text-slate-900">{{ $widget['label'] ?? 'Target dan Realisasi per Indikator' }}</h3>
            <span class="text-xs font-medium text-slate-400 tabular-nums" x-text="visibleCount + ' indikator'">{{ $totalCount }} indikator</span>
        </div>
        @if ($period !== null)
            <span class="sr-only">
                Periode {{ $period }}@if (count($units) > 0) · {{ implode(', ', $units) }}@endif
            </span>
        @endif
    @else
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="ibs-section-title text-base font-bold text-slate-900">{{ $widget['label'] ?? 'Target dan Realisasi per Indikator' }}</h3>
            @if ($period !== null)
                <span class="inline-flex items-center rounded-full bg-[#e6f4f6] px-2.5 py-0.5 text-[11px] font-semibold text-[#0b6175]">
                    Periode {{ $period }}@if (count($units) > 0) · {{ implode(', ', $units) }}@endif
                </span>
            @endif
        </div>
    @endif

    @if (empty($sections))
        <p class="mt-4 rounded-xl border border-slate-200/70 bg-slate-50/70 p-5 text-sm text-slate-600">
            Belum ada data target/realisasi terstruktur pada periode ini.
        </p>
    @else
        {{-- Ringkasan status & toolbar filter (disembunyikan secara visual saat embedded di /dashboard/kpi-detail karena sudah terpadu di header halaman) --}}
        <div @class(['sr-only' => $isEmbedded])>
            <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs tabular-nums">
                @if ($achievedCount > 0)
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200/80 bg-white px-2.5 py-0.5 font-medium text-slate-600 shadow-2xs">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 shrink-0"></span>
                        Tercapai (&ge;{{ $tercapaiMinLabel }}%): {{ $achievedCount }}
                    </span>
                @endif
                @if ($nearCount > 0)
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200/80 bg-white px-2.5 py-0.5 font-medium text-slate-600 shadow-2xs">
                        <span class="h-1.5 w-1.5 rounded-full bg-amber-500 shrink-0"></span>
                        Mendekati ({{ $hampirMinLabel }}% s.d. {{ $hampirMaxLabel }}%): {{ $nearCount }}
                    </span>
                @endif
                @if ($belowCount > 0)
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200/80 bg-white px-2.5 py-0.5 font-medium text-slate-600 shadow-2xs">
                        <span class="h-1.5 w-1.5 rounded-full bg-rose-500 shrink-0"></span>
                        Perlu Perhatian (&lt;{{ $hampirMinLabel }}%): {{ $belowCount }}
                    </span>
                @endif
            </div>

            {{-- Toolbar filter & pencarian Alpine.js instan --}}
            <div id="target-realisasi-toolbar" class="mt-3 flex flex-col gap-2 rounded-xl border border-slate-200/80 bg-slate-50/70 p-2">
                <div class="w-full">
                    <input
                        type="search"
                        x-model="searchQuery"
                        placeholder="Cari nama indikator..."
                        aria-label="Cari nama indikator"
                        class="w-full rounded-lg border border-slate-200 bg-white py-1.5 pl-3 pr-3 text-xs text-slate-800 placeholder:text-slate-400 focus:border-[#16a3b8] focus:outline-none focus:ring-1 focus:ring-[#16a3b8]"
                    />
                </div>
                <div class="flex flex-wrap items-center gap-1 text-[11px]">
                    <button
                        type="button"
                        data-rag-filter="all"
                        @click="activeRag = 'all'"
                        :class="activeRag === 'all' ? 'border-slate-800 text-slate-900 font-bold ring-1 ring-slate-800/15 shadow-2xs' : 'border-slate-200 text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50 hover:text-slate-800'"
                        class="inline-flex items-center gap-1 cursor-pointer rounded-md border bg-white px-2 py-0.5 transition"
                    >
                        <span class="h-1.5 w-1.5 rounded-full bg-slate-400 shrink-0"></span>
                        Semua ({{ $totalCount }})
                    </button>
                    <button
                        type="button"
                        data-rag-filter="tercapai"
                        @click="activeRag = 'tercapai'"
                        :class="activeRag === 'tercapai' ? 'border-emerald-600 text-emerald-950 font-bold ring-1 ring-emerald-600/20 shadow-2xs' : 'border-slate-200 text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50 hover:text-slate-800'"
                        class="inline-flex items-center gap-1 cursor-pointer rounded-md border bg-white px-2 py-0.5 transition"
                    >
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 shrink-0"></span>
                        Tercapai ({{ $achievedCount }})
                    </button>
                    <button
                        type="button"
                        data-rag-filter="hampir"
                        @click="activeRag = 'hampir'"
                        :class="activeRag === 'hampir' ? 'border-amber-600 text-amber-950 font-bold ring-1 ring-amber-600/20 shadow-2xs' : 'border-slate-200 text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50 hover:text-slate-800'"
                        class="inline-flex items-center gap-1 cursor-pointer rounded-md border bg-white px-2 py-0.5 transition"
                    >
                        <span class="h-1.5 w-1.5 rounded-full bg-amber-500 shrink-0"></span>
                        Mendekati ({{ $nearCount }})
                    </button>
                    <button
                        type="button"
                        data-rag-filter="perlu"
                        @click="activeRag = 'perlu'"
                        :class="activeRag === 'perlu' ? 'border-rose-600 text-rose-950 font-bold ring-1 ring-rose-600/20 shadow-2xs' : 'border-slate-200 text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50 hover:text-slate-800'"
                        class="inline-flex items-center gap-1 cursor-pointer rounded-md border bg-white px-2 py-0.5 transition"
                    >
                        <span class="h-1.5 w-1.5 rounded-full bg-rose-500 shrink-0"></span>
                        Perlu Perhatian ({{ $belowCount }})
                    </button>
                </div>
            </div>
        </div>

        <div class="relative mt-4 space-y-5 flex-1">
            <div x-show="visibleCount === 0" x-cloak class="rounded-xl border border-dashed border-slate-200 bg-slate-50/50 p-6 text-center text-xs text-slate-500">
                Tidak ada indikator yang sesuai dengan kriteria filter atau pencarian.
            </div>

            @foreach ($sections as $sectionIndex => $section)
                @php
                    $panels = $section['panels'] ?? [];
                    $chartable = [];
                    $datePanels = [];
                    $unverifiedPanels = [];

                    foreach ($panels as $panel) {
                        if ($panel['mode'] === 'date') {
                            $datePanels[] = $panel;
                        } elseif ($panel['score'] !== null && $panel['score']['scale'] === 'unverified') {
                            $unverifiedPanels[] = $panel;
                        } elseif ($panel['score'] !== null && $panel['score']['percent'] !== null) {
                            $chartable[] = $panel;
                        }
                    }

                    $chartId = 'target-realisasi-chart-'.$sectionIndex.'-'.$instanceId;
                    $sectionIndicatorIds = array_column($panels, 'indicator_id');
                @endphp

                <section
                    x-show="items.some(item => [{!! implode(',', array_map('intval', $sectionIndicatorIds)) !!}].includes(item.id) && matches(item.haystack, item.tier))"
                    class="relative border-t border-slate-100 pt-4 first:border-0 first:pt-0"
                >
                    @if (! empty($section['name']))
                        <h4 class="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-400 pb-2.5">{{ $section['name'] }}</h4>
                    @endif

                    @if (count($chartable) > 0)
                        <div
                            id="{{ $chartId }}"
                            class="grid grid-cols-1 min-w-0"
                            :class="viewMode === 'cards' ? 'md:grid-cols-2 xl:grid-cols-3 gap-4' : 'grid-cols-1 gap-3'"
                        >
                            @foreach ($chartable as $p)
                                @php
                                    $pct = round((float) $p['score']['percent'], 1);
                                    $barWidth = max(0, min(100, $pct));
                                    $tier = \App\Support\KpiScoreNormalizer::ragTier($pct);
                                    if ($tier === \App\Support\KpiScoreNormalizer::TIER_TERCAPAI) {
                                        $barColor = 'bg-emerald-600/85';
                                        $textColor = 'text-emerald-700';
                                    } elseif ($tier === \App\Support\KpiScoreNormalizer::TIER_HAMPIR) {
                                        $barColor = 'bg-amber-500/85';
                                        $textColor = 'text-amber-700';
                                    } else {
                                        $barColor = 'bg-rose-500/85';
                                        $textColor = 'text-rose-700';
                                    }
                                    $rawTarget = $p['numeric']['target'] ?? ($p['partial_numeric']['target'] ?? null);
                                    $rawRealisasi = $p['numeric']['realisasi'] ?? ($p['partial_numeric']['realisasi'] ?? null);
                                    $cmp = \App\Support\KpiScoreNormalizer::describeComparison([
                                        'satuan' => $p['satuan'] ?? null,
                                        'polarity' => $p['polarity'] ?? null,
                                        'target_type' => $p['target_type'] ?? 'numeric',
                                        'realisasi_type' => $p['realisasi_type'] ?? 'numeric',
                                        'target' => $rawTarget,
                                        'realisasi' => $rawRealisasi,
                                        'target_text' => $p['target_text'] ?? null,
                                        'realisasi_text' => $p['realisasi_text'] ?? null,
                                        'skor_kpi' => $pct,
                                        'tier' => $tier,
                                    ]);
                                    $unitBadge = $cmp['unit_label'];
                                    $targetStr = $cmp['target_override'] ?? $fmt($rawTarget !== null ? (float) $rawTarget : null);
                                    $realisasiStr = $cmp['realisasi_override'] ?? $fmt($rawRealisasi !== null ? (float) $rawRealisasi : null);
                                    $satuanStr = $unitBadge ? ' '.$unitBadge : '';
                                    $searchHaystack = strtolower($p['name'].' '.($unitBadge ?? ($p['satuan'] ?? '')));
                                    $tooltip = $p['name'].' | Target: '.$targetStr.$satuanStr.' | Realisasi: '.$realisasiStr.$satuanStr.' | Pencapaian: '.$fmtPercent($p['score']['percent']).' | '.$cmp['diff_label'];
                                @endphp
                                <div
                                    x-show="matches('{{ addcslashes($searchHaystack, "'\\") }}', '{{ $tier }}')"
                                    class="min-w-0 max-w-full flex flex-col justify-between rounded-xl border border-slate-200/80 bg-white px-4 py-3.5 shadow-[0_1px_2px_rgba(15,23,42,0.02)] hover:border-[#0b6175]/35 hover:shadow-xs transition-all"
                                    data-indicator-id="{{ $p['indicator_id'] }}"
                                    data-mode="{{ $p['mode'] }}"
                                    title="{{ $tooltip }}"
                                >
                                    <div class="flex items-start justify-between gap-2.5 min-w-0">
                                        <div class="flex-1 min-w-0">
                                            <span class="block text-[13px] font-semibold text-slate-900 leading-snug" title="{{ $p['name'] }}">
                                                {{ $p['name'] }}
                                            </span>
                                            @if (! empty($cmp['indicator_hint']) && $cmp['indicator_hint'] !== $unitBadge)
                                                <span class="mt-0.5 block text-[10.5px] leading-tight text-slate-400">
                                                    {{ $cmp['indicator_hint'] }}
                                                </span>
                                            @endif
                                        </div>
                                        <div class="flex items-center gap-2 shrink-0">
                                            @if (! empty($unitBadge))
                                                <span class="bg-slate-100/80 text-slate-600 px-2 py-0.5 rounded-md text-[11px] font-medium shrink-0">
                                                    {{ $unitBadge }}
                                                </span>
                                            @endif
                                            <span class="tabular-nums text-xs font-bold {{ $textColor }}">
                                                {{ $fmtPercent($p['score']['percent']) }}
                                            </span>
                                        </div>
                                    </div>
                                    <div class="mt-2.5 flex flex-col gap-2 min-w-0">
                                        @if ($p['mode'] === 'numeric' || $targetStr !== 'Tidak tercatat' || $realisasiStr !== 'Tidak tercatat')
                                            <div class="flex flex-wrap items-start justify-between gap-2 text-xs text-slate-500 tabular-nums">
                                                <div>
                                                    <span>Target: <strong class="font-semibold text-slate-800">{{ $targetStr }}</strong></span>
                                                    @if (! empty($cmp['target_hint']) && $cmp['target_hint'] !== $unitBadge)
                                                        <span class="block text-[10px] leading-tight text-slate-400">{{ $cmp['target_hint'] }}</span>
                                                    @endif
                                                </div>
                                                <div class="text-right">
                                                    <span>Realisasi: <strong class="font-semibold text-slate-800">{{ $realisasiStr }}</strong></span>
                                                    @if (! empty($cmp['realisasi_hint']) && $cmp['realisasi_hint'] !== $unitBadge)
                                                        <span class="block text-[10px] leading-tight text-slate-400">{{ $cmp['realisasi_hint'] }}</span>
                                                    @endif
                                                </div>
                                            </div>
                                        @endif
                                        @if ($cmp['target_bar_pct'] !== null && $cmp['realisasi_bar_pct'] !== null)
                                            <div dir="ltr" class="space-y-1">
                                                <div class="h-1.5 w-full rounded-full bg-slate-100 overflow-hidden" title="Target: {{ $targetStr }}{{ $satuanStr }}">
                                                    <div class="h-full rounded-full bg-slate-300" style="width: {{ $cmp['target_bar_pct'] }}%"></div>
                                                </div>
                                                <div class="h-1.5 w-full rounded-full bg-slate-100 overflow-hidden" title="Realisasi: {{ $realisasiStr }}{{ $satuanStr }}">
                                                    <div class="h-full rounded-full {{ $barColor }}" style="width: {{ $cmp['realisasi_bar_pct'] }}%"></div>
                                                </div>
                                            </div>
                                            <div class="flex items-center justify-between text-[10.5px] text-slate-500">
                                                <span class="text-slate-400">Perbandingan</span>
                                                <span class="font-medium text-slate-600 tabular-nums">{{ $cmp['diff_label'] }}</span>
                                            </div>
                                        @else
                                            <div class="h-1.5 w-full rounded-full bg-slate-100 overflow-hidden">
                                                <div class="h-full rounded-full {{ $barColor }}" style="width: {{ $barWidth }}%"></div>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- Milestone tanggal --}}
                    @if (count($datePanels) > 0)
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <span class="text-[11px] font-semibold text-slate-500">Milestone:</span>
                            @foreach ($datePanels as $panel)
                                @php
                                    $targetDate = $panel['date']['target'] ?? 'Tidak tercatat';
                                    $realisasiDate = $panel['date']['realisasi'] ?? 'Tidak tercatat';
                                    $deltaDays = $panel['date']['delta_days'] ?? null;
                                    $deltaText = $deltaDays === null
                                        ? 'Tanggal tercatat'
                                        : ($deltaDays < 0 ? 'Lebih cepat '.abs($deltaDays).' hari' : ($deltaDays === 0 ? 'Tepat waktu' : 'Lebih lambat '.$deltaDays.' hari'));
                                    $chipTooltip = $panel['name'].' | Batas tanggal: '.$targetDate.' | Tanggal tercapai: '.$realisasiDate.' | '.$deltaText;
                                    $chipDot = ($deltaDays !== null && $deltaDays <= 0)
                                        ? 'bg-emerald-500'
                                        : 'bg-rose-500';
                                    $dateTier = ($panel['score'] !== null && $panel['score']['percent'] !== null)
                                        ? \App\Support\KpiScoreNormalizer::ragTier((float) $panel['score']['percent'])
                                        : 'all';
                                    $dateHaystack = strtolower($panel['name']);
                                @endphp
                                <div
                                    x-show="matches('{{ addcslashes($dateHaystack, "'\\") }}', '{{ $dateTier }}')"
                                    class="inline-flex max-w-full flex-wrap items-center gap-1.5 rounded-lg border border-slate-200/80 bg-slate-50/70 px-2.5 py-1 text-[11px] font-medium text-slate-700 cursor-help tabular-nums"
                                    data-indicator-id="{{ $panel['indicator_id'] }}"
                                    data-mode="{{ $panel['mode'] }}"
                                    title="{{ $chipTooltip }}"
                                >
                                    <span class="h-1.5 w-1.5 rounded-full shrink-0 {{ $chipDot }}"></span>
                                    <span class="truncate max-w-[180px]">{{ $panel['name'] }}</span>
                                    @if ($panel['score'] !== null && $panel['score']['percent'] !== null)
                                        <span class="font-bold text-slate-900">{{ $fmtPercent($panel['score']['percent']) }}</span>
                                    @endif
                                    <span class="text-[10px] text-slate-500">({{ $realisasiDate }}) &bull; Batas {{ $targetDate }} &bull; {{ $deltaText }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- Unverified Skor --}}
                    @if (count($unverifiedPanels) > 0)
                        <div class="mt-2.5 flex flex-wrap items-center gap-2">
                            @foreach ($unverifiedPanels as $panel)
                                @php
                                    $unvHaystack = strtolower($panel['name'].' '.($panel['satuan'] ?? ''));
                                @endphp
                                <div
                                    x-show="(activeRag === 'all') && (searchQuery.trim() === '' || '{{ addcslashes($unvHaystack, "'\\") }}'.includes(searchQuery.trim().toLowerCase()))"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-amber-200/80 bg-amber-50/70 px-2.5 py-1 text-[11px] text-amber-900"
                                    data-indicator-id="{{ $panel['indicator_id'] }}"
                                    data-mode="{{ $panel['mode'] }}"
                                    title="Nilai mentah: {{ $fmt($panel['score']['raw'] ?? null) }}"
                                >
                                    <span class="font-medium">{{ $panel['name'] }}</span>
                                    @if ($panel['satuan'])
                                        <span class="text-amber-700">({{ $panel['satuan'] }})</span>
                                    @endif
                                    <span class="rounded bg-amber-100/90 px-1.5 py-0.5 font-semibold text-amber-800 text-[10px]">Skor tidak terverifikasi</span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- Accessible representation for characterization tests and screen readers --}}
                    <div class="sr-only" aria-label="Aksesibilitas data indikator seksi {{ $section['name'] ?? '' }}">
                        @foreach ($panels as $panel)
                            <div data-indicator-id="{{ $panel['indicator_id'] }}" data-mode="{{ $panel['mode'] }}">
                                <span>{{ $panel['name'] }}</span>
                                @if ($panel['satuan'])
                                    <span>{{ $panel['satuan'] }}</span>
                                @endif
                                @if ($panel['score'] !== null)
                                    @if ($panel['score']['scale'] === 'unverified')
                                        <span>Skor tidak terverifikasi</span>
                                    @else
                                        <span>{{ $fmtPercent($panel['score']['percent']) }}</span>
                                    @endif
                                @endif
                                @if ($panel['mode'] === 'numeric')
                                    <span>Target: {{ $fmt((float) $panel['numeric']['target']) }}</span>
                                    <span>Realisasi: {{ $fmt((float) $panel['numeric']['realisasi']) }}</span>
                                @endif
                                @if (! empty($panel['period_conflict']))
                                    <span>Periode banner sheet berbeda dari periode upload.</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</div>
