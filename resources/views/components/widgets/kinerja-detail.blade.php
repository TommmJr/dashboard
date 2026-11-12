@props(['widget' => [], 'data' => null])

{{-- Widget detail metrik KPI per unit/indikator (server-side).
     Modern Looker Studio-style data table dengan inline achievement progress bars.
     Data dihitung dari laporan KPI untuk periode terpilih. --}}
@php
    $period = $data['period'] ?? null;
    $rows = collect($data['rows'] ?? []);
    $truncated = (bool) ($data['truncated'] ?? false);
    $totalRows = (int) ($data['total_rows'] ?? 0);

    $widgetLimit = (int) ($widget['limit'] ?? 10);
    $showAll = request()->boolean('all') || request()->boolean('show_all') || $widgetLimit >= PHP_INT_MAX;
    $limit = $showAll ? PHP_INT_MAX : $widgetLimit;
    $isLimited = ! $showAll && $rows->count() > $limit;
    $displayRows = $isLimited ? $rows->take($limit) : $rows;
    $currentRouteName = request()->routeIs('dashboard.kpi-detail') ? 'dashboard.kpi-detail' : 'dashboard';

    // Daftar SEMUA unit di data (termasuk yang barisnya terpotong cap 50),
    // dipakai untuk dropdown pemilih unit. Payload reader tidak ditambah key.
    $unitOptions = array_values(array_filter(
        (array) ($widget['units'] ?? []),
        fn ($unit): bool => is_string($unit) && $unit !== '',
    ));

    // Filter yang sedang aktif dibaca langsung dari request: komponen ini
    // hanya menerima `widget` + `data`, bukan variabel $filters halaman.
    $startDate = request('start_date');
    $endDate = request('end_date');
    $requestedUnit = request('unit');

    // Unit terpilih hanya bila ada di daftar; kalau tidak, tidak ada highlight
    // (perilaku default tetap berlaku).
    $selectedUnit = is_string($requestedUnit) && in_array($requestedUnit, $unitOptions, true)
        ? $requestedUnit
        : null;

    // ponytail: format Indonesia (desimal koma, ribuan titik). Nilai bulat tampil
    // bulat (100,00 → 100, 100,0% → 100%); pecahan asli tetap maks 2 desimal
    // (105,50 → 105,5).
    $fmt = function ($value, int $decimals, string $suffix = ''): string {
        if ($value === null) {
            return '—';
        }

        $formatted = number_format((float) $value, $decimals, ',', '.');

        if (str_contains($formatted, ',')) {
            $formatted = rtrim(rtrim($formatted, '0'), ',');
        }

        return $formatted.$suffix;
    };
@endphp

<div
    class="h-full rounded-xl bg-white border border-slate-200/80 shadow-xs p-5 sm:p-6 transition-all duration-200 ease-out hover:shadow-md"
    x-data="{
        tableSearch: '',
        tableRag: 'all',
        matches(indikator, unit, tier) {
            return (this.tableRag === 'all' || this.tableRag === tier) &&
                (this.tableSearch.trim() === '' || (indikator + ' ' + unit).toLowerCase().includes(this.tableSearch.trim().toLowerCase()));
        }
    }"
>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="text-lg font-bold text-primary">{{ $widget['label'] ?? 'Detail Metrik KPI' }}</h3>
            <p class="mt-1 text-xs text-gray-500">Periode {{ $period ?? '—' }}</p>
        </div>

        @if (! empty($unitOptions))
            {{-- Form GET: halaman dimuat ulang supaya widget server-side lain
                 (kinerja-realisasi, target vs realisasi) ikut unit yang sama.
                 Filter tanggal dibawa agar tidak hilang saat pindah unit. --}}
            <form method="GET" action="{{ route($currentRouteName) }}" class="flex flex-wrap items-end gap-2">
                @if (is_string($startDate) && $startDate !== '')
                    <input type="hidden" name="start_date" value="{{ $startDate }}">
                @endif
                @if (is_string($endDate) && $endDate !== '')
                    <input type="hidden" name="end_date" value="{{ $endDate }}">
                @endif
                <label for="kinerja-detail-unit" class="sr-only">Pilih unit</label>
                <select id="kinerja-detail-unit" name="unit" onchange="this.form.submit()"
                        class="h-9 rounded border border-slate-300 bg-white px-2 text-sm text-gray-700">
                    <option value="">Semua unit</option>
                    @foreach ($unitOptions as $unitOption)
                        <option value="{{ $unitOption }}" @selected($selectedUnit === $unitOption)>{{ $unitOption }}</option>
                    @endforeach
                </select>
                <noscript>
                    <button type="submit" class="h-9 rounded border border-slate-300 bg-white px-3 text-sm text-gray-700">Tampilkan</button>
                </noscript>
                {{-- Jalan pintas supaya pembaca non-developer langsung menjangkau
                     TJBT (di urutan pertama daftar). --}}
                @php
                    $primaryUnit = !empty(config('kpi.visible_units')) ? config('kpi.visible_units')[0] : 'TJBT';
                @endphp
                <a href="{{ route($currentRouteName, array_filter([
                        'unit' => $primaryUnit,
                        'start_date' => is_string($startDate) ? $startDate : null,
                        'end_date' => is_string($endDate) ? $endDate : null,
                    ])) }}"
                   class="inline-flex h-9 items-center rounded border border-primary px-3 text-sm font-medium text-primary hover:bg-primary hover:text-white">
                    {{ $primaryUnit }}
                </a>
            </form>
        @endif
    </div>

    @if ($rows->isEmpty())
        <p class="mt-4 rounded-lg bg-slate-50 p-5 text-sm text-gray-600">
            Belum ada data metrik pada periode ini.
        </p>
    @else
        {{-- Toolbar pencarian cepat & filter RAG --}}
        <div class="mt-4 flex flex-col gap-2.5 rounded-lg border border-slate-200/80 bg-slate-50/70 p-2.5 sm:flex-row sm:items-center sm:justify-between">
            <div class="relative flex-1 max-w-xs">
                <input
                    id="kinerja-detail-search"
                    type="search"
                    x-model="tableSearch"
                    placeholder="Cari indikator atau unit..."
                    aria-label="Cari indikator atau unit"
                    class="w-full rounded-md border border-slate-300 bg-white py-1.5 pl-3 pr-3 text-xs text-slate-800 placeholder:text-slate-400 focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                />
            </div>
            <div class="flex flex-wrap items-center gap-1.5 text-[11px]">
                <button
                    type="button"
                    @click="tableRag = 'all'"
                    :class="tableRag === 'all' ? 'border-slate-800 text-slate-900 font-bold ring-1 ring-slate-800/15 shadow-2xs' : 'border-slate-200 text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50 hover:text-slate-800'"
                    class="inline-flex items-center gap-1.5 cursor-pointer rounded-md border bg-white px-2.5 py-1 transition"
                >
                    <span class="h-1.5 w-1.5 rounded-full bg-slate-400 shrink-0"></span>
                    Semua
                </button>
                <button
                    type="button"
                    @click="tableRag = 'tercapai'"
                    :class="tableRag === 'tercapai' ? 'border-emerald-600 text-emerald-950 font-bold ring-1 ring-emerald-600/20 shadow-2xs' : 'border-slate-200 text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50 hover:text-slate-800'"
                    class="inline-flex items-center gap-1.5 cursor-pointer rounded-md border bg-white px-2.5 py-1 transition"
                >
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 shrink-0"></span>
                    Tercapai
                </button>
                <button
                    type="button"
                    @click="tableRag = 'hampir'"
                    :class="tableRag === 'hampir' ? 'border-amber-600 text-amber-950 font-bold ring-1 ring-amber-600/20 shadow-2xs' : 'border-slate-200 text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50 hover:text-slate-800'"
                    class="inline-flex items-center gap-1.5 cursor-pointer rounded-md border bg-white px-2.5 py-1 transition"
                >
                    <span class="h-1.5 w-1.5 rounded-full bg-amber-500 shrink-0"></span>
                    Mendekati
                </button>
                <button
                    type="button"
                    @click="tableRag = 'perlu'"
                    :class="tableRag === 'perlu' ? 'border-rose-600 text-rose-950 font-bold ring-1 ring-rose-600/20 shadow-2xs' : 'border-slate-200 text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50 hover:text-slate-800'"
                    class="inline-flex items-center gap-1.5 cursor-pointer rounded-md border bg-white px-2.5 py-1 transition"
                >
                    <span class="h-1.5 w-1.5 rounded-full bg-rose-500 shrink-0"></span>
                    Perlu Perhatian
                </button>
            </div>
        </div>

        @if ($isLimited)
            <div class="mt-2 text-[11px] text-slate-500">
                Menampilkan 10 baris teratas.@if (\App\Http\Controllers\DashboardController::canAccessKpiDetail(auth()->user())) Untuk mencari seluruh indikator, buka <a href="{{ route('dashboard.kpi-detail') }}" class="text-accent underline font-medium">Halaman Rincian KPI</a>.@endif
            </div>
        @endif

        <div class="overflow-x-auto mt-3 rounded-lg border border-slate-200">
            <table class="w-full text-sm">
                <thead class="bg-slate-50/80 text-xs text-slate-600 font-semibold uppercase tracking-wider border-b border-slate-200">
                    <tr>
                        <th class="py-2.5 px-3 text-left">Unit</th>
                        <th class="py-2.5 px-3 text-left">Indikator</th>
                        <th class="py-2.5 px-3 text-right">Target</th>
                        <th class="py-2.5 px-3 text-right">Realisasi</th>
                        <th class="py-2.5 px-3 text-right">Pencapaian %</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($displayRows as $row)
                        @php
                            $pencapaianVal = $row['pencapaian'] ?? null;
                            $barWidth = 0;
                            $barColor = 'bg-slate-300';
                            $tier = 'all';
                            if ($pencapaianVal !== null) {
                                $numVal = (float) $pencapaianVal;
                                $barWidth = max(0, min(100, $numVal));
                                $tier = \App\Support\KpiScoreNormalizer::ragTier($numVal);
                                if ($tier === \App\Support\KpiScoreNormalizer::TIER_TERCAPAI) {
                                    $barColor = 'bg-emerald-500';
                                } elseif ($tier === \App\Support\KpiScoreNormalizer::TIER_HAMPIR) {
                                    $barColor = 'bg-amber-500';
                                } else {
                                    $barColor = 'bg-rose-500';
                                }
                            }
                        @endphp
                        <tr
                            x-show="matches('{{ addcslashes($row['indikator'], "'\\") }}', '{{ addcslashes($row['unit'], "'\\") }}', '{{ $tier }}')"
                            class="hover:bg-slate-50/60 transition-colors"
                        >
                            <td class="py-2.5 px-3 font-semibold text-primary">{{ $row['unit'] }}</td>
                            <td class="py-2.5 px-3 text-slate-800">{{ $row['indikator'] }}</td>
                            <td class="py-2.5 px-3 text-right font-medium text-slate-600">{{ $row['target_display'] ?? $fmt($row['target'] ?? null, 2) }}</td>
                            <td class="py-2.5 px-3 text-right font-medium text-slate-600">{{ $row['realisasi_display'] ?? $fmt($row['realisasi'] ?? null, 2) }}</td>
                            <td class="py-2.5 px-3 text-right">
                                <div class="flex flex-col items-end">
                                    <span class="font-medium text-slate-800">{{ $fmt($pencapaianVal, 1, '%') }}</span>
                                    @if ($pencapaianVal !== null)
                                        <div class="mt-1 h-1.5 w-16 sm:w-20 rounded-full bg-slate-100 overflow-hidden">
                                            <div class="h-full rounded-full {{ $barColor }}" style="width: {{ round($barWidth, 1) }}%"></div>
                                        </div>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @php
            $seeAllParams = array_filter([
                'unit' => $selectedUnit,
                'start_date' => is_string($startDate) ? $startDate : null,
                'end_date' => is_string($endDate) ? $endDate : null,
                'all' => $showAll ? null : 1,
            ]);
        @endphp
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
            <span>
                Menampilkan {{ $displayRows->count() }} dari {{ $totalRows > 0 ? $totalRows : $rows->count() }} baris.
            </span>
            <div class="flex flex-wrap items-center gap-3">
                @if ($isLimited)
                    <a href="{{ route($currentRouteName, $seeAllParams) }}#kinerja-detail" class="font-semibold text-primary hover:underline inline-flex items-center gap-1">
                        Lihat semua ({{ $totalRows > 0 ? $totalRows : $rows->count() }} baris) &rarr;
                    </a>
                @elseif (! request()->routeIs('dashboard.kpi-detail') && $showAll && $rows->count() > 10)
                    <a href="{{ route($currentRouteName, $seeAllParams) }}#kinerja-detail" class="font-semibold text-primary hover:underline inline-flex items-center gap-1">
                        Tampilkan ringkas (10 baris) &rarr;
                    </a>
                @endif
                @if (! request()->routeIs('dashboard.kpi-detail') && \App\Http\Controllers\DashboardController::canAccessKpiDetail(auth()->user()))
                    <a href="{{ route('dashboard.kpi-detail', array_filter([
                        'unit' => $selectedUnit,
                        'start_date' => is_string($startDate) ? $startDate : null,
                        'end_date' => is_string($endDate) ? $endDate : null,
                    ])) }}" class="font-semibold text-teal-700 hover:underline inline-flex items-center gap-1">
                        Halaman Rincian KPI &rarr;
                    </a>
                @endif
                @if (\App\Http\Controllers\KpiAnalyticsController::canAccessKpiAnalytics(auth()->user()))
                    <a href="{{ route('dashboard.kpi-analytics') }}" class="font-semibold text-teal-700 hover:underline inline-flex items-center gap-1">
                        Analitik & Bottleneck &rarr;
                    </a>
                @endif
            </div>
        </div>
    @endif
</div>
