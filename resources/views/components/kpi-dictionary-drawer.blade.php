@php
    $dict = \App\Support\KpiScoreNormalizer::dictionaryMeta();
@endphp

<div
    id="kpi-dictionary-drawer"
    x-data="{
        open: false,
        previousFocus: null,
        openDrawer() {
            this.previousFocus = document.activeElement;
            this.open = true;
            this.$nextTick(() => this.$refs.closeBtn?.focus());
        },
        closeDrawer() {
            if (!this.open) return;
            this.open = false;
            this.$nextTick(() => this.previousFocus?.focus?.());
        }
    }"
    @open-kpi-dictionary.window="openDrawer()"
    @keydown.escape.window="closeDrawer()"
    x-show="open"
    x-cloak
    class="fixed inset-0 z-50 overflow-hidden"
    role="dialog"
    aria-modal="true"
    aria-labelledby="kpi-dictionary-title"
>
    <div
        x-show="open"
        x-transition:enter="transition-opacity ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition-opacity ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        @click="closeDrawer()"
        class="fixed inset-0 bg-slate-900/35 overscroll-contain"
        aria-hidden="true"
    ></div>

    <div class="fixed inset-y-0 right-0 flex w-full max-w-md pl-4 sm:max-w-lg sm:pl-12">
        <div
            x-show="open"
            x-transition:enter="transform transition ease-out duration-250"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transform transition ease-in duration-200"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            class="flex w-full flex-col border-l border-slate-200/90 bg-white shadow-xl overscroll-contain"
        >
            {{-- Header --}}
            <div class="flex items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-[#0b6175]">Pedoman Metodologi</p>
                    <h2 id="kpi-dictionary-title" class="mt-0.5 text-base font-bold text-slate-900">Kamus KPI</h2>
                    <p class="mt-0.5 text-xs text-slate-500">Acuan resmi perhitungan polaritas, batas skor, dan kategori status capaian.</p>
                </div>
                <button
                    type="button"
                    x-ref="closeBtn"
                    @click="closeDrawer()"
                    aria-label="Tutup Kamus KPI"
                    class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-slate-200/80 bg-white text-slate-500 transition hover:bg-slate-50 hover:text-slate-900 cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M18 6 6 18M6 6l12 12" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </div>

            {{-- Body --}}
            <div class="flex-1 overflow-y-auto overscroll-contain px-5 py-4 space-y-5">
                {{-- 1. Rumus Polaritas --}}
                <section aria-labelledby="kamus-polaritas-heading" class="space-y-2.5">
                    <h3 id="kamus-polaritas-heading" class="text-xs font-semibold uppercase tracking-wider text-slate-600">
                        Rumus Arah Polaritas
                    </h3>
                    @foreach ($dict['polarities'] as $polarity)
                        <div class="rounded-xl border border-slate-200/80 bg-slate-50/60 p-3.5 space-y-2">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="text-sm font-bold text-slate-900">{{ $polarity['title'] }}</span>
                                <span class="inline-flex items-center rounded-full bg-[#e6f4f6] px-2.5 py-0.5 text-[11px] font-semibold text-[#0b6175]">
                                    {{ $polarity['badge'] }}
                                </span>
                            </div>
                            <div class="rounded-lg border border-slate-200/80 bg-white px-3 py-2 text-xs font-semibold text-slate-900 tabular-nums">
                                {{ $polarity['formula'] }}
                            </div>
                            <p class="text-xs leading-relaxed text-slate-600">{{ $polarity['summary'] }}</p>
                            <p class="text-[11px] text-slate-500">{{ $polarity['example'] }}</p>
                        </div>
                    @endforeach
                </section>

                {{-- 2. Aturan Pembatasan Skor Maksimal --}}
                <section aria-labelledby="kamus-capping-heading" class="space-y-2">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 id="kamus-capping-heading" class="text-xs font-semibold uppercase tracking-wider text-slate-600">
                            Pembatasan Skor Maksimal
                        </h3>
                        <span class="inline-flex items-center rounded-full border border-[#0b6175]/20 bg-[#e6f4f6] px-2.5 py-0.5 text-[11px] font-semibold text-[#0b6175] tabular-nums">
                            Rentang {{ $dict['score_min_label'] }} s.d. {{ $dict['score_max_label'] }}
                        </span>
                    </div>
                    <div class="rounded-xl border border-slate-200/80 bg-white p-3.5 text-xs leading-relaxed text-slate-600">
                        Nilai pencapaian setiap indikator dan skor rata-rata Nilai Kinerja Organisasi (NKO) dibatasi paling tinggi <strong class="font-semibold text-slate-900 tabular-nums">{{ $dict['score_max_label'] }}</strong> dan paling rendah <strong class="font-semibold text-slate-900 tabular-nums">{{ $dict['score_min_label'] }}</strong> agar lonjakan ekstrem pada satu indikator tidak mendistorsi capaian keseluruhan unit.
                    </div>
                </section>

                {{-- 3. Ambang Kategori Status Capaian --}}
                <section aria-labelledby="kamus-status-heading" class="space-y-2.5">
                    <h3 id="kamus-status-heading" class="text-xs font-semibold uppercase tracking-wider text-slate-600">
                        Ambang Kategori Status Capaian
                    </h3>
                    <div class="divide-y divide-slate-100 rounded-xl border border-slate-200/80 bg-white">
                        @foreach ($dict['tiers'] as $tier)
                            @php
                                $dotClass = match ($tier['key']) {
                                    \App\Support\KpiScoreNormalizer::TIER_TERCAPAI => 'bg-emerald-500',
                                    \App\Support\KpiScoreNormalizer::TIER_HAMPIR => 'bg-amber-500',
                                    default => 'bg-rose-500',
                                };
                                $badgeClass = match ($tier['key']) {
                                    \App\Support\KpiScoreNormalizer::TIER_TERCAPAI => 'border-emerald-200 bg-emerald-50 text-emerald-800',
                                    \App\Support\KpiScoreNormalizer::TIER_HAMPIR => 'border-amber-200 bg-amber-50 text-amber-800',
                                    default => 'border-rose-200 bg-rose-50 text-rose-800',
                                };
                            @endphp
                            <div class="p-3.5 space-y-1">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="inline-flex items-center gap-2 text-xs font-bold text-slate-900">
                                        <span class="h-2 w-2 rounded-full {{ $dotClass }} shrink-0" aria-hidden="true"></span>
                                        <span>{{ $tier['label'] }}</span>
                                    </span>
                                    <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold tabular-nums {{ $badgeClass }}">
                                        {{ $tier['rule'] }}
                                    </span>
                                </div>
                                <p class="text-xs leading-relaxed text-slate-600">{{ $tier['description'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </section>
            </div>

            {{-- Footer --}}
            <div class="flex items-center justify-between gap-3 border-t border-slate-100 bg-slate-50/60 px-5 py-3">
                <span class="text-[11px] text-slate-500">Mode baca saja sesuai aturan sistem aktif</span>
                <button
                    type="button"
                    @click="closeDrawer()"
                    class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3.5 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 hover:text-slate-900 cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]"
                >
                    Tutup
                </button>
            </div>
        </div>
    </div>
</div>
