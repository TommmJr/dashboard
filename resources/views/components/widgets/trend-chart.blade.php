@props(['widget' => [], 'data' => null])

@php
    $canMutateAnnotations = \App\Services\KpiPeriodAnnotationService::canMutate(auth()->user());
@endphp

{{-- Area chart ECharts --}}
<div class="ibs-card h-full p-5">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="ibs-section-title text-base font-bold text-slate-900">{{ $widget['label'] ?? 'Tren NKO' }}</h3>
        <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold text-slate-700 tabular-nums whitespace-nowrap">Target 100</span>
    </div>

    {{-- Skeleton shimmer loading state --}}
    <div x-show="loading" data-skeleton="trend-chart" class="mt-3 flex h-56 flex-col justify-center gap-3 p-4 sm:h-64 lg:h-72 animate-pulse">
        <div class="h-4 w-1/3 rounded bg-slate-200"></div>
        <div class="flex-1 w-full rounded-lg bg-slate-100 flex items-end gap-2 p-4">
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

    <div x-show="!loading && error !== ''" x-cloak class="mt-3 flex h-56 flex-col items-center justify-center gap-2 text-sm text-rose-700 sm:h-64 lg:h-72" role="alert">
        <p x-text="error"></p>
        <button type="button" @click="load()" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 shadow-2xs cursor-pointer">
            Muat Ulang
        </button>
    </div>

    <div x-show="!loading && empty && error === ''" x-cloak class="mt-3 flex h-56 flex-col items-center justify-center gap-2 text-sm text-slate-500 sm:h-64 lg:h-72">
        <p>Belum ada data kinerja yang aktif.</p>
        @can('dataset.import')
            <a href="{{ route('datasets.create') }}" class="text-xs font-semibold text-[#0b6175] hover:underline">Upload dataset pertama</a>
        @endcan
    </div>

    {{-- Chart selalu tampil. Bentuknya menyesuaikan jumlah periode: 1 periode =
         bar per unit (Y dari 0, satu warna, urut tertinggi) supaya snapshot
         terbaca jujur; >1 periode = garis tren per unit. --}}
    <div x-show="!loading && !empty && error === ''" x-cloak class="mt-3">
        <div id="performanceChart" class="h-56 w-full sm:h-64 lg:h-72" style="width: 100%; height: 288px; min-height: 224px;"></div>
    </div>

    {{-- Panel Anotasi Eksekutif per Periode (di bawah kanvas grafik) --}}
    <div id="trend-annotations-panel" x-show="!loading && error === ''" x-cloak class="mt-4 border-t border-slate-100 pt-3.5">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-600">Catatan Eksekutif per Periode</h4>
                <span x-show="annotations.length > 0" class="inline-flex items-center rounded-full bg-[#e6f4f6] px-2 py-0.5 text-[11px] font-semibold text-[#0b6175] tabular-nums" x-text="annotations.length"></span>
            </div>

            @if ($canMutateAnnotations)
                <button
                    type="button"
                    id="trend-annotation-add-btn"
                    x-show="!annotationFormOpen"
                    @click="openAnnotationForm()"
                    class="inline-flex items-center gap-1 rounded-lg border border-slate-200/90 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 transition hover:border-[#0b6175]/40 hover:bg-[#e6f4f6]/50 hover:text-[#0b6175] cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]"
                >
                    <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M12 5v14M5 12h14" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span>Tambah Catatan</span>
                </button>
            @endif
        </div>

        <p x-show="annotationError !== ''" x-text="annotationError" role="alert" class="mt-2 rounded-lg border border-rose-200 bg-rose-50/80 px-3 py-2 text-xs font-medium text-rose-800"></p>
        <p x-show="annotationSuccess !== ''" x-text="annotationSuccess" role="status" class="mt-2 rounded-lg border border-emerald-200 bg-emerald-50/80 px-3 py-2 text-xs font-medium text-emerald-800"></p>

        @if ($canMutateAnnotations)
            <form
                id="trend-annotation-form"
                x-show="annotationFormOpen"
                @submit.prevent="saveAnnotation('{{ route('dashboard.kpi-annotations.store') }}')"
                class="mt-3 rounded-xl border border-slate-200/80 bg-slate-50/70 p-3 space-y-2.5"
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
                            class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-slate-50 hover:text-slate-900 cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]"
                        >
                            Batal
                        </button>
                        <button
                            type="submit"
                            id="trend-annotation-save-btn"
                            :disabled="annotationSaving"
                            class="inline-flex items-center rounded-lg bg-[#0b6175] px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-[#094e5e] disabled:cursor-not-allowed disabled:opacity-60 cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]"
                        >
                            <span x-text="annotationSaving ? 'Menyimpan...' : 'Simpan Catatan'"></span>
                        </button>
                    </div>
                </div>
            </form>
        @endif

        <p x-show="annotations.length === 0 && !annotationFormOpen" class="mt-2 text-xs text-slate-500">
            Belum ada catatan periode yang ditambahkan.
        </p>

        <div x-show="annotations.length > 0" class="mt-2.5 space-y-2">
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
