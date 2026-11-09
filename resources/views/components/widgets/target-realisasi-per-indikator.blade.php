@props(['widget' => [], 'data' => null])

{{-- Panel Target vs Realisasi PER INDIKATOR (Looker Studio style - Varian Compact).
     Menampilkan daftar 1 baris per indikator dengan mini-bar % pencapaian.
     Target, realisasi, dan satuan mentah tersedia di tooltip.
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

    // Summary counts across all sections
    $totalCount = 0;
    $achievedCount = 0;
    $nearCount = 0;
    $belowCount = 0;

    foreach ($sections as $section) {
        foreach ($section['panels'] ?? [] as $panel) {
            $totalCount++;
            $pct = $panel['score']['percent'] ?? null;
            if ($pct !== null) {
                if ($pct >= 100) {
                    $achievedCount++;
                } elseif ($pct >= 95) {
                    $nearCount++;
                } else {
                    $belowCount++;
                }
            }
        }
    }
@endphp

<div class="h-full rounded-lg bg-white border border-slate-200 shadow-sm p-4 sm:p-5">
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
        {{-- Summary stat badges --}}
        <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 font-medium text-slate-700">
                <span class="h-2 w-2 rounded-full bg-slate-400"></span>
                Total: {{ $totalCount }} Indikator
            </span>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 font-medium text-emerald-700 border border-emerald-200">
                <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                Tercapai (&ge;100%): {{ $achievedCount }}
            </span>
            @if ($nearCount > 0)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 font-medium text-amber-700 border border-amber-200">
                    <span class="h-2 w-2 rounded-full bg-amber-500"></span>
                    Mendekati (95–99%): {{ $nearCount }}
                </span>
            @endif
            @if ($belowCount > 0)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-50 px-2.5 py-1 font-medium text-rose-700 border border-rose-200">
                    <span class="h-2 w-2 rounded-full bg-rose-500"></span>
                    Perlu Perhatian (&lt;95%): {{ $belowCount }}
                </span>
            @endif
        </div>

        <div class="mt-4 space-y-4">
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
                @endphp

                <section class="border-t border-slate-100 pt-3 first:border-0 first:pt-0">
                    @if (! empty($section['name']))
                        <h4 class="text-xs font-semibold uppercase tracking-wider text-primary pb-1">{{ $section['name'] }}</h4>
                    @endif

                    @if (count($chartable) > 0)
                        <div id="{{ $chartId }}" class="mt-2 space-y-1">
                            @foreach ($chartable as $p)
                                @php
                                    $pct = round((float) $p['score']['percent'], 1);
                                    $barWidth = max(0, min(100, $pct));
                                    if ($pct >= 100) {
                                        $barColor = 'bg-emerald-500';
                                        $textColor = 'text-emerald-700';
                                    } elseif ($pct >= 95) {
                                        $barColor = 'bg-amber-500';
                                        $textColor = 'text-amber-700';
                                    } else {
                                        $barColor = 'bg-rose-500';
                                        $textColor = 'text-rose-700';
                                    }
                                    $targetStr = $p['numeric'] ? $fmt((float) $p['numeric']['target']) : null;
                                    $realisasiStr = $p['numeric'] ? $fmt((float) $p['numeric']['realisasi']) : null;
                                    $satuanStr = $p['satuan'] ? ' '.$p['satuan'] : '';
                                    $tooltip = $p['name'].' | Target: '.($targetStr ?? '—').$satuanStr.' | Realisasi: '.($realisasiStr ?? '—').$satuanStr.' | Pencapaian: '.$fmtPercent($p['score']['percent']);
                                @endphp
                                <div
                                    class="flex items-center justify-between gap-3 rounded px-2 py-1 text-xs hover:bg-slate-50 transition-colors"
                                    data-indicator-id="{{ $p['indicator_id'] }}"
                                    data-mode="{{ $p['mode'] }}"
                                    title="{{ $tooltip }}"
                                >
                                    <span class="truncate font-medium text-slate-700 flex-1 max-w-[50%]" title="{{ $p['name'] }}">
                                        {{ $p['name'] }}
                                    </span>
                                    <div class="flex items-center gap-3 w-1/2 sm:w-2/5 justify-end">
                                        <div class="h-2 w-full max-w-[140px] rounded-full bg-slate-100 overflow-hidden">
                                            <div class="h-full rounded-full {{ $barColor }}" style="width: {{ $barWidth }}%"></div>
                                        </div>
                                        <span class="w-14 text-right font-bold {{ $textColor }}">
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
                                @endphp
                                <div
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
                                <div
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
