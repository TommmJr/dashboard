@props(['widget' => [], 'data' => null])

{{-- Panel Target vs Realisasi PER INDIKATOR (keputusan Pak Gama 2026-10-19
     11:17). Server-side, tanpa fetch dan tanpa ECharts: satu kartu per
     indikator individual sehingga satuan berbeda tidak pernah satu sumbu.

     Data: App\Services\TargetRealisasiPerIndicatorReader (selalu terstruktur,
     tidak membaca KPI_READ_MODEL). Widget lama `target-vs-realisasi` / R8
     TIDAK dipakai dan TIDAK diubah.

     Aturan tampil:
     - numeric  : dua bar berdampingan, skala per panel (tidak lintas indikator)
     - date     : kartu timeline (target, realisasi, selisih hari) + skor
     - score_only: kartu skor; persen HANYA bila skala terverifikasi
     - baris grup = judul seksi; NKO tidak pernah jadi panel --}}
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
@endphp

<div class="rounded-lg bg-white border border-slate-200 shadow-sm p-6">
    <h3 class="text-lg font-bold text-primary">{{ $widget['label'] ?? 'Target dan Realisasi per Indikator' }}</h3>

    @if ($period !== null)
        <p class="mt-1 text-xs text-gray-500">
            Periode {{ $period }}@if (count($units) > 0) — unit {{ implode(', ', $units) }}@endif.
            Satu panel per indikator; nilai tidak dijumlahkan lintas indikator atau satuan.
        </p>
    @endif

    @if (empty($sections))
        <p class="mt-4 rounded-lg bg-slate-50 p-5 text-sm text-gray-600">
            Belum ada data target/realisasi terstruktur pada periode ini.
        </p>
    @else
        <div class="mt-4 space-y-6">
            @foreach ($sections as $section)
                <section>
                    @if (! empty($section['name']))
                        <h4 class="text-sm font-semibold text-primary">{{ $section['name'] }}</h4>
                    @endif

                    <div class="mt-3 grid gap-4 sm:grid-cols-2">
                        @foreach ($section['panels'] as $panel)
                            <article
                                class="rounded border border-slate-200 p-4"
                                data-indicator-id="{{ $panel['indicator_id'] }}"
                                data-mode="{{ $panel['mode'] }}"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <h5 class="text-sm font-medium text-primary">{{ $panel['name'] }}</h5>
                                    @if (! empty($panel['satuan']))
                                        <span class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-gray-600">{{ $panel['satuan'] }}</span>
                                    @endif
                                </div>

                                @if ($panel['mode'] === 'numeric')
                                    @php
                                        $target = (float) $panel['numeric']['target'];
                                        $realisasi = (float) $panel['numeric']['realisasi'];
                                        $max = max($target, $realisasi, 0.0001);
                                        $targetWidth = max(0, min(100, $target / $max * 100));
                                        $realisasiWidth = max(0, min(100, $realisasi / $max * 100));
                                    @endphp
                                    <div class="mt-3 space-y-2 text-xs">
                                        <div>
                                            <div class="flex justify-between text-gray-600">
                                                <span>Target</span>
                                                <span class="font-medium text-primary">{{ $fmt($target) }}{{ $panel['satuan'] !== null ? ' '.$panel['satuan'] : '' }}</span>
                                            </div>
                                            <div class="mt-1 h-2 w-full rounded bg-slate-100">
                                                <div class="h-2 rounded bg-slate-300" style="width: {{ round($targetWidth, 2) }}%"></div>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="flex justify-between text-gray-600">
                                                <span>Realisasi</span>
                                                <span class="font-medium text-primary">{{ $fmt($realisasi) }}{{ $panel['satuan'] !== null ? ' '.$panel['satuan'] : '' }}</span>
                                            </div>
                                            <div class="mt-1 h-2 w-full rounded bg-slate-100">
                                                <div class="h-2 rounded bg-accent" style="width: {{ round($realisasiWidth, 2) }}%"></div>
                                            </div>
                                        </div>
                                    </div>
                                @elseif ($panel['mode'] === 'date')
                                    <div class="mt-3 space-y-1 text-xs text-gray-600">
                                        <div class="flex justify-between">
                                            <span>Target</span>
                                            <span class="font-medium text-primary">{{ $panel['date']['target'] ?? '—' }}</span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span>Realisasi</span>
                                            <span class="font-medium text-primary">{{ $panel['date']['realisasi'] ?? '—' }}</span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span>Selisih</span>
                                            <span class="font-medium text-primary">
                                                @if ($panel['date']['delta_days'] === null)
                                                    —
                                                @else
                                                    {{ $panel['date']['delta_days'] }} hari ({{ $panel['date']['delta_days'] <= 0 ? 'lebih cepat' : 'lebih lambat' }})
                                                @endif
                                            </span>
                                        </div>
                                    </div>
                                @endif

                                @if ($panel['score'] !== null)
                                    <p class="mt-3 text-xs text-gray-600">
                                        Skor
                                        @if ($panel['score']['scale'] === 'unverified')
                                            <span class="ml-1 rounded bg-yellow-100 px-2 py-0.5 font-medium text-yellow-800">Skor tidak terverifikasi</span>
                                        @else
                                            <span class="ml-1 font-semibold text-primary">{{ $fmtPercent($panel['score']['percent']) }}</span>
                                            <span class="text-gray-400">({{ $panel['score']['scale'] === 'ratio' ? 'rasio x 100' : 'persen sumber' }})</span>
                                        @endif
                                    </p>
                                @endif

                                @if (! empty($panel['period_conflict']))
                                    <p class="mt-2 text-xs text-yellow-800">Periode banner sheet berbeda dari periode upload.</p>
                                @endif
                            </article>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</div>
