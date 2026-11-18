@props(['widget' => [], 'data' => null, 'embeddedInKpiDetail' => false])

{{-- Panel Target vs Realisasi PER INDIKATOR (PLN Telemetry Bullet Gauge Cards).
     Data: App\Services\TargetRealisasiPerIndicatorReader (selalu terstruktur). --}}
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

    $totalCount = 0;
    $achievedCount = 0;
    $nearCount = 0;
    $belowCount = 0;

    $allItems = [];
    $defaultIdx = 0;

    foreach ($sections as $section) {
        $groupName = (string) ($section['name'] ?? 'Umum');
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
                'id' => (int) $panel['indicator_id'],
                'name' => (string) $panel['name'],
                'group' => $groupName,
                'tier' => $tier,
                'pct' => $pct !== null ? (float) $pct : null,
                'idx' => $defaultIdx++,
                'haystack' => strtolower($panel['name'].' '.($panel['satuan'] ?? '').' '.$groupName),
            ];
        }
    }
@endphp

<script @cspNonce>
if (typeof window.targetRealisasiWidget !== 'function') {
    window.targetRealisasiWidget = function(initialItems, defaultMode = 'cards') {
        return {
            searchQuery: '',
            activeRag: 'all',
            activeGroup: 'all',
            sortBy: 'default',
            viewMode: defaultMode,
            items: initialItems || [],
            init() {
                const params = new URLSearchParams(window.location.search);
                const ragParam = params.get('rag');
                if (['all', 'tercapai', 'hampir', 'perlu'].includes(ragParam)) {
                    this.activeRag = ragParam;
                }
                const viewParam = params.get('view');
                if (['split', 'cards', 'table'].includes(viewParam)) {
                    this.viewMode = viewParam;
                }
                const qParam = params.get('search');
                if (qParam) {
                    this.searchQuery = qParam;
                }
            },
            matches(haystack, tier, group = 'all') {
                const ragOk = this.activeRag === 'all' || this.activeRag === tier;
                const groupOk = this.activeGroup === 'all' || group === this.activeGroup;
                const q = this.searchQuery.trim().toLowerCase();
                const searchOk = q === '' || (haystack || '').includes(q);
                return ragOk && groupOk && searchOk;
            },
            get sortedOrderMap() {
                const sorted = [...this.items];
                if (this.sortBy === 'lowest') {
                    sorted.sort((a, b) => (a.pct ?? 9999) - (b.pct ?? 9999));
                } else if (this.sortBy === 'highest') {
                    sorted.sort((a, b) => (b.pct ?? -9999) - (a.pct ?? -9999));
                } else if (this.sortBy === 'name') {
                    sorted.sort((a, b) => String(a.name || '').localeCompare(String(b.name || ''), 'id'));
                } else {
                    sorted.sort((a, b) => a.idx - b.idx);
                }
                const map = {};
                sorted.forEach((item, index) => {
                    map[item.id] = index;
                });
                return map;
            },
            orderFor(id) {
                return this.sortedOrderMap[id] ?? 0;
            },
            get visibleCount() {
                return this.items.filter(item => this.matches(item.haystack, item.tier, item.group)).length;
            }
        };
    };
}
</script>
<script type="application/json" id="targetRealisasiItems_{{ $instanceId }}">
{!! json_encode($allItems) !!}
</script>

<div
    class="ibs-card min-w-0 max-w-full h-full p-5 flex flex-col justify-between"
    x-data="targetRealisasiWidget(JSON.parse(document.getElementById('targetRealisasiItems_{{ $instanceId }}').textContent), '{{ $isEmbedded ? 'cards' : 'split' }}')"
    @kpi-view-changed.window="viewMode = $event.detail"
    @kpi-page-filter-changed.window="searchQuery = $event.detail.search ?? ''; activeRag = $event.detail.rag ?? 'all'; activeGroup = $event.detail.group ?? 'all'; sortBy = $event.detail.sort ?? 'default'"
>
    <div>
    @if ($isEmbedded)
        <div class="flex items-start justify-between gap-3 pb-3 border-b border-slate-100">
            <div class="min-w-0">
                <h3 class="ibs-section-title text-sm font-bold text-slate-900 tracking-tight">{{ $widget['label'] ?? 'Target dan Realisasi per Indikator' }}</h3>
                <p class="mt-0.5 text-[11px] text-slate-500">Klik kartu indikator untuk inspeksi rincian terhadap batas {{ $tercapaiMinLabel }}%</p>
            </div>
            <span class="inline-flex items-center rounded-full border border-slate-200/80 bg-slate-50 px-2.5 py-0.5 text-[11px] font-semibold text-slate-700 tabular-nums shrink-0" x-text="visibleCount + ' indikator'">{{ $totalCount }} indikator</span>
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
        <p class="mt-4 rounded-xl border border-dashed border-slate-200 bg-slate-50/70 p-6 text-center text-sm text-slate-600">
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
                        id="target-realisasi-search"
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
            <div x-show="visibleCount === 0" x-cloak class="rounded-xl border border-dashed border-slate-200 bg-slate-50/50 p-8 text-center space-y-2">
                <p class="text-xs font-medium text-slate-600">Tidak ada indikator yang sesuai dengan kriteria filter atau pencarian.</p>
                @if ($isEmbedded)
                    <button type="button" @click="$dispatch('kpi-reset-filters')" class="pg-control-btn inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 cursor-pointer">
                        Reset Semua Filter
                    </button>
                @endif
            </div>

            @foreach ($sections as $sectionIndex => $section)
                @php
                    $panels = $section['panels'] ?? [];
                    $sectionGroup = (string) ($section['name'] ?? 'Umum');
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
                    x-show="items.some(item => [{!! implode(',', array_map('intval', $sectionIndicatorIds)) !!}].includes(item.id) && matches(item.haystack, item.tier, item.group))"
                    class="relative border-t border-slate-100 pt-4 first:border-0 first:pt-0"
                >
                    @if (! empty($section['name']))
                        <div class="flex items-center justify-between gap-2 pb-3">
                            <h4 class="text-[11px] font-bold uppercase tracking-[0.07em] text-slate-500">{{ $section['name'] }}</h4>
                            @if ($loop->first)
                                <span class="text-[11px] font-medium text-slate-400 tabular-nums">Target Acuan: {{ $tercapaiMinLabel }}% &bull; Maks 110%</span>
                            @endif
                        </div>
                    @endif

                    @if (count($chartable) > 0)
                        <div
                            id="{{ $chartId }}"
                            class="grid grid-cols-1 min-w-0"
                            :class="viewMode === 'cards' ? 'md:grid-cols-2 xl:grid-cols-3 gap-4' : 'gap-3'"
                        >
                            @foreach ($chartable as $p)
                                @php
                                    $pct = round((float) $p['score']['percent'], 1);
                                    $bulletWidth = max(0, min(100, round(($pct / 110) * 100, 1)));
                                    $tier = \App\Support\KpiScoreNormalizer::ragTier($pct);
                                    if ($tier === \App\Support\KpiScoreNormalizer::TIER_TERCAPAI) {
                                        $barColor = 'bg-emerald-500';
                                        $dotColor = 'bg-emerald-500';
                                        $pillTheme = 'border-emerald-200/80 bg-emerald-50/70 text-emerald-800';
                                        $cardHover = 'hover:border-[#0b6175]/40';
                                    } elseif ($tier === \App\Support\KpiScoreNormalizer::TIER_HAMPIR) {
                                        $barColor = 'bg-amber-500';
                                        $dotColor = 'bg-amber-500';
                                        $pillTheme = 'border-amber-200/80 bg-amber-50/70 text-amber-800';
                                        $cardHover = 'hover:border-amber-400/60';
                                    } else {
                                        $barColor = 'bg-rose-500';
                                        $dotColor = 'bg-rose-500';
                                        $pillTheme = 'border-rose-200/80 bg-rose-50/70 text-rose-800';
                                        $cardHover = 'hover:border-rose-400/60';
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
                                    $searchHaystack = strtolower($p['name'].' '.($unitBadge ?? ($p['satuan'] ?? '')).' '.$sectionGroup);
                                    $tooltip = $p['name'].' | Target: '.$targetStr.$satuanStr.' | Realisasi: '.$realisasiStr.$satuanStr.' | Pencapaian: '.$fmtPercent($p['score']['percent']).' | '.$cmp['diff_label'];
                                @endphp
                                <div
                                    x-show="matches('{{ addcslashes($searchHaystack, "'\\") }}', '{{ $tier }}', '{{ addcslashes($sectionGroup, "'\\") }}')"
                                    :style="'order: ' + orderFor({{ (int) $p['indicator_id'] }})"
                                    @if ($isEmbedded)
                                        @click="$dispatch('open-kpi-inspector', { id: {{ (int) $p['indicator_id'] }} })"
                                    @endif
                                    class="group min-w-0 max-w-full flex flex-col justify-between rounded-2xl border border-slate-200/80 bg-white p-4 {{ $cardHover }} hover:shadow-md hover:-translate-y-[1px] transition-all duration-200 {{ $isEmbedded ? 'cursor-pointer' : '' }}"
                                    data-indicator-id="{{ $p['indicator_id'] }}"
                                    data-mode="{{ $p['mode'] }}"
                                    title="{{ $tooltip }}"
                                >
                                    {{-- Top Row: Indicator Title & Achievement Pill --}}
                                    <div class="flex items-start justify-between gap-2.5 min-w-0">
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-1.5 mb-1">
                                                <span class="h-2 w-2 rounded-full shrink-0 {{ $dotColor }}" aria-hidden="true"></span>
                                                @if (! empty($unitBadge))
                                                    <span class="rounded-md bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-600 truncate">
                                                        {{ $unitBadge }}
                                                    </span>
                                                @endif
                                            </div>
                                            <span class="block text-[13px] font-semibold text-slate-900 leading-snug group-hover:text-[#0b6175] transition-colors">
                                                {{ $p['name'] }}
                                            </span>
                                            @if (! empty($cmp['indicator_hint']) && $cmp['indicator_hint'] !== $unitBadge)
                                                <span class="mt-0.5 block text-[10.5px] leading-tight text-slate-400">
                                                    {{ $cmp['indicator_hint'] }}
                                                </span>
                                            @endif
                                        </div>

                                        <span class="inline-flex shrink-0 items-center rounded-full border px-2.5 py-0.5 text-xs font-bold tabular-nums {{ $pillTheme }}">
                                            {{ $fmtPercent($p['score']['percent']) }}
                                        </span>
                                    </div>

                                    {{-- Middle: Target vs Realisasi Pair + Telemetry Bullet Track --}}
                                    <div class="mt-3 pt-2.5 border-t border-slate-100 space-y-2.5 min-w-0">
                                        @if ($p['mode'] === 'numeric' || $targetStr !== 'Tidak tercatat' || $realisasiStr !== 'Tidak tercatat')
                                            <div class="grid grid-cols-2 gap-3 text-[11px] tabular-nums">
                                                <div class="min-w-0">
                                                    <span class="block text-[10px] font-medium uppercase tracking-wider text-slate-400">Target</span>
                                                    <strong class="mt-0.5 block truncate text-xs font-semibold text-slate-700">{{ $targetStr }}</strong>
                                                    @if (! empty($cmp['target_hint']) && $cmp['target_hint'] !== $unitBadge)
                                                        <span class="block truncate text-[9.5px] text-slate-400">{{ $cmp['target_hint'] }}</span>
                                                    @endif
                                                </div>
                                                <div class="min-w-0 text-right">
                                                    <span class="block text-[10px] font-medium uppercase tracking-wider text-slate-400">Realisasi</span>
                                                    <strong class="mt-0.5 block truncate text-xs font-bold text-slate-900">{{ $realisasiStr }}</strong>
                                                    @if (! empty($cmp['realisasi_hint']) && $cmp['realisasi_hint'] !== $unitBadge)
                                                        <span class="block truncate text-[9.5px] text-slate-400">{{ $cmp['realisasi_hint'] }}</span>
                                                    @endif
                                                </div>
                                            </div>
                                        @endif

                                        {{-- Bullet Track (0%..110% with 95% & 100% target markers) --}}
                                        <div class="relative pt-1 pb-0.5" title="Capaian: {{ $fmtPercent($p['score']['percent']) }}">
                                            <div class="relative h-2 w-full rounded-full bg-slate-100 overflow-hidden">
                                                <div class="h-full rounded-full {{ $barColor }} animate-bar-reveal transition-all duration-500" style="width: {{ $bulletWidth }}%"></div>
                                            </div>
                                            {{-- 95% warning tick --}}
                                            <span class="pointer-events-none absolute top-0.5 h-3 w-px bg-amber-400/75" style="left: 86.36%" aria-hidden="true"></span>
                                            {{-- 100% target marker --}}
                                            <span class="pointer-events-none absolute top-0 h-4 w-0.5 rounded-full bg-slate-700/75" style="left: 90.9%" aria-hidden="true"></span>
                                        </div>

                                        {{-- Bottom: Deviation + Inspect Prompt --}}
                                        <div class="flex items-center justify-between gap-2 pt-0.5 text-[10.5px]">
                                            <span class="inline-flex items-center rounded-md border px-2 py-0.5 font-semibold tabular-nums {{ $pillTheme }}">
                                                {{ $cmp['diff_label'] }}
                                            </span>
                                            @if ($isEmbedded)
                                                <button
                                                    type="button"
                                                    @click.stop="$dispatch('open-kpi-inspector', { id: {{ (int) $p['indicator_id'] }} })"
                                                    class="inline-flex items-center gap-1 text-[11px] font-semibold text-slate-400 group-hover:text-[#0b6175] transition-colors cursor-pointer"
                                                >
                                                    <span>Detail</span>
                                                    <svg class="h-3 w-3 transition-transform group-hover:translate-x-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                        <path d="m9 18 6-6-6-6" stroke-linecap="round" stroke-linejoin="round"/>
                                                    </svg>
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- Milestone tanggal --}}
                    @if (count($datePanels) > 0)
                        <div class="mt-3.5 flex flex-wrap items-center gap-2">
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
                                    $dateHaystack = strtolower($panel['name'].' '.$sectionGroup);
                                @endphp
                                <button
                                    type="button"
                                    @if ($isEmbedded)
                                        @click="$dispatch('open-kpi-inspector', { id: {{ (int) $panel['indicator_id'] }} })"
                                    @endif
                                    x-show="matches('{{ addcslashes($dateHaystack, "'\\") }}', '{{ $dateTier }}', '{{ addcslashes($sectionGroup, "'\\") }}')"
                                    class="inline-flex max-w-full flex-wrap items-center gap-1.5 rounded-xl border border-slate-200/80 bg-white px-3 py-1.5 text-[11px] font-medium text-slate-700 hover:border-[#0b6175]/40 transition tabular-nums cursor-pointer"
                                    data-indicator-id="{{ $panel['indicator_id'] }}"
                                    data-mode="{{ $panel['mode'] }}"
                                    title="{{ $chipTooltip }}"
                                >
                                    <span class="h-1.5 w-1.5 rounded-full shrink-0 {{ $chipDot }}"></span>
                                    <span class="truncate max-w-[200px] font-semibold text-slate-800">{{ $panel['name'] }}</span>
                                    @if ($panel['score'] !== null && $panel['score']['percent'] !== null)
                                        <span class="font-bold text-[#0b6175]">{{ $fmtPercent($panel['score']['percent']) }}</span>
                                    @endif
                                    <span class="text-[10px] text-slate-500">({{ $realisasiDate }}) &bull; Batas {{ $targetDate }} &bull; {{ $deltaText }}</span>
                                </button>
                            @endforeach
                        </div>
                    @endif

                    {{-- Unverified Skor --}}
                    @if (count($unverifiedPanels) > 0)
                        <div class="mt-2.5 flex flex-wrap items-center gap-2">
                            @foreach ($unverifiedPanels as $panel)
                                @php
                                    $unvHaystack = strtolower($panel['name'].' '.($panel['satuan'] ?? '').' '.$sectionGroup);
                                @endphp
                                <div
                                    x-show="(activeRag === 'all') && (activeGroup === 'all' || '{{ addcslashes($sectionGroup, "'\\") }}' === activeGroup) && (searchQuery.trim() === '' || '{{ addcslashes($unvHaystack, "'\\") }}'.includes(searchQuery.trim().toLowerCase()))"
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-amber-200/80 bg-amber-50/70 px-2.5 py-1 text-[11px] text-amber-900"
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

    @if ($isEmbedded && ! empty($sections))
        <div class="mt-5 pt-3 border-t border-slate-100 flex flex-wrap items-center justify-between gap-2 text-[11px] text-slate-500 tabular-nums">
            <span class="inline-flex items-center gap-1.5">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 shrink-0" aria-hidden="true"></span>
                <span>Ambang Tercapai &ge; {{ $tercapaiMinLabel }}% &bull; Mendekati {{ $hampirMinLabel }}% &bull; Klik kartu untuk inspeksi detail</span>
            </span>
            <span>Skor Maksimal {{ $fmt(\App\Support\KpiScoreNormalizer::SCORE_MAX) }}%</span>
        </div>
    @endif
</div>
