@props(['widget' => [], 'data' => null])

@php
    $canMutateAnnotations = \App\Services\KpiPeriodAnnotationService::canMutate(auth()->user());
@endphp

<div class="ibs-card flex h-full flex-col justify-between p-5">
    <div>
        {{-- Interactive Header Stage --}}
        <div class="flex flex-wrap items-center justify-between gap-2.5 border-b border-slate-100 pb-3">
            <div class="flex flex-wrap items-center gap-2 min-w-0">
                <h3 class="ibs-section-title text-base font-bold text-slate-900">{{ $widget['label'] ?? 'Tren NKO' }}</h3>
                <span
                    x-show="!loading && periodLabels.length > 0"
                    x-cloak
                    class="inline-flex items-center rounded-full border border-slate-200/80 bg-slate-50 px-2 py-0.5 text-[11px] font-medium text-slate-600 tabular-nums"
                    x-text="periodLabels.length + ' periode'"
                ></span>
            </div>

            <div class="flex flex-wrap items-center gap-1.5">
                {{-- Chart Mode Switcher (tampil bila periode > 1) --}}
                <div
                    x-show="!loading && !empty && periodLabels.length > 1"
                    x-cloak
                    class="inline-flex items-center rounded-xl border border-slate-200/75 bg-slate-100/85 p-0.5 text-[11px]"
                    role="group"
                    aria-label="Mode tampilan grafik tren"
                >
                    <button
                        type="button"
                        @click="setChartStyle('area')"
                        :class="chartStyle === 'area' ? 'bg-white text-[#0b6175] font-semibold shadow-2xs ring-1 ring-slate-900/5' : 'text-slate-600 font-medium hover:text-slate-900'"
                        class="pill-tab-transition rounded-lg px-2 py-0.5 cursor-pointer whitespace-nowrap"
                    >
                        Area
                    </button>
                    <button
                        type="button"
                        @click="setChartStyle('line')"
                        :class="chartStyle === 'line' ? 'bg-white text-[#0b6175] font-semibold shadow-2xs ring-1 ring-slate-900/5' : 'text-slate-600 font-medium hover:text-slate-900'"
                        class="pill-tab-transition rounded-lg px-2 py-0.5 cursor-pointer whitespace-nowrap"
                    >
                        Garis
                    </button>
                    <button
                        type="button"
                        @click="setChartStyle('bar')"
                        :class="chartStyle === 'bar' ? 'bg-white text-[#0b6175] font-semibold shadow-2xs ring-1 ring-slate-900/5' : 'text-slate-600 font-medium hover:text-slate-900'"
                        class="pill-tab-transition rounded-lg px-2 py-0.5 cursor-pointer whitespace-nowrap"
                    >
                        Batang
                    </button>
                </div>

                {{-- Toggle Target 100 Reference Line --}}
                <button
                    type="button"
                    @click="toggleTargetLine()"
                    :aria-pressed="showTargetLine"
                    :class="showTargetLine ? 'border-[#0b6175]/30 bg-[#e6f4f6]/75 text-[#0b6175] font-semibold' : 'border-slate-200 bg-white text-slate-600 font-medium hover:bg-slate-50'"
                    class="pg-control-btn inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-[11px] tabular-nums whitespace-nowrap cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]"
                >
                    <span class="h-1.5 w-1.5 rounded-full" :class="showTargetLine ? 'bg-[#0b6175]' : 'bg-slate-300'" aria-hidden="true"></span>
                    <span>Target 100</span>
                </button>
            </div>
        </div>

        {{-- Skeleton shimmer loading state (zero CLS) --}}
        <div x-show="loading" data-skeleton="trend-chart" class="mt-3 flex h-60 flex-col justify-center gap-3 p-4 sm:h-72 animate-pulse">
            <div class="h-4 w-1/3 rounded bg-slate-200"></div>
            <div class="flex-1 w-full rounded-xl bg-slate-100 flex items-end gap-2 p-4">
                <div class="w-1/6 h-1/2 bg-slate-200 rounded"></div>
                <div class="w-1/6 h-3/4 bg-slate-200 rounded"></div>
                <div class="w-1/6 h-2/3 bg-slate-200 rounded"></div>
                <div class="w-1/6 h-4/5 bg-slate-200 rounded"></div>
                <div class="w-1/6 h-1/3 bg-slate-200 rounded"></div>
                <div class="w-1/6 h-3/5 bg-slate-200 rounded"></div>
            </div>
            <div class="flex justify-between">
                <div class="h-3 w-16 rounded bg-slate-200"></div>
                <div class="h-3 w-16 rounded bg-slate-200"></div>
                <div class="h-3 w-16 rounded bg-slate-200"></div>
            </div>
        </div>

        {{-- Error state --}}
        <div x-show="!loading && error !== ''" x-cloak class="mt-3 flex h-60 flex-col items-center justify-center gap-2.5 rounded-xl border border-rose-200/70 bg-rose-50/40 p-6 text-center text-sm text-rose-700 sm:h-72" role="alert">
            <p class="font-medium" x-text="error"></p>
            <button type="button" @click="load()" class="pg-control-btn inline-flex items-center rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 shadow-2xs cursor-pointer">
                Muat Ulang
            </button>
        </div>

        {{-- Empty state --}}
        <div x-show="!loading && empty && error === ''" x-cloak class="mt-3 flex h-60 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-slate-200 bg-slate-50/60 p-6 text-center text-sm text-slate-500 sm:h-72">
            <p>Belum ada data kinerja yang aktif.</p>
            @can('dataset.import')
                <a href="{{ route('datasets.create') }}" class="text-xs font-semibold text-[#0b6175] hover:underline">Upload dataset pertama</a>
            @endcan
        </div>

        {{-- ECharts Canvas --}}
        <div x-show="!loading && !empty && error === ''" x-cloak class="mt-3">
            <div id="performanceChart" class="h-60 w-full sm:h-72" style="width: 100%; height: 288px; min-height: 240px;"></div>
        </div>
    </div>

    {{-- Collapsible Executive Annotations Strip (anti-overload di L1) --}}
    <div id="trend-annotations-panel" x-show="!loading && error === ''" x-cloak class="mt-4 border-t border-slate-100 pt-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <button
                type="button"
                @click="annotationsOpen = !annotationsOpen"
                :aria-expanded="annotationsOpen"
                class="inline-flex items-center gap-2 rounded-lg py-1 text-left transition hover:text-[#0b6175] cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]"
            >
                <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-600">Catatan Eksekutif per Periode</h4>
                <span
                    class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums"
                    :class="annotations.length > 0 ? 'bg-[#e6f4f6] text-[#0b6175]' : 'bg-slate-100 text-slate-500'"
                    x-text="annotations.length"
                ></span>
                <svg
                    class="h-3.5 w-3.5 text-slate-400 transition-transform duration-200"
                    :class="annotationsOpen ? 'rotate-180' : ''"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    aria-hidden="true"
                >
                    <path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </button>

            <div class="flex items-center gap-2">
                <span
                    x-show="!annotationsOpen && annotations.length > 0"
                    class="hidden sm:inline-block max-w-[260px] truncate text-[11px] text-slate-500"
                    x-text="annotations[0] ? (annotations[0].period_label + ': ' + annotations[0].note) : ''"
                ></span>

                @if ($canMutateAnnotations)
                    <button
                        type="button"
                        id="trend-annotation-add-btn"
                        x-show="!annotationFormOpen"
                        @click="openAnnotationForm()"
                        class="pg-control-btn inline-flex items-center gap-1 rounded-lg border border-slate-200/90 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:border-[#0b6175]/40 hover:bg-[#e6f4f6]/50 hover:text-[#0b6175] cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]"
                    >
                        <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M12 5v14M5 12h14" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <span>Tambah Catatan</span>
                    </button>
                @endif
            </div>
        </div>

        <p x-show="annotationError !== ''" x-text="annotationError" role="alert" class="mt-2 rounded-lg border border-rose-200 bg-rose-50/80 px-3 py-2 text-xs font-medium text-rose-800"></p>
        <p x-show="annotationSuccess !== ''" x-text="annotationSuccess" role="status" class="mt-2 rounded-lg border border-emerald-200 bg-emerald-50/80 px-3 py-2 text-xs font-medium text-emerald-800"></p>

        <div
            x-show="annotationsOpen || annotationFormOpen"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 -translate-y-1"
            x-transition:enter-end="opacity-100 translate-y-0"
            class="mt-2.5 space-y-2.5"
        >
            @if ($canMutateAnnotations)
                <form
                    id="trend-annotation-form"
                    x-show="annotationFormOpen"
                    @submit.prevent="saveAnnotation('{{ route('dashboard.kpi-annotations.store') }}')"
                    class="rounded-xl border border-slate-200/80 bg-slate-50/70 p-3 space-y-2.5"
                >
                    <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-3">
                        <div>
                            <label for="trend-annotation-period" class="block text-[11px] font-semibold text-slate-700">Periode</label>
                            <template x-if="availableAnnotationPeriods().length > 0">
                                <select
                                    id="trend-annotation-period"
                                    x-model="annotationPeriod"
                                    required
                                    class="mt-1 h-8 w-full rounded-lg border border-slate-200 bg-white px-2.5 text-xs text-slate-800 outline-none focus:border-[#0b6175] focus:ring-2 focus:ring-[#16a3b8]/25"
                                >
                                    <template x-for="opt in availableAnnotationPeriods()" :key="opt.key">
                                        <option :value="opt.key" x-text="opt.label"></option>
                                    </template>
                                </select>
                            </template>
                            <template x-if="availableAnnotationPeriods().length === 0">
                                <input
                                    id="trend-annotation-period"
                                    type="month"
                                    x-model="annotationPeriod"
                                    required
                                    class="mt-1 h-8 w-full rounded-lg border border-slate-200 bg-white px-2.5 text-xs text-slate-800 outline-none focus:border-[#0b6175] focus:ring-2 focus:ring-[#16a3b8]/25"
                                />
                            </template>
                        </div>
                        <div class="sm:col-span-2">
                            <label for="trend-annotation-note" class="block text-[11px] font-semibold text-slate-700">Catatan Konteks Bisnis</label>
                            <textarea
                                id="trend-annotation-note"
                                x-model="annotationNote"
                                maxlength="300"
                                rows="2"
                                required
                                placeholder="Jelaskan penyebab deviasi atau capaian NKO pada periode ini..."
                                class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs text-slate-800 placeholder:text-slate-400 outline-none focus:border-[#0b6175] focus:ring-2 focus:ring-[#16a3b8]/25"
                            ></textarea>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="text-[11px] text-slate-500 tabular-nums" x-text="annotationNote.length + ' / 300 karakter'"></span>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                @click="closeAnnotationForm()"
                                class="pg-control-btn inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50 hover:text-slate-900 cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]"
                            >
                                Batal
                            </button>
                            <button
                                type="submit"
                                id="trend-annotation-save-btn"
                                :disabled="annotationSaving"
                                class="pg-control-btn inline-flex items-center rounded-lg bg-[#0b6175] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#094e5e] disabled:cursor-not-allowed disabled:opacity-60 cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]"
                            >
                                <span x-text="annotationSaving ? 'Menyimpan...' : 'Simpan Catatan'"></span>
                            </button>
                        </div>
                    </div>
                </form>
            @endif

            <p x-show="annotations.length === 0 && !annotationFormOpen" class="text-xs text-slate-500">
                Belum ada catatan periode yang ditambahkan.
            </p>

            <div x-show="annotations.length > 0" class="max-h-56 overflow-y-auto space-y-2 pr-1">
                <template x-for="item in annotations" :key="item.period">
                    <div class="flex flex-col gap-2 rounded-xl border border-slate-200/75 bg-slate-50/50 px-3 py-2.5 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0 flex-1 space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center rounded-md bg-[#e6f4f6] px-2 py-0.5 text-[11px] font-semibold text-[#0b6175] tabular-nums" x-text="item.period_label"></span>
                                <span class="text-[11px] text-slate-500 tabular-nums" x-text="item.author_name + ' • ' + item.updated_at_label"></span>
                            </div>
                            <p class="text-xs leading-relaxed text-slate-700 break-words" x-text="item.note"></p>
                        </div>

                        @if ($canMutateAnnotations)
                            <div class="flex shrink-0 items-center gap-1.5">
                                <button
                                    type="button"
                                    @click="editAnnotation(item)"
                                    class="inline-flex items-center rounded-lg border border-slate-200/80 bg-white px-2.5 py-1 text-[11px] font-medium text-slate-600 transition hover:border-[#0b6175]/35 hover:text-[#0b6175] cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]"
                                >
                                    Ubah
                                </button>
                                <template x-if="confirmDeletePeriod !== item.period">
                                    <button
                                        type="button"
                                        @click="confirmDeletePeriod = item.period"
                                        class="inline-flex items-center rounded-lg border border-slate-200/80 bg-white px-2.5 py-1 text-[11px] font-medium text-rose-700 transition hover:border-rose-300 hover:bg-rose-50 cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-500"
                                    >
                                        Hapus
                                    </button>
                                </template>
                                <template x-if="confirmDeletePeriod === item.period">
                                    <div class="inline-flex items-center gap-1">
                                        <button
                                            type="button"
                                            @click="deleteAnnotation('{{ url('/dashboard/kpi-annotations') }}/' + encodeURIComponent(item.period))"
                                            :disabled="annotationSaving"
                                            class="inline-flex items-center rounded-lg border border-rose-300 bg-rose-600 px-2.5 py-1 text-[11px] font-semibold text-white transition hover:bg-rose-700 disabled:opacity-60 cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-500"
                                        >
                                            Yakin Hapus
                                        </button>
                                        <button
                                            type="button"
                                            @click="confirmDeletePeriod = null"
                                            class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-2 py-1 text-[11px] font-medium text-slate-600 hover:bg-slate-50 cursor-pointer"
                                        >
                                            Batal
                                        </button>
                                    </div>
                                </template>
                            </div>
                        @endif
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>
