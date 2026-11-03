@props(['widget' => [], 'data' => null])

@can('dashboard.update')
    @php
        $builderData = is_array($data) ? $data : [
            'catalog' => [],
            'targets' => [],
            'initial_target_key' => '',
            'current_user_layout_key' => '',
        ];
        $targets = $builderData['targets'] ?? [];
        $catalog = $builderData['catalog'] ?? [];
        $encodedBuilderData = base64_encode((string) json_encode($builderData, JSON_UNESCAPED_UNICODE));
    @endphp

    <div
        id="dashboard-layout-builder"
        x-data="dashboardLayoutBuilder('{{ $encodedBuilderData }}')"
        class="rounded-lg border border-slate-200 bg-white p-6 shadow"
    >
        {{-- In-page toast feedback for layout builder mutations --}}
        <div
            x-show="toast.show"
            x-cloak
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 -translate-y-2"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 -translate-y-2"
            :class="toast.type === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800'"
            class="fixed top-6 right-6 z-50 flex items-center gap-3 rounded-lg border px-4 py-3 shadow-lg"
            role="status"
        >
            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            <p class="text-sm font-medium" x-text="toast.message"></p>
            <button type="button" @click="toast.show = false" class="ml-2 text-slate-400 hover:text-slate-600">
                <span class="sr-only">Tutup</span>
                &times;
            </button>
        </div>

        {{-- Split-View Workspace: panel konfigurasi (kiri) dan pratinjau langsung (kanan) --}}
        <div id="builder-split-view" @resize.window="viewportWidth = window.innerWidth; resizePreviewCharts()" class="mt-4 grid grid-cols-1 items-start gap-6 lg:grid-cols-12">
            {{-- Panel Konfigurasi, Palet, dan Aksi --}}
            <div id="builder-config-pane" class="flex min-w-0 flex-col gap-5 lg:col-span-5">
                <div class="flex flex-col gap-4 border-b border-slate-200 pb-4">
                    <div>
                        <div class="flex items-center gap-2.5">
                            <h3 class="text-lg font-bold text-primary">{{ $widget['label'] ?? 'Editor Layout' }}</h3>
                            <span
                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                :class="isCustomized() ? 'bg-accent/15 text-primary' : 'bg-slate-100 text-slate-600'"
                                x-text="isCustomized() ? 'Kustom (Database)' : 'Bawaan (Konfigurasi Fallback)'"
                            >
                                Bawaan (Konfigurasi Fallback)
                            </span>
                        </div>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="layout-editor-target" class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Target Layout (Role / Divisi)
                        </label>
                        <select
                            id="layout-editor-target"
                            name="target_key"
                            x-model="selectedTargetKey"
                            @change="selectTarget(selectedTargetKey)"
                            :disabled="saving"
                            class="h-10 rounded border border-slate-200 bg-white px-3 text-sm font-medium text-primary outline-none focus:border-accent focus:ring-2 focus:ring-accent/20 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            @foreach ($targets as $target)
                                <option value="{{ $target['layout_key'] }}">
                                    {{ $target['label'] }} ({{ $target['layout_key'] }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- Status feedback AJAX (sukses / validasi 422 / error server) --}}
                <div
                    x-show="saving"
                    x-cloak
                    class="rounded border border-sky-200 bg-sky-50 px-4 py-3 text-sm font-medium text-sky-800"
                    role="status"
                >
                    <span x-text="savingAction === 'reset' ? 'Mereset layout ke konfigurasi bawaan...' : 'Menyimpan perubahan layout...'"></span>
                </div>

                <div
                    x-show="!saving && statusMessage !== ''"
                    x-cloak
                    class="rounded border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800"
                    role="status"
                    x-text="statusMessage"
                ></div>

                <div
                    x-show="!saving && (errorMessage !== '' || validationErrors.length > 0)"
                    x-cloak
                    class="rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
                    role="alert"
                >
                    <p x-show="errorMessage !== ''" class="font-semibold" x-text="errorMessage"></p>
                    <ul x-show="validationErrors.length > 0" class="mt-1 list-inside list-disc space-y-0.5">
                        <template x-for="(err, idx) in validationErrors" :key="idx">
                            <li x-text="err"></li>
                        </template>
                    </ul>
                </div>

                {{-- Palet Komponen Visual (Top Toolbar Looker Studio Style) --}}
                <div class="rounded-lg border border-slate-200 bg-slate-50/70 p-4">
                    <div class="flex items-center justify-between border-b border-slate-200 pb-3">
                        <div class="flex items-center gap-2">
                            <h4 class="text-sm font-bold uppercase tracking-wide text-primary">Komponen</h4>
                            <span class="inline-flex items-center rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary">
                                {{ count($catalog) }}
                            </span>
                        </div>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-2 xl:grid-cols-3" aria-label="Palet komponen">
                        <template x-for="item in catalog" :key="item.key">
                            <div
                                draggable="true"
                                @dragstart="startPaletteDrag(item.key)"
                                @dragend="endPaletteDrag()"
                                class="group relative flex flex-col justify-between rounded-lg border border-slate-200 bg-white p-3 shadow-xs transition hover:border-accent hover:shadow-md cursor-grab active:cursor-grabbing"
                                :class="(item.key !== 'custom-chart' && hasWidget(item.key)) ? 'opacity-60 bg-slate-50' : ''"
                            >
                                <div>
                                    <div class="palette-thumb relative mb-2 flex h-14 w-full items-center justify-center rounded-md bg-slate-50 border border-slate-100 text-primary/80 group-hover:bg-primary/5 group-hover:border-primary/20 transition-colors overflow-hidden">
                                        @include('components.widgets.partials.widget-sketch', ['keyExpr' => 'item.key', 'sizeClass' => 'h-10 w-full'])
                                    </div>
                                    <div class="flex items-center justify-between gap-1 mb-1.5">
                                        <span
                                            class="palette-type-label rounded bg-primary/10 px-1.5 py-0.5 text-[10px] font-semibold text-primary truncate"
                                            x-text="paletteVisual(item.key).label"
                                        ></span>
                                        <span
                                            class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-600"
                                            x-text="`${item.default_cols || 3} Kolom`"
                                        ></span>
                                    </div>
                                    <p class="text-xs font-bold text-primary line-clamp-2" x-text="item.label"></p>
                                </div>

                                <div class="mt-3 pt-2 border-t border-slate-100 flex items-center justify-between">
                                    <span class="text-[10px] text-slate-400 group-hover:text-accent font-medium flex items-center gap-1">
                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                                        </svg>
                                        Tarik ke Kanvas
                                    </span>
                                    <button
                                        type="button"
                                        @click="addWidget(item.key)"
                                        :disabled="saving || (item.key !== 'custom-chart' && hasWidget(item.key))"
                                        class="inline-flex h-6 items-center rounded px-2 text-[10px] font-semibold transition disabled:cursor-not-allowed"
                                        :class="(item.key !== 'custom-chart' && hasWidget(item.key))
                                            ? 'border border-slate-200 bg-slate-100 text-slate-400'
                                            : 'bg-primary text-white hover:bg-accent'"
                                        x-text="(item.key !== 'custom-chart' && hasWidget(item.key)) ? 'Aktif' : '+ Tambah'"
                                    ></button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Daftar Widget Aktif: kontrol susunan, lebar, dan konfigurasi --}}
                <div
                    id="active-widget-list"
                    @dragover.prevent="isDragOverCanvas = true"
                    @dragleave="isDragOverCanvas = false"
                    @drop.prevent="isDragOverCanvas = false; onCanvasDrop(activeWidgets.length)"
                    :class="isDragOverCanvas ? 'ring-2 ring-accent bg-accent/5' : 'bg-white'"
                    class="rounded-lg border border-slate-200 p-4 transition"
                >
                    <div class="flex items-center justify-between gap-2 border-b border-slate-200 pb-3">
                        <div>
                            <h4 class="text-sm font-bold uppercase tracking-wide text-primary">Widget Aktif</h4>
                        </div>
                        <span
                            class="shrink-0 rounded bg-primary/10 px-2.5 py-1 text-xs font-semibold text-primary"
                            x-text="`${activeWidgets.length} Widget`"
                        ></span>
                    </div>

                    <ul class="mt-3 space-y-2.5">
                        <template x-for="(w, index) in activeWidgets" :key="index">
                            <li
                                draggable="true"
                                @dragstart="onCardDragStart(index)"
                                @dragover.prevent="onCardDragOver(index)"
                                @drop.prevent.stop="onCardDrop(index)"
                                @dragend="onCardDragEnd()"
                                :class="canvasDragIndex === index ? 'opacity-40 border-dashed border-accent' : 'border-slate-200'"
                                class="rounded-lg border bg-slate-50/60 p-3 transition hover:shadow-sm"
                            >
                                <div class="canvas-card-toolbar flex flex-wrap items-center justify-between gap-2">
                                    <div class="flex min-w-0 items-center gap-2.5">
                                        <span
                                            class="canvas-drag-handle inline-flex h-7 w-7 shrink-0 items-center justify-center rounded bg-slate-100 text-xs font-bold text-slate-600 cursor-grab active:cursor-grabbing hover:bg-slate-200 transition"
                                            title="Tarik untuk memindahkan urutan widget"
                                        >
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16" />
                                            </svg>
                                        </span>
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-bold text-primary" x-text="widgetLabel(w.key)"></p>
                                            <div class="flex items-center gap-1.5 text-xs text-slate-400">
                                                <span class="font-mono text-[11px]" x-text="w.key"></span>
                                                <span>&bull;</span>
                                                <span class="font-semibold text-accent" x-text="`${w.cols || 3} Kolom`"></span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="flex flex-wrap items-center gap-1.5">
                                        {{-- Atur Lebar Selector --}}
                                        <div class="flex items-center rounded-lg border border-slate-200 bg-white p-0.5 text-xs font-medium" title="Atur Lebar Kolom">
                                            <span class="px-1.5 text-[11px] font-semibold text-slate-500">Atur Lebar:</span>
                                            <template x-for="n in [1, 2, 3]" :key="n">
                                                <button
                                                    type="button"
                                                    @click="setWidgetCols(w, n)"
                                                    :aria-label="`Lebar ${n} kolom`"
                                                    :class="(w.cols === n) ? 'bg-primary text-white shadow-xs' : 'text-slate-600 hover:text-primary'"
                                                    class="rounded px-2 py-0.5 text-[11px] font-semibold transition"
                                                    x-text="n"
                                                ></button>
                                            </template>
                                        </div>

                                        <button
                                            type="button"
                                            @click="moveUp(index)"
                                            :disabled="saving || index === 0"
                                            class="inline-flex h-7 w-7 items-center justify-center rounded border border-slate-200 bg-white text-xs font-bold text-slate-600 hover:text-primary disabled:cursor-not-allowed disabled:opacity-40"
                                            :aria-label="`Naikkan ${widgetLabel(w.key)}`"
                                            title="Geser ke atas / kiri"
                                        >
                                            &uarr;
                                        </button>
                                        <button
                                            type="button"
                                            @click="moveDown(index)"
                                            :disabled="saving || index === activeWidgets.length - 1"
                                            class="inline-flex h-7 w-7 items-center justify-center rounded border border-slate-200 bg-white text-xs font-bold text-slate-600 hover:text-primary disabled:cursor-not-allowed disabled:opacity-40"
                                            :aria-label="`Turunkan ${widgetLabel(w.key)}`"
                                            title="Geser ke bawah / kanan"
                                        >
                                            &darr;
                                        </button>
                                        <button
                                            type="button"
                                            @click="removeWidget(index)"
                                            :disabled="saving || activeWidgets.length <= 1 || isLockedWidget(w.key)"
                                            class="inline-flex h-7 items-center rounded border border-red-200 bg-red-50 px-2 text-xs font-semibold text-red-700 transition hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-40"
                                            :aria-label="`Hapus ${widgetLabel(w.key)}`"
                                            :title="isLockedWidget(w.key) ? 'Widget wajib ada untuk peran ini' : 'Hapus widget dari dashboard'"
                                        >
                                            Hapus
                                        </button>
                                    </div>
                                </div>

                                <div class="mt-2.5 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium">Lebar Kolom:</span>
                                        <select
                                            x-model.number="w.cols"
                                            class="rounded border border-slate-200 bg-white px-2 py-1 text-xs text-primary font-semibold outline-none focus:border-accent"
                                        >
                                            <option :value="1">1 Kolom (1/3)</option>
                                            <option :value="2">2 Kolom (2/3)</option>
                                            <option :value="3">3 Kolom (Penuh)</option>
                                        </select>
                                    </div>

                                    <template x-if="w.key === 'custom-chart'">
                                        <button
                                            type="button"
                                            @click="w._openConfig = !w._openConfig"
                                            class="inline-flex items-center gap-1 font-semibold text-accent hover:underline"
                                        >
                                            <span x-text="w._openConfig ? 'Tutup Konfigurasi' : 'Atur Grafik'"></span>
                                        </button>
                                    </template>
                                </div>

                                {{-- Panel Konfigurasi Inline untuk Custom Chart --}}
                                <template x-if="w.key === 'custom-chart' && w._openConfig">
                                    <div class="mt-2.5 space-y-2.5 rounded-lg border border-slate-200 bg-white p-3 text-xs">
                                        <div>
                                            <label class="mb-1 block font-semibold text-slate-600">Judul Grafik</label>
                                            <input
                                                type="text"
                                                x-model="w.config.title"
                                                maxlength="60"
                                                class="w-full rounded border border-slate-300 bg-white px-2.5 py-1 text-xs text-primary outline-none focus:border-accent"
                                                placeholder="Contoh: Distribusi Skor NKO"
                                            />
                                        </div>
                                        <div class="grid grid-cols-2 gap-2">
                                            <div>
                                                <label class="mb-1 block font-semibold text-slate-600">Tipe Grafik</label>
                                                <select
                                                    x-model="w.config.chart_type"
                                                    class="w-full rounded border border-slate-300 bg-white px-2 py-1 text-xs text-primary outline-none focus:border-accent"
                                                >
                                                    <option value="bar">Bar (Batang)</option>
                                                    <option value="line">Line (Garis)</option>
                                                    <option value="donut">Donut (Lingkaran)</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="mb-1 block font-semibold text-slate-600">Metrik</label>
                                                <select
                                                    x-model="w.config.metric"
                                                    class="w-full rounded border border-slate-300 bg-white px-2 py-1 text-xs text-primary outline-none focus:border-accent"
                                                >
                                                    <option value="nko_score">Skor NKO</option>
                                                    <option value="pencapaian_ratio">Persentase Pencapaian</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </li>
                        </template>
                    </ul>

                    <div
                        x-show="activeWidgets.length === 0"
                        x-cloak
                        class="mt-3 rounded-lg border-2 border-dashed border-slate-300 bg-white p-6 text-center text-xs text-slate-400"
                    >
                        Belum ada widget aktif. Tarik komponen dari palet atau klik tombol Tambah.
                    </div>
                </div>

                {{-- Footer aksi simpan & reset --}}
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-4">
                    <p class="text-xs text-slate-500">
                        Target aktif:
                        <span class="font-semibold text-primary" x-text="currentTarget()?.layout_key ?? '-'"></span>
                    </p>

                    <div class="flex flex-wrap items-center gap-3">
                        <a
                            href="{{ route('dashboard') }}"
                            class="inline-flex h-10 items-center justify-center rounded border border-slate-300 bg-white px-4 text-sm font-semibold text-primary transition hover:border-accent hover:text-accent"
                        >
                            Lihat Dashboard
                        </a>

                        <button
                            type="button"
                            @click="resetLayout()"
                            :disabled="saving"
                            class="inline-flex h-10 items-center justify-center rounded border border-slate-300 bg-white px-4 text-sm font-semibold text-primary transition hover:border-accent hover:text-accent disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Reset ke Bawaan
                        </button>

                        <button
                            type="button"
                            @click="saveLayout()"
                            :disabled="saving || activeWidgets.length === 0"
                            class="inline-flex h-10 items-center justify-center rounded bg-primary px-5 text-sm font-semibold text-white transition hover:bg-accent disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Simpan Layout
                        </button>
                    </div>
                </div>
            </div>

            {{-- Panel Pratinjau Langsung (sticky pada layar lebar) --}}
            <div id="builder-preview-pane" class="min-w-0 lg:sticky lg:top-6 lg:col-span-7">
                <p class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">Pratinjau</p>
                <div class="rounded-lg border border-slate-200 bg-slate-50/50 p-4">
                    <div class="flex flex-col gap-2 border-b border-slate-200 pb-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h4 class="text-sm font-bold uppercase tracking-wide text-primary">Pratinjau</h4>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded bg-primary/10 px-2.5 py-1 text-xs font-semibold text-primary" x-text="`${activeWidgets.length} Widget di Kanvas`"></span>
                            <span class="rounded bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600" x-text="`Viewport ${viewportWidth}px`"></span>
                        </div>
                    </div>

                    {{-- Viewport pratinjau ala monitor eksekutif --}}
                    <div
                        id="preview-canvas-viewport"
                        @dragover="autoScrollPreview($event)"
                        @dragleave="isDragOverCanvas = false"
                        @drop.prevent="isDragOverCanvas = false; onCanvasDrop(activeWidgets.length)"
                        :class="isDragOverCanvas ? 'ring-2 ring-accent bg-accent/5' : 'bg-slate-100'"
                        class="mt-4 min-h-[260px] rounded-lg border border-slate-300 p-3 transition lg:max-h-[calc(100vh-14rem)] lg:overflow-y-auto"
                    >
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                            <template x-for="(w, index) in activeWidgets" :key="index">
                                <div
                                    draggable="true"
                                    @dragstart="onCardDragStart(index)"
                                    @dragover.prevent="onCardDragOver(index)"
                                    @drop.prevent.stop="onCardDrop(index)"
                                    @dragend="onCardDragEnd()"
                                    :class="[
                                        previewSpanClass(w.cols),
                                        canvasDragIndex === index ? 'opacity-40 border-dashed border-accent' : 'border-slate-200'
                                    ]"
                                    class="preview-card-item flex min-w-0 flex-col rounded-lg border bg-white p-3 shadow-sm transition hover:shadow-md"
                                >
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="truncate text-xs font-bold text-primary" x-text="w.key === 'custom-chart' ? (w.config?.title || widgetLabel(w.key)) : widgetLabel(w.key)"></p>
                                        <span class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-600" x-text="`${w.cols || 3} Kolom`"></span>
                                    </div>

                                    {{-- Live In-Canvas WYSIWYG Previews --}}
                                    <template x-if="w.key === 'custom-chart'">
                                        <div
                                            class="preview-chart-container canvas-chart-container relative mt-2 h-44 w-full rounded border border-slate-100 bg-slate-50/60 p-2"
                                            x-effect="initPreviewChart($el, w)"
                                        >
                                            <div class="h-full w-full echarts-instance" :id="`preview-chart-${w._uid}`"></div>
                                        </div>
                                    </template>

                                    <template x-if="w.key === 'trend-chart'">
                                        <div
                                            class="preview-chart-container canvas-chart-container relative mt-2 h-40 w-full rounded border border-slate-100 bg-slate-50/60 p-2"
                                            x-effect="initPreviewChart($el, w)"
                                        >
                                            <div class="h-full w-full echarts-instance" :id="`preview-chart-${w._uid}`"></div>
                                        </div>
                                    </template>

                                    <template x-if="w.key === 'kpi-summary'">
                                        <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
                                            <div class="rounded border border-sky-100 bg-sky-50/80 p-2.5">
                                                <p class="text-[10px] font-bold uppercase tracking-wide text-sky-700">Rata-rata NKO</p>
                                                <p class="mt-1 text-base font-bold text-sky-950">105.4%</p>
                                            </div>
                                            <div class="rounded border border-emerald-100 bg-emerald-50/80 p-2.5">
                                                <p class="text-[10px] font-bold uppercase tracking-wide text-emerald-700">Indikator</p>
                                                <p class="mt-1 text-base font-bold text-emerald-950">16 Tercapai</p>
                                            </div>
                                            <div class="col-span-2 rounded border border-amber-100 bg-amber-50/80 p-2.5 sm:col-span-1">
                                                <p class="text-[10px] font-bold uppercase tracking-wide text-amber-700">Periode Aktif</p>
                                                <p class="mt-1 text-xs font-bold text-amber-950">Agustus 2026</p>
                                            </div>
                                        </div>
                                    </template>

                                    <template x-if="w.key === 'nko-ranking'">
                                        <div class="mt-2 space-y-1.5 rounded border border-slate-100 bg-slate-50/60 p-2.5 text-xs">
                                            <div class="flex items-center justify-between font-medium">
                                                <span class="text-slate-700">1. TJBT (Jawa Bagian Tengah)</span>
                                                <span class="font-bold text-emerald-600">105.5</span>
                                            </div>
                                            <div class="flex items-center justify-between font-medium">
                                                <span class="text-slate-700">2. TRANS (Transmisi)</span>
                                                <span class="font-bold text-emerald-600">100.7</span>
                                            </div>
                                            <div class="flex items-center justify-between text-[11px] text-slate-400">
                                                <span>+13 Unit Lainnya</span>
                                                <span class="font-semibold text-slate-500">100.0</span>
                                            </div>
                                        </div>
                                    </template>

                                    <template x-if="!['custom-chart', 'trend-chart', 'kpi-summary', 'nko-ranking'].includes(w.key)">
                                        <div class="preview-sketch mt-2 flex h-32 flex-col items-center justify-center rounded border border-slate-100 bg-slate-50/60 text-primary/70">
                                            @include('components.widgets.partials.widget-sketch', ['keyExpr' => 'w.key', 'sizeClass' => 'h-24 w-full'])
                                            <span class="mt-1 text-[11px] text-slate-500" x-text="paletteVisual(w.key).label"></span>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>

                        {{-- Placeholder Saat Kanvas Kosong --}}
                        <div
                            x-show="activeWidgets.length === 0"
                            x-cloak
                            class="flex flex-col items-center justify-center rounded-lg border-2 border-dashed border-slate-300 bg-white p-8 text-center"
                        >
                            <svg class="mb-2 h-10 w-10 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                            </svg>
                            <p class="text-sm font-bold text-slate-600">Kanvas Presentasi Masih Kosong</p>
                            <p class="mt-1 text-xs text-slate-400">Tarik komponen dari Palet Komponen dan lepas di sini untuk memulai.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        if (typeof window.dashboardLayoutBuilder !== 'function') {
            window.dashboardLayoutBuilder = function (rawInitial = {}) {
                let initial = rawInitial;
                if (typeof rawInitial === 'string' && rawInitial !== '') {
                    try {
                        const bytes = Uint8Array.from(atob(rawInitial), (c) => c.charCodeAt(0));
                        initial = JSON.parse(new TextDecoder().decode(bytes));
                    } catch (e) {
                        initial = {};
                    }
                }
                const targets = Array.isArray(initial.targets) ? initial.targets : [];
                const catalog = Array.isArray(initial.catalog) ? initial.catalog : [];
                const defaultTargetKey = initial.initial_target_key || (targets[0]?.layout_key ?? '');
                const initialTarget = targets.find((t) => t.layout_key === defaultTargetKey) || targets[0] || null;

                return {
                    catalog,
                    targets,
                    selectedTargetKey: initialTarget ? initialTarget.layout_key : '',
                    activeWidgets: [],
                    saving: false,
                    savingAction: '',
                    statusMessage: '',
                    errorMessage: '',
                    validationErrors: [],
                    dragIndex: null,
                    canvasDragIndex: null,
                    paletteDragKey: null,
                    isDragOverCanvas: false,
                    viewportWidth: window.innerWidth,
                    currentUserLayoutKey: initial.current_user_layout_key || '',
                    toast: { show: false, message: '', type: 'success', timer: null },
                    previewChartInstances: {},
                    previewResizeFrame: null,

                    showToast(msg, type = 'success') {
                        if (this.toast.timer) {
                            clearTimeout(this.toast.timer);
                        }
                        this.toast.message = msg;
                        this.toast.type = type;
                        this.toast.show = true;
                        this.toast.timer = setTimeout(() => {
                            this.toast.show = false;
                        }, 4000);
                    },

                    init() {
                        const target = this.currentTarget();
                        this.activeWidgets = (target && Array.isArray(target.active_widgets))
                            ? target.active_widgets.map((w) => this.normalizeItem(w))
                            : [];
                    },

                    normalizeItem(item) {
                        const uid = 'w_' + Math.random().toString(36).substring(2, 9);
                        if (typeof item === 'string') {
                            const cat = this.catalog.find((c) => c.key === item);
                            return {
                                _uid: uid,
                                key: item,
                                cols: cat?.default_cols || 3,
                                config: item === 'custom-chart' ? { title: 'Grafik Kustom', chart_type: 'bar', metric: 'nko_score' } : {},
                                _openConfig: false,
                            };
                        }
                        const cat = this.catalog.find((c) => c.key === item.key);
                        return {
                            _uid: item._uid || uid,
                            key: item.key,
                            cols: item.cols || cat?.default_cols || 3,
                            config: item.config || (item.key === 'custom-chart' ? { title: 'Grafik Kustom', chart_type: 'bar', metric: 'nko_score' } : {}),
                            _openConfig: false,
                        };
                    },

                    currentTarget() {
                        return this.targets.find((t) => t.layout_key === this.selectedTargetKey) || null;
                    },

                    isCustomized() {
                        return Boolean(this.currentTarget()?.is_customized);
                    },

                    selectTarget(targetKey) {
                        this.disposeAllPreviewCharts();
                        this.selectedTargetKey = targetKey;
                        const target = this.currentTarget();
                        this.activeWidgets = (target && Array.isArray(target.active_widgets))
                            ? target.active_widgets.map((w) => this.normalizeItem(w))
                            : [];
                        this.clearMessages();
                    },

                    clearMessages() {
                        this.statusMessage = '';
                        this.errorMessage = '';
                        this.validationErrors = [];
                    },

                    widgetLabel(key) {
                        const found = this.catalog.find((item) => item.key === key);
                        return found ? found.label : key;
                    },

                    hasWidget(key) {
                        return this.activeWidgets.some((w) => w.key === key);
                    },

                    addWidget(key) {
                        if (this.saving) {
                            return;
                        }
                        if (key !== 'custom-chart' && this.hasWidget(key)) {
                            return;
                        }
                        this.clearMessages();
                        this.activeWidgets.push(this.normalizeItem(key));
                    },

                    isLockedWidget(widgetKey) {
                        return (this.selectedTargetKey === 'editor_dashboard' || this.selectedTargetKey === 'admin')
                            && widgetKey === 'layout-editor-placeholder';
                    },

                    removeWidget(index) {
                        if (this.saving || this.activeWidgets.length <= 1) {
                            return;
                        }
                        const item = this.activeWidgets[index];
                        if (item && this.isLockedWidget(item.key)) {
                            return;
                        }
                        if (item && item._uid) {
                            this.disposePreviewChart(item._uid);
                        }
                        this.clearMessages();
                        this.activeWidgets.splice(index, 1);
                        this.resizePreviewCharts();
                    },

                    moveUp(index) {
                        if (this.saving || index <= 0 || index >= this.activeWidgets.length) {
                            return;
                        }
                        this.clearMessages();
                        const copy = [...this.activeWidgets];
                        const temp = copy[index - 1];
                        copy[index - 1] = copy[index];
                        copy[index] = temp;
                        this.activeWidgets = copy;
                    },

                    moveDown(index) {
                        if (this.saving || index < 0 || index >= this.activeWidgets.length - 1) {
                            return;
                        }
                        this.clearMessages();
                        const copy = [...this.activeWidgets];
                        const temp = copy[index + 1];
                        copy[index + 1] = copy[index];
                        copy[index] = temp;
                        this.activeWidgets = copy;
                    },

                    paletteVisual(key) {
                        const map = {
                            'kpi-summary': { type: 'scorecard', label: 'Kartu Skor' },
                            'admin-summary': { type: 'scorecard', label: 'Kartu Skor' },
                            'trend-chart': { type: 'line', label: 'Grafik Garis' },
                            'kinerja-realisasi': { type: 'bar', label: 'Grafik Batang' },
                            'target-realisasi-per-indikator': { type: 'bar', label: 'Grafik Batang' },
                            'pencapaian-nko': { type: 'bar', label: 'Grafik Batang' },
                            'custom-chart': { type: 'bar', label: 'Grafik Batang' },
                            'nko-donut': { type: 'donut', label: 'Grafik Donat' },
                            'nko-ranking': { type: 'ranking', label: 'Peringkat' },
                            'kinerja-detail': { type: 'table', label: 'Tabel' },
                            'dataset-status': { type: 'table', label: 'Tabel' },
                            'failed-datasets': { type: 'table', label: 'Tabel' },
                            'recent-audit': { type: 'table', label: 'Tabel' },
                        };

                        return map[key] || { type: 'generic', label: 'Komponen' };
                    },

                    startPaletteDrag(key) {
                        if (this.saving) return;
                        this.paletteDragKey = key;
                    },

                    endPaletteDrag() {
                        this.paletteDragKey = null;
                        this.isDragOverCanvas = false;
                    },

                    onCardDragStart(index) {
                        if (this.saving) return;
                        this.canvasDragIndex = index;
                        this.dragIndex = index;
                    },

                    onCardDragOver(index) {
                        if (this.saving) return;
                    },

                    autoScrollPreview(event) {
                        if (this.canvasDragIndex === null && !this.paletteDragKey) {
                            return;
                        }
                        this.isDragOverCanvas = true;
                        event.preventDefault();

                        // ponytail: fixed step, add acceleration by distance if users find it slow
                        const edge = 60;
                        const step = 16;
                        const viewport = document.getElementById('preview-canvas-viewport');
                        const isScrollable = viewport && (viewport.scrollHeight > viewport.clientHeight);

                        if (isScrollable) {
                            const rect = viewport.getBoundingClientRect();
                            const y = event.clientY;
                            if (y - rect.top < edge) {
                                viewport.scrollTop -= step;
                            } else if (rect.bottom - y < edge) {
                                viewport.scrollTop += step;
                            }
                        } else {
                            const y = event.clientY;
                            if (y < edge) {
                                window.scrollBy(0, -step);
                            } else if (window.innerHeight - y < edge) {
                                window.scrollBy(0, step);
                            }
                        }
                    },

                    onCardDrop(targetIndex) {
                        if (this.saving) return;
                        if (this.paletteDragKey) {
                            this.onCanvasDrop(targetIndex);
                            return;
                        }
                        if (this.canvasDragIndex === null || this.canvasDragIndex === targetIndex) {
                            this.canvasDragIndex = null;
                            this.dragIndex = null;
                            return;
                        }
                        this.clearMessages();
                        const copy = [...this.activeWidgets];
                        const [moved] = copy.splice(this.canvasDragIndex, 1);
                        copy.splice(targetIndex, 0, moved);
                        this.activeWidgets = copy;
                        this.canvasDragIndex = null;
                        this.dragIndex = null;
                    },

                    onCardDragEnd() {
                        this.canvasDragIndex = null;
                        this.dragIndex = null;
                    },

                    onCanvasDrop(index = null) {
                        if (this.saving) return;
                        if (this.paletteDragKey) {
                            const key = this.paletteDragKey;
                            this.paletteDragKey = null;
                            this.isDragOverCanvas = false;
                            if (key !== 'custom-chart' && this.hasWidget(key)) {
                                this.showToast(`Widget "${this.widgetLabel(key)}" sudah ada di kanvas.`, 'error');
                                return;
                            }
                            this.clearMessages();
                            const newItem = this.normalizeItem(key);
                            const insertIdx = (index !== null && index >= 0 && index <= this.activeWidgets.length)
                                ? index
                                : this.activeWidgets.length;
                            this.activeWidgets.splice(insertIdx, 0, newItem);
                            this.showToast(`Widget "${this.widgetLabel(key)}" berhasil ditambahkan ke kanvas.`, 'success');
                            return;
                        }
                        if (this.canvasDragIndex !== null) {
                            const targetIdx = (index !== null && index >= 0 && index <= this.activeWidgets.length)
                                ? Math.min(index, this.activeWidgets.length - 1)
                                : this.activeWidgets.length - 1;
                            this.onCardDrop(targetIdx);
                        }
                    },

                    onDragStart(index) {
                        this.onCardDragStart(index);
                    },

                    onDragOver(index) {
                        this.onCardDragOver(index);
                    },

                    onDrop(index) {
                        this.onCardDrop(index);
                    },

                    onDragEnd() {
                        this.onCardDragEnd();
                    },

                    previewChartOption(widget) {
                        if (widget.key === 'trend-chart') {
                            return {
                                color: ['#00a896'],
                                grid: { top: 15, right: 15, bottom: 25, left: 35 },
                                xAxis: { type: 'category', data: ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu'], axisLabel: { fontSize: 10 } },
                                yAxis: { type: 'value', min: 95, axisLabel: { fontSize: 10 } },
                                series: [{ data: [98.2, 99.1, 100.4, 101.2, 102.5, 103.8, 104.9, 105.5], type: 'line', smooth: true }]
                            };
                        }

                        const chartType = widget.config?.chart_type || 'bar';
                        const isNko = (widget.config?.metric || 'nko_score') === 'nko_score';
                        const units = ['TJBT', 'Smg', 'Skr', 'Pwt', 'Ygy'];
                        const values = isNko ? [105.5, 102.3, 101.8, 100.4, 99.8] : [100, 95.2, 98.4, 92.1, 91.5];

                        if (chartType === 'donut') {
                            return {
                                color: ['#005b96', '#00a896', '#f39c12', '#2ecc71'],
                                tooltip: { trigger: 'item' },
                                series: [{
                                    name: widget.config?.title || 'Metrik',
                                    type: 'pie',
                                    radius: ['45%', '70%'],
                                    avoidLabelOverlap: false,
                                    label: { show: false },
                                    data: [
                                        { value: 105.5, name: 'TJBT' },
                                        { value: 102.3, name: 'Smg' },
                                        { value: 101.8, name: 'Skr' },
                                        { value: 100.4, name: 'Lainnya' }
                                    ]
                                }]
                            };
                        }

                        return {
                            color: ['#005b96'],
                            grid: { top: 15, right: 15, bottom: 25, left: 35 },
                            xAxis: { type: 'category', data: units, axisLabel: { fontSize: 10 } },
                            yAxis: { type: 'value', axisLabel: { fontSize: 10 } },
                            series: chartType === 'line'
                                ? [{ data: values, type: 'line', smooth: true, areaStyle: { opacity: 0.15 } }]
                                : [{ data: values, type: 'bar', barWidth: '45%', itemStyle: { borderRadius: [3, 3, 0, 0] } }]
                        };
                    },

                    initPreviewChart(containerEl, widget) {
                        if (typeof window.echarts === 'undefined' || !containerEl || !widget) {
                            return;
                        }
                        void widget.cols;
                        void widget.config?.chart_type;
                        void widget.config?.metric;
                        void widget.config?.title;

                        const chartDom = containerEl.querySelector('.echarts-instance') || containerEl;
                        const uid = widget._uid || (widget._uid = 'w_' + Math.random().toString(36).substring(2, 9));
                        let chartInstance = this.previewChartInstances[uid];
                        if (chartInstance && (chartInstance.isDisposed() || chartInstance.getDom() !== chartDom)) {
                            this.disposePreviewChart(uid);
                            chartInstance = null;
                        }
                        if (!chartInstance) {
                            chartInstance = window.echarts.getInstanceByDom(chartDom) || window.echarts.init(chartDom);
                            this.previewChartInstances[uid] = chartInstance;
                        }
                        chartInstance.setOption(this.previewChartOption(widget), true);
                        this.resizePreviewCharts();
                    },

                    initCanvasChart(containerEl, widget) {
                        return this.initPreviewChart(containerEl, widget);
                    },

                    initTrendPreviewChart(containerEl, widget) {
                        return this.initPreviewChart(containerEl, widget);
                    },

                    resizePreviewCharts() {
                        if (this.previewResizeFrame !== null) {
                            cancelAnimationFrame(this.previewResizeFrame);
                        }
                        this.previewResizeFrame = requestAnimationFrame(() => {
                            this.previewResizeFrame = null;
                            this.pruneDetachedPreviewCharts();
                            Object.values(this.previewChartInstances).forEach((chartInstance) => {
                                if (chartInstance && !chartInstance.isDisposed()) {
                                    chartInstance.resize();
                                }
                            });
                        });
                    },

                    disposePreviewChart(uid) {
                        const chartInstance = this.previewChartInstances[uid];
                        if (!chartInstance) {
                            return;
                        }
                        if (!chartInstance.isDisposed()) {
                            chartInstance.dispose();
                        }
                        delete this.previewChartInstances[uid];
                    },

                    disposeAllPreviewCharts() {
                        Object.keys(this.previewChartInstances).forEach((uid) => this.disposePreviewChart(uid));
                    },

                    pruneDetachedPreviewCharts() {
                        Object.keys(this.previewChartInstances).forEach((uid) => {
                            const inst = this.previewChartInstances[uid];
                            const dom = inst && !inst.isDisposed() ? inst.getDom() : null;
                            if (!dom || !dom.isConnected) {
                                this.disposePreviewChart(uid);
                            }
                        });
                    },

                    setWidgetCols(widget, cols) {
                        widget.cols = cols;
                        this.$nextTick(() => {
                            this.resizePreviewCharts();
                            window.dispatchEvent(new Event('resize'));
                        });
                    },

                    previewSpanClass(cols) {
                        const c = parseInt(cols, 10);
                        if (c === 1) return 'md:col-span-1';
                        if (c === 2) return 'md:col-span-2';
                        return 'md:col-span-3';
                    },

                    csrfToken() {
                        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
                    },

                    extractValidationErrors(payload) {
                        if (!payload || typeof payload.errors !== 'object' || payload.errors === null) {
                            return [];
                        }
                        const messages = [];
                        Object.values(payload.errors).forEach((fieldErrors) => {
                            if (Array.isArray(fieldErrors)) {
                                fieldErrors.forEach((msg) => {
                                    if (typeof msg === 'string' && msg !== '') {
                                        messages.push(msg);
                                    }
                                });
                            }
                        });
                        return messages;
                    },

                    serializeWidgets() {
                        return this.activeWidgets.map((w) => ({
                            key: w.key,
                            cols: parseInt(w.cols, 10) || 3,
                            config: w.config || {},
                        }));
                    },

                    async saveLayout() {
                        if (this.saving) {
                            return;
                        }

                        const target = this.currentTarget();
                        if (!target) {
                            this.errorMessage = 'Target layout tidak ditemukan.';
                            return;
                        }

                        this.clearMessages();
                        this.saving = true;
                        this.savingAction = 'save';

                        try {
                            const response = await fetch("{{ route('dashboard.layout.update') }}", {
                                method: 'PUT',
                                credentials: 'same-origin',
                                headers: {
                                    'Accept': 'application/json',
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': this.csrfToken(),
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                                body: JSON.stringify({
                                    role_slug: target.role_slug,
                                    division_id: target.division_id,
                                    layout_key: target.layout_key,
                                    widgets: this.serializeWidgets(),
                                }),
                            });

                            if (response.status === 401 || response.status === 419) {
                                window.location.href = "{{ route('login') }}";
                                return;
                            }

                            const payload = await response.json().catch(() => ({}));

                            if (response.status === 422) {
                                this.errorMessage = payload.message || 'Data susunan widget tidak valid.';
                                this.validationErrors = this.extractValidationErrors(payload);
                                this.showToast(this.errorMessage, 'error');
                                return;
                            }

                            if (!response.ok) {
                                this.errorMessage = payload.message || `Gagal menyimpan layout (HTTP ${response.status}).`;
                                this.showToast(this.errorMessage, 'error');
                                return;
                            }

                            const savedWidgets = Array.isArray(payload.widgets) ? payload.widgets : this.serializeWidgets();
                            target.active_widgets = [...savedWidgets];
                            target.is_customized = true;
                            this.activeWidgets = savedWidgets.map((w) => this.normalizeItem(w));
                            this.statusMessage = payload.message || 'Layout dashboard berhasil disimpan.';
                            this.showToast(this.statusMessage, 'success');

                            if (target.layout_key === this.currentUserLayoutKey) {
                                window.location.reload();
                            }
                        } catch (e) {
                            this.errorMessage = 'Terjadi gangguan jaringan saat menyimpan layout.';
                            this.showToast(this.errorMessage, 'error');
                        } finally {
                            this.saving = false;
                            this.savingAction = '';
                        }
                    },

                    async resetLayout() {
                        if (this.saving) {
                            return;
                        }

                        const target = this.currentTarget();
                        if (!target) {
                            this.errorMessage = 'Target layout tidak ditemukan.';
                            return;
                        }

                        this.clearMessages();
                        this.saving = true;
                        this.savingAction = 'reset';

                        try {
                            const response = await fetch("{{ route('dashboard.layout.reset') }}", {
                                method: 'DELETE',
                                credentials: 'same-origin',
                                headers: {
                                    'Accept': 'application/json',
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': this.csrfToken(),
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                                body: JSON.stringify({
                                    role_slug: target.role_slug,
                                    division_id: target.division_id,
                                    layout_key: target.layout_key,
                                }),
                            });

                            if (response.status === 401 || response.status === 419) {
                                window.location.href = "{{ route('login') }}";
                                return;
                            }

                            const payload = await response.json().catch(() => ({}));

                            if (response.status === 422) {
                                this.errorMessage = payload.message || 'Permintaan reset layout tidak valid.';
                                this.validationErrors = this.extractValidationErrors(payload);
                                this.showToast(this.errorMessage, 'error');
                                return;
                            }

                            if (!response.ok) {
                                this.errorMessage = payload.message || `Gagal mereset layout (HTTP ${response.status}).`;
                                this.showToast(this.errorMessage, 'error');
                                return;
                            }

                            this.disposeAllPreviewCharts();
                            const restoredWidgets = Array.isArray(payload.widgets)
                                ? payload.widgets
                                : [...(target.fallback_widgets || [])];
                            target.active_widgets = [...restoredWidgets];
                            target.is_customized = false;
                            this.activeWidgets = restoredWidgets.map((w) => this.normalizeItem(w));
                            this.statusMessage = payload.message || 'Layout dashboard dikembalikan ke bawaan.';
                            this.showToast(this.statusMessage, 'success');
                            this.resizePreviewCharts();

                            if (target.layout_key === this.currentUserLayoutKey) {
                                window.location.reload();
                            }
                        } catch (e) {
                            this.errorMessage = 'Terjadi gangguan jaringan saat mereset layout.';
                            this.showToast(this.errorMessage, 'error');
                        } finally {
                            this.saving = false;
                            this.savingAction = '';
                        }
                    },
                };
            };
        }
    </script>
@endcan
