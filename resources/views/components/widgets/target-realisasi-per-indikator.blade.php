@props(['widget' => [], 'data' => null])

{{-- Panel Target vs Realisasi PER INDIKATOR (Looker Studio style - Varian Compact).
     Menampilkan daftar 1 baris per indikator dengan mini-bar % pencapaian.
     Target, realisasi, dan satuan mentah tersedia di inline compact & tooltip.
     Milestone tanggal dan skor unverified disajikan dalam chip padat.

     Data: App\Services\TargetRealisasiPerIndicatorReader (selalu terstruktur).
--}}
@php
    $period = $data['period'] ?? null;
    $sections = $data['sections'] ?? [];
    $units = $data['units'] ?? [];

    $fmt = function (?float $value): string {
        if ($value === null) {
            return '—';
        }

        $text = number_format($value, 4, ',', '.');

        return str_contains($text, ',') ? rtrim(rtrim($text, '0'), ',') : $text;
    };

    $fmtPercent = fn (?float $value): string => $value === null ? '—' : $fmt(round($value, 1)).'%';

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
    class="h-full rounded-xl bg-white border border-slate-200/80 shadow-xs p-4 sm:p-5 transition-all duration-200 ease-out hover:shadow-md"
    x-data="targetRealisasiWidget(JSON.parse(document.getElementById('targetRealisasiItems_{{ $instanceId }}').textContent))"
>
    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <h3 class="text-base font-bold text-primary">{{ $widget['label'] ?? 'Target dan Realisasi per Indikator' }}</h3>
        @if ($period !== null)
            <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">
                Periode {{ $period }}@if (count($units) > 0) · {{ implode(', ', $units) }}@endif
            </span>
        @endif
    </div>

    @if (empty($sections))
        <p class="mt-4 rounded-lg bg-slate-50 p-5 text-sm text-gray-600">
            Belum ada data target/realisasi terstruktur pada periode ini.
        </p>
    @else
        {{-- Ringkasan status pill badges --}}
        <div class="mt-2.5 flex flex-wrap items-center gap-2 text-xs">
            @if ($achievedCount > 0)
                <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200/80 bg-white px-2.5 py-0.5 font-medium text-slate-600 shadow-2xs">
                    <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                    Tercapai (&ge;{{ $tercapaiMinLabel }}%): {{ $achievedCount }}
                </span>
            @endif
            @if ($nearCount > 0)
                <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200/80 bg-white px-2.5 py-0.5 font-medium text-slate-600 shadow-2xs">
                    <span class="h-2 w-2 rounded-full bg-amber-500"></span>
                    Mendekati ({{ $hampirMinLabel }}–{{ $hampirMaxLabel }}%): {{ $nearCount }}
                </span>
            @endif
            @if ($belowCount > 0)
                <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200/80 bg-white px-2.5 py-0.5 font-medium text-slate-600 shadow-2xs">
                    <span class="h-2 w-2 rounded-full bg-rose-500"></span>
                    Perlu Perhatian (&lt;{{ $hampirMinLabel }}%): {{ $belowCount }}
                </span>
            @endif
        </div>

        {{-- Toolbar filter & pencarian Alpine.js instan --}}
        <div id="target-realisasi-toolbar" class="mt-3 flex flex-col gap-2.5 rounded-lg border border-slate-200/80 bg-slate-50/70 p-2.5 sm:flex-row sm:items-center sm:justify-between">
            <div class="relative flex-1 max-w-xs">
                <input
                    type="search"
                    x-model="searchQuery"
                    placeholder="Cari nama indikator..."
                    aria-label="Cari nama indikator"
                    class="w-full rounded-md border border-slate-300 bg-white py-1.5 pl-3 pr-3 text-xs text-slate-800 placeholder:text-slate-400 focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                />
            </div>
            <div class="flex flex-wrap items-center gap-1.5 text-[11px]">
                <button
                    type="button"
                    data-rag-filter="all"
                    @click="activeRag = 'all'"
                    :class="activeRag === 'all' ? 'border-slate-800 text-slate-900 font-bold ring-1 ring-slate-800/15 shadow-2xs' : 'border-slate-200 text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50 hover:text-slate-800'"
                    class="inline-flex items-center gap-1.5 cursor-pointer rounded-md border bg-white px-2.5 py-1 transition"
                >
                    <span class="h-1.5 w-1.5 rounded-full bg-slate-400 shrink-0"></span>
                    Semua ({{ $totalCount }})
                </button>
                <button
                    type="button"
                    data-rag-filter="tercapai"
                    @click="activeRag = 'tercapai'"
                    :class="activeRag === 'tercapai' ? 'border-emerald-600 text-emerald-950 font-bold ring-1 ring-emerald-600/20 shadow-2xs' : 'border-slate-200 text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50 hover:text-slate-800'"
                    class="inline-flex items-center gap-1.5 cursor-pointer rounded-md border bg-white px-2.5 py-1 transition"
                >
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 shrink-0"></span>
                    Tercapai ({{ $achievedCount }})
                </button>
                <button
                    type="button"
                    data-rag-filter="hampir"
                    @click="activeRag = 'hampir'"
                    :class="activeRag === 'hampir' ? 'border-amber-600 text-amber-950 font-bold ring-1 ring-amber-600/20 shadow-2xs' : 'border-slate-200 text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50 hover:text-slate-800'"
                    class="inline-flex items-center gap-1.5 cursor-pointer rounded-md border bg-white px-2.5 py-1 transition"
                >
                    <span class="h-1.5 w-1.5 rounded-full bg-amber-500 shrink-0"></span>
                    Mendekati ({{ $nearCount }})
                </button>
                <button
                    type="button"
                    data-rag-filter="perlu"
                    @click="activeRag = 'perlu'"
                    :class="activeRag === 'perlu' ? 'border-rose-600 text-rose-950 font-bold ring-1 ring-rose-600/20 shadow-2xs' : 'border-slate-200 text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50 hover:text-slate-800'"
                    class="inline-flex items-center gap-1.5 cursor-pointer rounded-md border bg-white px-2.5 py-1 transition"
                >
                    <span class="h-1.5 w-1.5 rounded-full bg-rose-500 shrink-0"></span>
                    Perlu Perhatian ({{ $belowCount }})
                </button>
            </div>
        </div>

        <div class="mt-4 space-y-4">
            <div x-show="visibleCount === 0" x-cloak class="rounded-lg border border-dashed border-slate-300 p-6 text-center text-xs text-slate-500">
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
                    class="border-t border-slate-100 pt-3 first:border-0 first:pt-0"
                >
                    @if (! empty($section['name']))
                        <h4 class="text-xs font-semibold uppercase tracking-wider text-primary pb-1">{{ $section['name'] }}</h4>
                    @endif

                    @if (count($chartable) > 0)
                        <div id="{{ $chartId }}" class="mt-2 space-y-1">
                            @foreach ($chartable as $p)
                                @php
                                    $pct = round((float) $p['score']['percent'], 1);
                                    $barWidth = max(0, min(100, $pct));
                                    $tier = \App\Support\KpiScoreNormalizer::ragTier($pct);
                                    if ($tier === \App\Support\KpiScoreNormalizer::TIER_TERCAPAI) {
                                        $barColor = 'bg-emerald-500';
                                        $textColor = 'text-emerald-700';
                                    } elseif ($tier === \App\Support\KpiScoreNormalizer::TIER_HAMPIR) {
                                        $barColor = 'bg-amber-500';
                                        $textColor = 'text-amber-700';
                                    } else {
                                        $barColor = 'bg-rose-500';
                                        $textColor = 'text-rose-700';
                                    }
                                    $targetStr = $p['numeric'] ? $fmt((float) $p['numeric']['target']) : null;
                                    $realisasiStr = $p['numeric'] ? $fmt((float) $p['numeric']['realisasi']) : null;
                                    $satuanStr = $p['satuan'] ? ' '.$p['satuan'] : '';
                                    $searchHaystack = strtolower($p['name'].' '.($p['satuan'] ?? ''));
                                    $tooltip = $p['name'].' | Target: '.($targetStr ?? '—').$satuanStr.' | Realisasi: '.($realisasiStr ?? '—').$satuanStr.' | Pencapaian: '.$fmtPercent($p['score']['percent']);
                                @endphp
                                <div
                                    x-show="matches('{{ addcslashes($searchHaystack, "'\\") }}', '{{ $tier }}')"
                                    class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 rounded px-2.5 py-1.5 text-xs hover:bg-slate-50 transition border border-transparent hover:border-slate-100"
                                    data-indicator-id="{{ $p['indicator_id'] }}"
                                    data-mode="{{ $p['mode'] }}"
                                    title="{{ $tooltip }}"
                                >
                                    <div class="flex items-center gap-1.5 min-w-0 flex-1">
                                        <span class="truncate font-medium text-slate-700" title="{{ $p['name'] }}">
                                            {{ $p['name'] }}
                                        </span>
                                        @if (! empty($p['satuan']))
                                            <span class="shrink-0 text-[10px] text-slate-400">({{ $p['satuan'] }})</span>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-3 shrink-0">
                                        @if ($p['mode'] === 'numeric')
                                            <div class="hidden sm:flex items-center gap-2 text-[11px] text-slate-500 tabular-nums">
                                                <span>Target: <strong class="font-semibold text-slate-700">{{ $targetStr }}</strong></span>
                                                <span>Realisasi: <strong class="font-semibold text-slate-700">{{ $realisasiStr }}</strong></span>
                                            </div>
                                        @endif
                                        <div class="h-2 w-24 sm:w-28 rounded-full bg-slate-100 overflow-hidden">
                                            <div class="h-full rounded-full {{ $barColor }}" style="width: {{ $barWidth }}%"></div>
                                        </div>
                                        <span class="w-14 text-right font-bold tabular-nums {{ $textColor }}">
                                            {{ $fmtPercent($p['score']['percent']) }}
                                        </span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- Milestone tanggal --}}
                    @if (count($datePanels) > 0)
                        <div class="mt-2.5 flex flex-wrap items-center gap-1.5">
                            <span class="text-[11px] font-semibold text-slate-500">Milestone:</span>
                            @foreach ($datePanels as $panel)
                                @php
                                    $targetDate = $panel['date']['target'] ?? '—';
                                    $realisasiDate = $panel['date']['realisasi'] ?? '—';
                                    $deltaDays = $panel['date']['delta_days'] ?? null;
                                    $deltaText = $deltaDays === null ? '—' : ($deltaDays <= 0 ? abs($deltaDays).' hari lebih cepat' : $deltaDays.' hari lebih lambat');
                                    $chipTooltip = $panel['name'].' | Target: '.$targetDate.' | Realisasi: '.$realisasiDate.' | Selisih: '.$deltaText;
                                    $chipBorder = ($deltaDays !== null && $deltaDays <= 0)
                                        ? 'border-emerald-200 bg-emerald-50/70 text-emerald-800'
                                        : 'border-rose-200 bg-rose-50/70 text-rose-800';
                                    $dateTier = ($panel['score'] !== null && $panel['score']['percent'] !== null)
                                        ? \App\Support\KpiScoreNormalizer::ragTier((float) $panel['score']['percent'])
                                        : 'all';
                                    $dateHaystack = strtolower($panel['name']);
                                @endphp
                                <div
                                    x-show="matches('{{ addcslashes($dateHaystack, "'\\") }}', '{{ $dateTier }}')"
                                    class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-[11px] font-medium {{ $chipBorder }} cursor-help"
                                    data-indicator-id="{{ $panel['indicator_id'] }}"
                                    data-mode="{{ $panel['mode'] }}"
                                    title="{{ $chipTooltip }}"
                                >
                                    <span class="truncate max-w-[160px]">{{ $panel['name'] }}</span>
                                    @if ($panel['score'] !== null && $panel['score']['percent'] !== null)
                                        <span class="font-bold">{{ $fmtPercent($panel['score']['percent']) }}</span>
                                    @endif
                                    <span class="text-[10px] opacity-75">({{ $realisasiDate }})</span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- Unverified Skor --}}
                    @if (count($unverifiedPanels) > 0)
                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            @foreach ($unverifiedPanels as $panel)
                                @php
                                    $unvHaystack = strtolower($panel['name'].' '.($panel['satuan'] ?? ''));
                                @endphp
                                <div
                                    x-show="(activeRag === 'all') && (searchQuery.trim() === '' || '{{ addcslashes($unvHaystack, "'\\") }}'.includes(searchQuery.trim().toLowerCase()))"
                                    class="inline-flex items-center gap-1.5 rounded border border-amber-200 bg-amber-50/80 px-2 py-0.5 text-[11px] text-amber-900"
                                    data-indicator-id="{{ $panel['indicator_id'] }}"
                                    data-mode="{{ $panel['mode'] }}"
                                    title="Nilai mentah: {{ $fmt($panel['score']['raw'] ?? null) }}"
                                >
                                    <span class="font-medium">{{ $panel['name'] }}</span>
                                    @if ($panel['satuan'])
                                        <span class="text-amber-700">({{ $panel['satuan'] }})</span>
                                    @endif
                                    <span class="rounded bg-yellow-100 px-1 py-0.2 font-semibold text-yellow-800 text-[10px]">Skor tidak terverifikasi</span>
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
