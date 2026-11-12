@props(['widget' => [], 'data' => null])

{{-- Widget peringkat NKO per unit (server-side).
     Data dihitung DashboardController::nkoRanking() untuk periode terbaru.
     Kategori mengikuti legenda laporan KPI: Tercapai >= 100,
     Hampir Tercapai 95-100, Perlu Peningkatan < 95. --}}
@php
    $rows = collect($data ?? []);

    $category = function (float $nko): array {
        return match (\App\Support\KpiScoreNormalizer::ragTier($nko)) {
            \App\Support\KpiScoreNormalizer::TIER_TERCAPAI => ['Tercapai', 'bg-green-100 text-green-800'],
            \App\Support\KpiScoreNormalizer::TIER_HAMPIR => ['Hampir Tercapai', 'bg-yellow-100 text-yellow-800'],
            default => ['Perlu Peningkatan', 'bg-red-100 text-red-800'],
        };
    };
@endphp

<div class="card-interactive flex h-full flex-col justify-between rounded-xl border border-slate-200/80 bg-white p-6 shadow-[0_1px_3px_0_rgba(0,0,0,0.04)]">
    <div>
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-teal-50 text-accent">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6M18 9h1.5a2.5 2.5 0 0 0 0-5H18" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M4 22h16M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22M14 14.66V17c0 .55.47.98.97 1.21 1.18.54 2.03 2.03 2.03 3.79M18 2H6v7a6 6 0 0 0 12 0V2Z" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>
                <h3 class="text-lg font-bold text-primary">{{ $widget['label'] ?? 'Peringkat NKO Unit' }}</h3>
            </div>
            <span class="text-xs font-medium text-slate-400">Periode Terkini</span>
        </div>

        @if ($rows->isEmpty())
            <p class="mt-4 rounded-lg bg-slate-50 p-5 text-sm text-gray-600">
                Belum ada data NKO. Konversi laporan KPI lalu unggah berkasnya lewat menu Upload Dataset.
            </p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                            <th class="py-2.5 pl-1 pr-3">#</th>
                            <th class="py-2.5 pr-4">Unit</th>
                            <th class="py-2.5 pr-4 text-right">NKO</th>
                            <th class="py-2.5 text-right">Kategori</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100/80">
                        @foreach ($rows as $index => $row)
                            @php
                                [$label, $classes] = $category((float) $row->nko);
                                $nkoFormatted = number_format((float) $row->nko, 2, ',', '.');
                            @endphp
                            <tr class="transition-colors hover:bg-slate-50/70">
                                <td class="py-2.5 pl-1 pr-3 text-xs font-bold text-slate-400">{{ $index + 1 }}</td>
                                <td class="py-2.5 pr-4 font-semibold text-slate-800">{{ $row->unit }}</td>
                                <td class="py-2.5 pr-4 text-right font-bold text-primary tabular-nums">
                                    <span x-data="numberTicker('{{ $nkoFormatted }}')" x-text="display">{{ $nkoFormatted }}</span>
                                </td>
                                <td class="py-2.5 text-right">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $classes }}">{{ $label }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
