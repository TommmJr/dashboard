@props(['widget' => [], 'data' => null, 'embeddedInKpiDetail' => false])

{{-- Widget detail metrik KPI per unit/indikator (server-side).
     Quiet Luxury Financial Ledger table dengan inline achievement micro-bars.
     Data dihitung dari laporan KPI untuk periode terpilih. --}}
@php
    $isEmbedded = (bool) ($embeddedInKpiDetail ?? false);
    $instanceId = $widget['instance'] ?? 0;
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
    $fmt = fn ($value, int $decimals = 1, string $suffix = ''): string => \App\Support\KpiScoreNormalizer::formatNumber(
        $value !== null ? (float) $value : null,
        $decimals,
        $suffix,
    );

    $rowFilterItems = $displayRows->map(function (array $row): array {
        $skorKpiVal = array_key_exists('skor_kpi', $row) ? $row['skor_kpi'] : ($row['pencapaian'] ?? null);
        $tier = $skorKpiVal !== null
            ? \App\Support\KpiScoreNormalizer::ragTier((float) $skorKpiVal)
            : 'all';

        return [
            'indikator' => (string) ($row['indikator'] ?? ''),
            'unit' => (string) ($row['unit'] ?? ''),
            'tier' => $tier,
        ];
    })->values()->all();
@endphp

<script type="application/json" id="kinerjaDetailItems_{{ $instanceId }}">
{!! json_encode($rowFilterItems) !!}
</script>

<div
    class="ibs-card min-w-0 max-w-full h-full rounded-2xl border border-slate-200/80 bg-white p-5 shadow-[0_1px_3px_0_rgba(15,23,42,0.04),0_4px_12px_-2px_rgba(15,23,42,0.03)] flex flex-col justify-between"
    x-data="{
        tableSearch: '',
        tableRag: 'all',
        rows: JSON.parse(document.getElementById('kinerjaDetailItems_{{ $instanceId }}')?.textContent || '[]'),
        matches(indikator, unit, tier) {
            return (this.tableRag === 'all' || this.tableRag === tier) &&
                (this.tableSearch.trim() === '' || (indikator + ' ' + unit).toLowerCase().includes(this.tableSearch.trim().toLowerCase()));
        },
        get visibleRowCount() {
            return this.rows.filter(r => this.matches(r.indikator, r.unit, r.tier)).length;
        }
    }"
    @kpi-page-filter-changed.window="tableSearch = $event.detail.search ?? ''; tableRag = $event.detail.rag ?? 'all'"
>
    <div class="min-w-0 flex-1 flex flex-col">
    <div class="flex flex-wrap items-center justify-between gap-2 @if ($isEmbedded) pb-3 border-b border-slate-100 @endif">
        @if ($isEmbedded)
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="ibs-section-title text-sm font-bold text-slate-900 tracking-tight">{{ $widget['label'] ?? 'Detail Metrik KPI' }}</h3>
                    <span class="inline-flex items-center rounded-full border border-slate-200/80 bg-slate-50 px-2 py-0.5 text-[11px] font-medium text-slate-600 tabular-nums" x-text="visibleRowCount + ' baris'">{{ $displayRows->count() }} baris</span>
                </div>
                <p class="mt-0.5 text-[11px] text-slate-500">Tampilan matriks komparatif terhadap target</p>
                <span class="sr-only">Periode {{ $period ?? 'Belum tersedia' }}</span>
            </div>
        @else
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="ibs-section-title text-base font-bold text-slate-900">{{ $widget['label'] ?? 'Detail Metrik KPI' }}</h3>
                <span class="inline-flex items-center rounded-full bg-[#e6f4f6] px-2.5 py-0.5 text-[11px] font-semibold text-[#0b6175]">
                    Periode {{ $period ?? 'Belum tersedia' }}
                </span>
            </div>
        @endif

        @if (! $isEmbedded && ! empty($unitOptions))
            {{-- Form GET: halaman dimuat ulang supaya widget server-side lain
                 (kinerja-realisasi, target vs realisasi) ikut unit yang sama.
                 Filter tanggal dibawa agar tidak hilang saat pindah unit. --}}
            <form method="GET" action="{{ route($currentRouteName) }}" class="flex min-w-0 max-w-full flex-wrap items-center gap-2">
                @if (is_string($startDate) && $startDate !== '')
                    <input type="hidden" name="start_date" value="{{ $startDate }}">
                @endif
                @if (is_string($endDate) && $endDate !== '')
                    <input type="hidden" name="end_date" value="{{ $endDate }}">
                @endif
                <label for="kinerja-detail-unit" class="sr-only">Pilih unit</label>
                <select id="kinerja-detail-unit" name="unit" data-auto-submit
                        class="h-8 max-w-full min-w-0 rounded-lg border border-slate-200/80 bg-white px-2.5 text-xs font-medium text-slate-700 focus:border-[#16a3b8] focus:outline-none focus:ring-1 focus:ring-[#16a3b8]">
                    <option value="">Semua unit</option>
                    @foreach ($unitOptions as $unitOption)
                        <option value="{{ $unitOption }}" @selected($selectedUnit === $unitOption)>{{ $unitOption }}</option>
                    @endforeach
                </select>
                <noscript>
                    <button type="submit" class="h-8 rounded-lg border border-slate-200 bg-white px-3 text-xs font-medium text-slate-700">Tampilkan</button>
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
                   class="inline-flex h-8 items-center rounded-lg border border-[#0b6175]/25 bg-white px-3 text-xs font-semibold text-[#0b6175] transition hover:bg-[#e6f4f6]/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0b6175]/40">
                    {{ $primaryUnit }}
                </a>
            </form>
        @endif
    </div>

    @if ($rows->isEmpty())
        <p class="mt-4 rounded-xl border border-slate-200/70 bg-slate-50/70 p-5 text-sm text-slate-600">
            Belum ada data metrik pada periode ini.
        </p>
    @else
        {{-- Toolbar pencarian cepat & filter RAG (disembunyikan secara visual saat embedded di /dashboard/kpi-detail) --}}
        <div @class(['sr-only' => $isEmbedded])>
            <div class="mt-3 flex flex-col gap-2 rounded-xl border border-slate-200/80 bg-slate-50/70 p-2 sm:flex-row sm:items-center sm:justify-between min-w-0">
                <div class="relative flex-1 min-w-0 w-full sm:max-w-xs">
                    <input
                        id="kinerja-detail-search"
                        type="search"
                        x-model="tableSearch"
                        placeholder="Cari indikator atau unit..."
                        aria-label="Cari indikator atau unit"
                        class="w-full rounded-lg border border-slate-200 bg-white py-1.5 pl-3 pr-3 text-xs text-slate-800 placeholder:text-slate-400 focus:border-[#16a3b8] focus:outline-none focus:ring-1 focus:ring-[#16a3b8]"
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
        </div>

        @if ($isLimited)
            <div class="mt-2.5 text-[11px] text-slate-500">
                Menampilkan 10 baris teratas.@if (\App\Http\Controllers\DashboardController::canAccessKpiDetail(auth()->user())) Untuk mencari seluruh indikator, buka <a href="{{ route('dashboard.kpi-detail') }}" class="text-accent underline font-medium">Halaman Rincian KPI</a>.@endif
            </div>
        @endif

        <div class="relative w-full min-w-0 overflow-x-auto mt-3.5 rounded-xl border border-slate-200/80 flex-1">
            <table class="w-full text-xs">
                <thead class="bg-slate-50/80 text-[10px] text-slate-500 font-semibold uppercase tracking-[0.04em] border-b border-slate-200/80">
                    <tr>
                        <th class="py-2.5 px-2 text-left">Unit</th>
                        <th class="py-2.5 px-2.5 text-left">Indikator</th>
                        <th class="py-2.5 px-1.5 text-right">Target</th>
                        <th class="py-2.5 px-1.5 text-right">Realisasi</th>
                        <th class="py-2.5 px-2 text-right leading-tight">Skor Pencapaian KPI (Maks 110%)</th>
                        <th class="py-2.5 px-2 text-right leading-tight">
                            <div class="inline-flex flex-col items-end gap-0.5">
                                <span>Perbandingan Target</span>
                                <span class="inline-flex items-center gap-1.5 text-[9.5px] font-medium normal-case tracking-normal text-slate-400">
                                    <span class="inline-flex items-center gap-1"><span class="inline-block w-2 h-1 rounded-full bg-slate-300"></span>Target</span>
                                    <span class="inline-flex items-center gap-1"><span class="inline-block w-2 h-1 rounded-full bg-emerald-500"></span>Realisasi</span>
                                </span>
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100/90">
                    @foreach ($displayRows as $row)
                        @php
                            $skorKpiVal = array_key_exists('skor_kpi', $row) ? $row['skor_kpi'] : ($row['pencapaian'] ?? null);
                            $barWidth = 0;
                            $barColor = 'bg-slate-300';
                            $statusPillClass = 'border-slate-200 bg-slate-50 text-slate-600';
                            $tier = 'all';
                            if ($skorKpiVal !== null) {
                                $numVal = (float) $skorKpiVal;
                                $barWidth = max(0, min(100, $numVal));
                                $tier = \App\Support\KpiScoreNormalizer::ragTier($numVal);
                                if ($tier === \App\Support\KpiScoreNormalizer::TIER_TERCAPAI) {
                                    $barColor = 'bg-emerald-500';
                                    $statusPillClass = 'border-emerald-200/70 bg-emerald-50 text-emerald-700';
                                } elseif ($tier === \App\Support\KpiScoreNormalizer::TIER_HAMPIR) {
                                    $barColor = 'bg-amber-500';
                                    $statusPillClass = 'border-amber-200/70 bg-amber-50 text-amber-700';
                                } else {
                                    $barColor = 'bg-rose-500';
                                    $statusPillClass = 'border-rose-200/70 bg-rose-50 text-rose-700';
                                }
                            }
                            $cmp = \App\Support\KpiScoreNormalizer::describeComparison([
                                'satuan' => $row['satuan'] ?? null,
                                'polarity' => $row['polarity'] ?? null,
                                'target_type' => $row['target_type'] ?? 'numeric',
                                'realisasi_type' => $row['realisasi_type'] ?? 'numeric',
                                'target' => $row['target'] ?? null,
                                'realisasi' => $row['realisasi'] ?? null,
                                'target_text' => $row['target_text'] ?? null,
                                'realisasi_text' => $row['realisasi_text'] ?? null,
                                'target_date' => $row['target_date'] ?? null,
                                'realisasi_date' => $row['realisasi_date'] ?? null,
                                'skor_kpi' => $skorKpiVal,
                                'tier' => $tier !== 'all' ? $tier : 'unverified',
                            ]);
                            $targetDisplay = $row['target_display'] ?? $cmp['target_override'] ?? $fmt($row['target'] ?? null, 1);
                            $realisasiDisplay = $row['realisasi_display'] ?? $cmp['realisasi_override'] ?? $fmt($row['realisasi'] ?? null, 1);
                            $skorDisplay = $fmt($skorKpiVal, 1, '%');
                            $realisasiCompareColor = $skorKpiVal !== null ? $barColor : 'bg-[#0b6175]';
                        @endphp
                        <tr
                            x-show="matches('{{ addcslashes($row['indikator'], "'\\") }}', '{{ addcslashes($row['unit'], "'\\") }}', '{{ $tier }}')"
                            class="hover:bg-slate-50/75 transition-colors group"
                        >
                            <td class="py-2.5 px-2 whitespace-nowrap align-top">
                                <span class="inline-flex items-center rounded-full border border-slate-200/60 bg-slate-100/90 px-1.5 py-0.5 text-[10.5px] font-semibold text-slate-700">{{ $row['unit'] }}</span>
                            </td>
                            <td class="py-2.5 px-3 text-slate-800">{{ $row['indikator'] }}@if (! empty($cmp['indicator_hint']))<div class="mt-0.5 text-[10.5px] leading-tight text-slate-400">{{ $cmp['indicator_hint'] }}</div>@endif</td>
                            <td class="py-2.5 px-1.5 text-right tabular-nums align-top">
                                <div class="{{ $targetDisplay === 'Tidak tercatat' ? 'text-xs font-normal text-slate-400' : 'font-medium text-slate-600' }}">{{ $targetDisplay }}</div>
                                @if (! empty($cmp['target_hint']) && $targetDisplay !== 'Tidak tercatat')
                                    <div class="mt-0.5 text-[10px] leading-tight text-slate-400">{{ $cmp['target_hint'] }}</div>
                                @endif
                            </td>
                            <td class="py-2.5 px-1.5 text-right tabular-nums align-top">
                                <div class="{{ $realisasiDisplay === 'Tidak tercatat' ? 'text-xs font-normal text-slate-400' : 'font-semibold text-slate-900' }}">{{ $realisasiDisplay }}</div>
                                @if (! empty($cmp['realisasi_hint']) && $realisasiDisplay !== 'Tidak tercatat')
                                    <div class="mt-0.5 text-[10px] leading-tight text-slate-400">{{ $cmp['realisasi_hint'] }}</div>
                                @endif
                            </td>
                            <td class="py-2.5 px-2 text-right align-top">
                                <div class="flex flex-col items-end">
                                    <span class="tabular-nums {{ $skorDisplay === 'Tidak tercatat' ? 'text-xs font-normal text-slate-400' : 'font-bold text-slate-900' }}">{{ $skorDisplay }}</span>
                                    @if ($skorKpiVal !== null)
                                        <div class="mt-1.5 h-1.5 w-14 rounded-full bg-slate-100 overflow-hidden">
                                            <div class="h-full rounded-full {{ $barColor }}" style="width: {{ round($barWidth, 1) }}%"></div>
                                        </div>
                                    @endif
                                </div>
                            </td>
                            <td class="py-2.5 px-2 text-right align-top">
                                @if ($cmp['target_bar_pct'] !== null && $cmp['realisasi_bar_pct'] !== null)
                                    <div class="inline-flex flex-col items-end gap-1">
                                        <div dir="ltr" class="w-16 space-y-1">
                                            <div class="h-1.5 w-full rounded-full bg-slate-100 overflow-hidden" title="Target: {{ $targetDisplay }}">
                                                <div class="h-full rounded-full bg-slate-300" style="width: {{ $cmp['target_bar_pct'] }}%"></div>
                                            </div>
                                            <div class="h-1.5 w-full rounded-full bg-slate-100 overflow-hidden" title="Realisasi: {{ $realisasiDisplay }}">
                                                <div class="h-full rounded-full {{ $realisasiCompareColor }}" style="width: {{ $cmp['realisasi_bar_pct'] }}%"></div>
                                            </div>
                                        </div>
                                        <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold leading-tight text-right tabular-nums {{ $statusPillClass }}">{{ $cmp['diff_label'] }}</span>
                                    </div>
                                @else
                                    <span class="text-xs font-normal text-slate-400">{{ $cmp['diff_label'] }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    @if ($isEmbedded)
                        <tr x-show="visibleRowCount === 0" x-cloak>
                            <td colspan="6" class="py-8 px-4 text-center text-xs text-slate-500">
                                Tidak ada indikator yang sesuai dengan filter.
                            </td>
                        </tr>
                    @endif
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
        <div class="mt-3.5 pt-3 border-t border-slate-100 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
            <span class="tabular-nums">
                Menampilkan {{ $displayRows->count() }} dari {{ $totalRows > 0 ? $totalRows : $rows->count() }} baris.
            </span>
            <div class="flex flex-wrap items-center gap-3">
                @if ($isLimited)
                    <a href="{{ route($currentRouteName, $seeAllParams) }}#kinerja-detail" class="font-semibold text-[#0b6175] hover:underline inline-flex items-center gap-1">
                        Lihat semua ({{ $totalRows > 0 ? $totalRows : $rows->count() }} baris) &rarr;
                    </a>
                @elseif (! request()->routeIs('dashboard.kpi-detail') && $showAll && $rows->count() > 10)
                    <a href="{{ route($currentRouteName, $seeAllParams) }}#kinerja-detail" class="font-semibold text-[#0b6175] hover:underline inline-flex items-center gap-1">
                        Tampilkan ringkas (10 baris) &rarr;
                    </a>
                @endif
                @if (! request()->routeIs('dashboard.kpi-detail') && \App\Http\Controllers\DashboardController::canAccessKpiDetail(auth()->user()))
                    <a href="{{ route('dashboard.kpi-detail', array_filter([
                        'unit' => $selectedUnit,
                        'start_date' => is_string($startDate) ? $startDate : null,
                        'end_date' => is_string($endDate) ? $endDate : null,
                    ])) }}" class="font-semibold text-[#0b6175] hover:underline inline-flex items-center gap-1">
                        Halaman Rincian KPI &rarr;
                    </a>
                @endif
            </div>
        </div>
    @endif
    </div>
</div>
