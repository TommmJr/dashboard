@props(['widget' => [], 'data' => null])

{{-- Widget peringkat NKO per unit (server-side).
     Data dihitung DashboardController::nkoRanking() untuk periode terbaru.
     Kategori mengikuti legenda laporan KPI: Tercapai >= 100,
     Hampir Tercapai 95 s.d. 100, Perlu Peningkatan < 95. --}}
@php
    $rows = collect($data ?? []);

    $category = function (float $nko): array {
        return match (\App\Support\KpiScoreNormalizer::ragTier($nko)) {
            \App\Support\KpiScoreNormalizer::TIER_TERCAPAI => ['tercapai', 'Tercapai', 'border border-emerald-200 bg-emerald-50 text-emerald-800', 'bg-emerald-500'],
            \App\Support\KpiScoreNormalizer::TIER_HAMPIR => ['hampir', 'Hampir Tercapai', 'border border-amber-200 bg-amber-50 text-amber-800', 'bg-amber-500'],
            default => ['perlu', 'Perlu Peningkatan', 'border border-rose-200 bg-rose-50 text-rose-800', 'bg-rose-500'],
        };
    };
@endphp

<div class="ibs-card card-interactive flex h-full flex-col justify-between p-5">
    <div>
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[#e6f4f6] text-[#0b6175]">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6M18 9h1.5a2.5 2.5 0 0 0 0-5H18" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M4 22h16M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22M14 14.66V17c0 .55.47.98.97 1.21 1.18.54 2.03 2.03 2.03 3.79M18 2H6v7a6 6 0 0 0 12 0V2Z" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>
                <div>
                    <h3 class="ibs-section-title text-base font-bold text-slate-900">{{ $widget['label'] ?? 'Peringkat NKO' }}</h3>
                </div>
            </div>
            <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold text-slate-600">Periode Terkini</span>
        </div>

        @if ($rows->isEmpty())
            <p class="mt-4 rounded-xl border border-slate-200/70 bg-slate-50 p-5 text-sm text-slate-600">
                Belum ada data NKO. Konversi laporan KPI lalu unggah berkasnya lewat menu Upload Dataset.
            </p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                            <th class="py-2.5 pl-1 pr-2">#</th>
                            <th class="py-2.5 pr-2.5">Unit</th>
                            <th class="py-2.5 pr-2.5 hidden 2xl:table-cell">Progres</th>
                            <th class="py-2.5 pr-2.5 text-right" title="Nilai Kinerja Organisasi">NKO</th>
                            <th class="py-2.5 text-right">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100/80">
                        @foreach ($rows as $index => $row)
                            @php
                                $nkoVal = (float) $row->nko;
                                [$tier, $label, $classes, $barColor] = $category($nkoVal);
                                $nkoFormatted = number_format($nkoVal, 2, ',', '.');
                                $barWidth = max(0, min(100, round(($nkoVal / 110) * 100, 1)));
                            @endphp
                            <tr class="transition-colors hover:bg-slate-50/70">
                                <td class="py-2.5 pl-1 pr-2 text-xs font-bold tabular-nums text-slate-400">{{ $index + 1 }}</td>
                                <td class="py-2.5 pr-2.5 font-semibold text-slate-800">{{ $row->unit }}</td>
                                <td class="py-2.5 pr-2.5 hidden 2xl:table-cell">
                                    <div class="h-1.5 w-16 rounded-full bg-slate-100 overflow-hidden">
                                        <div class="h-full rounded-full {{ $barColor }}" style="width: {{ $barWidth }}%"></div>
                                    </div>
                                </td>
                                <td class="py-2.5 pr-2.5 text-right font-bold text-[#0b6175] tabular-nums">
                                    <span x-data="numberTicker('{{ $nkoFormatted }}')" x-text="display">{{ $nkoFormatted }}</span>
                                </td>
                                <td class="py-2.5 text-right">
                                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium whitespace-nowrap {{ $classes }}">
                                        @if ($tier === 'tercapai')
                                            <svg class="h-3.5 w-3.5 shrink-0 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                <path d="m9 12 2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                        @elseif ($tier === 'hampir')
                                            <svg class="h-3.5 w-3.5 shrink-0 text-amber-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                <circle cx="12" cy="12" r="9"/>
                                                <path d="M12 7v5l3 2" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                        @else
                                            <svg class="h-3.5 w-3.5 shrink-0 text-rose-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                <path d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                        @endif
                                        <span>{{ $label }}</span>
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
