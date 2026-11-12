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
        @keydown.escape.window="cancelDrag()"
        @resize.window="viewportWidth = window.innerWidth; resizePreviewCharts()"
        class="w-full"
    >
        {{-- Floating Drag Proxy (mengikuti pointer saat Pointer Events drag berlangsung) --}}
        <div
            x-show="pointerDrag.active && pointerDrag.item"
            x-cloak
            class="pointer-events-none fixed z-50 rounded-lg border-2 border-accent bg-white/95 px-3.5 py-2.5 shadow-xl select-none"
            :style="`left: ${pointerDrag.x}px; top: ${pointerDrag.y}px; width: ${pointerDrag.width}px; transform: translate3d(-50%, -50%, 0) scale(1.02); opacity: 0.95;`"
        >
            <div class="flex items-center gap-2">
                <span class="inline-flex h-5 w-5 items-center justify-center rounded bg-accent/15 text-accent">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16" />
                    </svg>
                </span>
                <span class="truncate text-xs font-bold text-primary" x-text="pointerDrag.item ? (pointerDrag.item.key === 'custom-chart' ? (pointerDrag.item.config?.title || widgetLabel(pointerDrag.item.key)) : widgetLabel(pointerDrag.item.key)) : ''"></span>
                <span class="ml-auto shrink-0 rounded bg-primary/10 px-1.5 py-0.5 text-[10px] font-semibold text-primary" x-text="`${pointerDrag.item?.cols || 3} Kolom`"></span>
            </div>
        </div>

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

        {{-- Status feedback AJAX (sukses / validasi 422 / error server) --}}
        <div
            x-show="saving"
            x-cloak
            class="mb-4 rounded-lg border border-sky-200 bg-sky-50 px-3.5 py-2.5 text-xs font-medium text-sky-800"
            role="status"
        >
            <span x-text="savingAction === 'reset' ? 'Mereset layout ke konfigurasi bawaan...' : 'Menyimpan perubahan layout...'"></span>
        </div>

        <div
            x-show="!saving && statusMessage !== ''"
            x-cloak
            class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3.5 py-2.5 text-xs font-medium text-emerald-800"
            role="status"
            x-text="statusMessage"
        ></div>

        <div
            x-show="!saving && (errorMessage !== '' || validationErrors.length > 0)"
            x-cloak
            class="mb-4 rounded-lg border border-red-200 bg-red-50 px-3.5 py-2.5 text-xs text-red-800"
            role="alert"
        >
            <p x-show="errorMessage !== ''" class="font-semibold" x-text="errorMessage"></p>
            <ul x-show="validationErrors.length > 0" class="mt-1 list-inside list-disc space-y-0.5">
                <template x-for="(err, idx) in validationErrors" :key="idx">
                    <li x-text="err"></li>
                </template>
            </ul>
        </div>

        {{-- Block 1 (Top — #builder-top-bar): Horizontal 1-row bar --}}
        <div id="builder-top-bar" class="w-full mb-6 flex flex-col gap-4 rounded-xl border border-slate-200 bg-slate-50/80 p-4 shadow-2xs md:flex-row md:items-center md:justify-between">
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-2">
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z" />
                        </svg>
                    </span>
                    <h3 class="text-sm font-bold text-primary">{{ $widget['label'] ?? 'Editor Tata Letak' }}</h3>
                    <span
                        class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold"
                        :class="isCustomized() ? 'bg-accent/15 text-primary' : 'bg-slate-100 text-slate-600'"
                        x-text="isCustomized() ? 'Kustom (Database)' : 'Bawaan (Fallback)'"
                    >
                         (Konfigurasi Bawaan)
                    </span>
                    <span
                        x-show="isDirty()"
                        x-cloak
                        class="inline-flex items-center gap-1 rounded-full border border-amber-300 bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-800"
                    >
                        <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                        Belum Disimpan
                    </span>
                </div>

                <div class="flex items-center gap-2">
                    <label for="layout-editor-target" class="text-xs font-semibold uppercase tracking-wide text-slate-500 whitespace-nowrap">
                        Target Layout (Role / Divisi):
                    </label>
                    <select
                        id="layout-editor-target"
                        name="target_key"
                        x-model="selectedTargetKey"
                        @change="selectTarget(selectedTargetKey)"
                        :disabled="saving"
                        class="h-9 rounded-lg border border-slate-200 bg-white px-3 text-xs font-medium text-primary outline-none focus:border-accent focus:ring-2 focus:ring-accent/20 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        @foreach ($targets as $target)
                            <option value="{{ $target['layout_key'] }}">
                                {{ $target['label'] }} ({{ $target['layout_key'] }})
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2 md:justify-end">
                <div class="flex items-center gap-1.5 mr-1">
                    <span class="rounded-lg bg-primary/10 px-2.5 py-1 text-xs font-bold text-primary" x-text="`${activeWidgets.length} Widget`"></span>
                    <span class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700" x-text="`${activeWidgets.reduce((acc, w) => acc + (parseInt(w.cols, 10) || 3), 0)} Kolom Grid`"></span>
                </div>

                <button
                    type="button"
                    @click="resetLayout()"
                    :disabled="saving"
                    title="Reset ke Default"
                    class="inline-flex h-9 items-center justify-center rounded-lg border border-slate-300 bg-white px-3 text-xs font-semibold text-primary transition hover:border-accent hover:text-accent disabled:cursor-not-allowed disabled:opacity-50"
                >
                    Reset ke Bawaan
                </button>

                <button
                    type="button"
                    @click="saveLayout()"
                    :disabled="saving || activeWidgets.length === 0"
                    title="Simpan Tata Letak"
                    :class="isDirty() ? 'bg-accent text-white shadow-xs hover:bg-accent/90 ring-2 ring-accent/30 font-bold' : 'bg-primary text-white hover:bg-accent font-semibold'"
                    class="inline-flex h-9 items-center justify-center gap-1.5 rounded-lg px-3.5 text-xs transition disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4" />
                    </svg>
                    <span>Simpan Tata Letak</span>
                    <span class="sr-only">Simpan Layout</span>
                </button>
            </div>
        </div>

        {{-- Block 2 (Middle — #builder-preview-pane): Full-Width 100% Landscape Pratinjau --}}
        <div id="builder-preview-pane" class="w-full mb-6">
            <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 shadow-2xs">
                <div class="flex items-center justify-between border-b border-slate-200 pb-3 mb-3">
                    <div class="flex items-center gap-2">
                        <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-primary/10 text-primary">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
                            </svg>
                        </span>
                        <h4 class="text-sm font-bold uppercase tracking-wide text-primary">Pratinjau Tata Letak</h4>
                        <span class="text-xs text-slate-500">Kanvas Grid 3-Kolom Responsif</span>
                    </div>
                    <div class="flex items-center gap-2 text-xs text-slate-500">
                        <span class="hidden sm:inline">Tarik kartu atau tombol panah untuk memindahkan urutan</span>
                    </div>
                </div>

                {{-- Viewport pratinjau kanvas --}}
                <div
                    id="preview-canvas-viewport"
                    @dragover="autoScrollPreview($event)"
                    @dragleave="onViewportDragLeave($event)"
                    @drop.prevent="isDragOverCanvas = false; onCanvasDrop($event)"
                    :class="isDragOverCanvas ? 'ring-2 ring-accent bg-accent/5' : 'bg-slate-100'"
                    class="min-h-[300px] rounded-xl border border-slate-300 p-4 transition"
                >
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                            <template x-for="(w, index) in activeWidgets" :key="w._uid || index">
                                <div
                                    @dragover.prevent="onCardDragOver($event, index)"
                                    @dragleave="onCardDragLeave($event, index)"
                                    @drop.prevent.stop="onCardDrop($event, index)"
                                    :class="[
                                        previewSpanClass(w.cols),
                                        canvasDragIndex === index ? 'opacity-40 border-dashed border-accent' : 'border-slate-200',
                                        (dropTargetIndex === index && (canvasDragIndex !== index || paletteDragKey)) ? 'ring-2 ring-accent ring-offset-1' : ''
                                    ]"
                                    class="preview-card-item relative flex min-w-0 min-h-[175px] flex-col justify-between rounded-xl border bg-white p-4 shadow-xs transition hover:shadow-md"
                                >
                                    {{-- Indikator garis posisi drop (sebelum / sesudah) --}}
                                    <div
                                        x-show="dropTargetIndex === index && (canvasDragIndex !== index || paletteDragKey)"
                                        x-cloak
                                        :class="dropPlacement === 'before' ? '-top-2 left-1 right-1 h-1.5' : '-bottom-2 left-1 right-1 h-1.5'"
                                        class="pointer-events-none absolute z-30 rounded-full bg-accent shadow"
                                    ></div>
                                    <div class="canvas-card-toolbar flex flex-wrap items-center justify-between gap-1.5 border-b border-slate-100 pb-2" :class="(canvasDragIndex !== null || paletteDragKey) ? 'pointer-events-none' : ''">
                                        <div class="flex min-w-0 items-center gap-1.5 pointer-events-auto max-w-full">
                                            <span
                                                @pointerdown="onCardPointerDown($event, index)"
                                                class="canvas-drag-handle inline-flex h-7 w-7 shrink-0 cursor-grab items-center justify-center rounded-lg bg-slate-100 text-slate-600 transition hover:bg-slate-200 active:cursor-grabbing select-none touch-none"
                                                title="Tarik untuk memindahkan urutan widget"
                                            >
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16" />
                                                </svg>
                                            </span>
                                            <span class="inline-flex h-6 min-w-6 items-center justify-center rounded-md bg-primary/10 px-1.5 text-[11px] font-bold text-primary select-none" x-text="`#${index + 1}`" title="Posisi widget"></span>
                                            <p class="truncate text-xs font-bold text-primary select-none" x-text="w.key === 'custom-chart' ? (w.config?.title || widgetLabel(w.key)) : widgetLabel(w.key)"></p>
                                        </div>

                                        <div class="flex flex-wrap items-center gap-1 pointer-events-auto">
                                            {{-- Atur Lebar Selector --}}
                                            <div class="flex items-center rounded-lg border border-slate-200 bg-slate-50/80 p-0.5 text-xs font-medium shadow-2xs shrink-0 flex-nowrap" title="Atur Lebar Kolom">
                                                <span class="px-1 text-[10px] font-bold uppercase tracking-wider text-slate-500 shrink-0" title="Lebar Kolom">Atur Lebar:</span>
                                                <template x-for="n in [1, 2, 3]" :key="n">
                                                    <button
                                                        type="button"
                                                        draggable="false"
                                                        @mousedown.stop
                                                        @pointerdown.stop
                                                        @click="setWidgetCols(w, n)"
                                                        :aria-label="`Lebar ${n} kolom (${n === 1 ? '33%' : (n === 2 ? '66%' : '100%')})`"
                                                        :class="(w.cols === n) ? 'bg-primary text-white shadow-xs' : 'text-slate-600 hover:bg-slate-100 hover:text-primary'"
                                                        class="inline-flex items-center gap-0.5 rounded px-1.5 py-0.5 text-[11px] font-semibold transition shrink-0"
                                                    >
                                                        <span x-text="`${n} Kol`"></span>
                                                        <span class="text-[9px] opacity-75 font-normal" :class="w.cols === 1 ? 'hidden' : 'inline'" x-text="n === 1 ? '(33%)' : (n === 2 ? '(66%)' : '(100%)')"></span>
                                                    </button>
                                                </template>
                                            </div>

                                            <button
                                                type="button"
                                                draggable="false"
                                                @mousedown.stop
                                                @pointerdown.stop
                                                @click="moveUp(index)"
                                                :disabled="saving || index === 0"
                                                class="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-slate-200 bg-white text-xs font-bold text-slate-600 hover:border-accent hover:text-accent disabled:cursor-not-allowed disabled:opacity-40 transition"
                                                :aria-label="`Geser ke kiri atau atas ${widgetLabel(w.key)}`"
                                                title="Geser ke kiri / atas"
                                            >
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                                                </svg>
                                            </button>
                                            <button
                                                type="button"
                                                draggable="false"
                                                @mousedown.stop
                                                @pointerdown.stop
                                                @click="moveDown(index)"
                                                :disabled="saving || index === activeWidgets.length - 1"
                                                class="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-slate-200 bg-white text-xs font-bold text-slate-600 hover:border-accent hover:text-accent disabled:cursor-not-allowed disabled:opacity-40 transition"
                                                :aria-label="`Geser ke kanan atau bawah ${widgetLabel(w.key)}`"
                                                title="Geser ke kanan / bawah"
                                            >
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                                </svg>
                                            </button>

                                            <template x-if="w.key === 'custom-chart'">
                                                <button
                                                    type="button"
                                                    draggable="false"
                                                    @mousedown.stop
                                                    @pointerdown.stop
                                                    @click="w._openConfig = !w._openConfig"
                                                    class="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:text-primary hover:border-accent transition"
                                                    :title="w._openConfig ? 'Tutup Pengaturan' : 'Atur Grafik'"
                                                    :aria-label="`Atur grafik ${widgetLabel(w.key)}`"
                                                >
                                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                    </svg>
                                                </button>
                                            </template>

                                            <button
                                                type="button"
                                                draggable="false"
                                                @mousedown.stop
                                                @pointerdown.stop
                                                @click="removeWidget(index)"
                                                :disabled="saving || activeWidgets.length <= 1 || isLockedWidget(w.key)"
                                                class="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-red-200 bg-red-50 text-red-600 hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-40 transition"
                                                :aria-label="`Hapus ${widgetLabel(w.key)}`"
                                                :title="isLockedWidget(w.key) ? 'Widget wajib ada untuk peran ini' : 'Hapus widget dari dashboard'"
                                            >
                                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>

                                    {{-- Panel Konfigurasi Inline untuk Custom Chart --}}
                                    <template x-if="w.key === 'custom-chart' && w._openConfig">
                                        <div class="mt-2.5 space-y-2 rounded-lg border border-slate-200 bg-slate-50/80 p-2.5 text-xs">
                                            <div>
                                                <label class="mb-1 block font-semibold text-slate-600">Judul Grafik</label>
                                                <input
                                                    type="text"
                                                    x-model="w.config.title"
                                                    maxlength="60"
                                                    class="w-full rounded border border-slate-300 bg-white px-2 py-1 text-xs text-primary outline-none focus:border-accent"
                                                    placeholder="Contoh: Distribusi Skor NKO"
                                                />
                                            </div>
                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
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

                                    {{-- Live In-Canvas WYSIWYG Previews untuk Interactive Charts --}}
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

                                    {{-- High-Fidelity Representative Visual Mini-Mockups (Tailwind/SVG) --}}
                                    <div class="preview-sketch mt-2 flex flex-col justify-between rounded-lg border border-slate-100 bg-slate-50/70 p-3 shadow-2xs" data-mini-mockup="true">
                                        {{-- 1. nko-ranking (Bar Chart Mockup) --}}
                                        <template x-if="w.key === 'nko-ranking'">
                                            <div class="space-y-1.5">
                                                <div class="flex items-center justify-between text-[11px] font-semibold text-slate-700">
                                                    <span class="flex items-center gap-1.5 truncate">
                                                        <span class="h-2 w-2 rounded-full bg-teal-500 shrink-0"></span>
                                                        1. TJBT (Jawa Bagian Tengah)
                                                    </span>
                                                    <span class="rounded bg-teal-50 px-1.5 py-0.5 font-bold text-teal-700 shrink-0">105.5</span>
                                                </div>
                                                <div class="h-2 w-full overflow-hidden rounded-full bg-slate-200">
                                                    <div class="h-full rounded-full bg-teal-600" style="width: 100%;"></div>
                                                </div>
                                                <div class="flex items-center justify-between text-[11px] font-semibold text-slate-700">
                                                    <span class="flex items-center gap-1.5 truncate">
                                                        <span class="h-2 w-2 rounded-full bg-cyan-500 shrink-0"></span>
                                                        2. TRANS (Transmisi)
                                                    </span>
                                                    <span class="rounded bg-cyan-50 px-1.5 py-0.5 font-bold text-cyan-700 shrink-0">100.7</span>
                                                </div>
                                                <div class="h-2 w-full overflow-hidden rounded-full bg-slate-200">
                                                    <div class="h-full rounded-full bg-cyan-600" style="width: 95%;"></div>
                                                </div>
                                                <div class="flex items-center justify-between text-[11px] font-semibold text-slate-600">
                                                    <span class="flex items-center gap-1.5 truncate">
                                                        <span class="h-2 w-2 rounded-full bg-slate-400 shrink-0"></span>
                                                        3. UPT Semarang
                                                    </span>
                                                    <span class="rounded bg-slate-100 px-1.5 py-0.5 font-bold text-slate-600 shrink-0">99.4</span>
                                                </div>
                                                <div class="h-2 w-full overflow-hidden rounded-full bg-slate-200">
                                                    <div class="h-full rounded-full bg-slate-400" style="width: 88%;"></div>
                                                </div>
                                            </div>
                                        </template>

                                        {{-- 2. aset-upt-chart (Bar Chart Mockup) --}}
                                        <template x-if="w.key === 'aset-upt-chart'">
                                            <div class="space-y-2">
                                                <div class="flex items-center justify-between text-[11px]">
                                                    <span class="font-bold text-slate-700 truncate">Rekapitulasi 8 UPT</span>
                                                    <span class="rounded bg-teal-50 px-1.5 py-0.5 text-[9px] font-bold text-teal-700 border border-teal-200 shrink-0">1.824 Trafo &bull; 86.420 MVA</span>
                                                </div>
                                                <svg class="h-16 w-full" viewBox="0 0 240 60" fill="none">
                                                    <line x1="10" y1="52" x2="230" y2="52" stroke="#cbd5e1" stroke-width="1" />
                                                    <rect x="20" y="16" width="16" height="36" rx="2" fill="#0d9488" />
                                                    <rect x="38" y="24" width="16" height="28" rx="2" fill="#06b6d4" />
                                                    <rect x="74" y="10" width="16" height="42" rx="2" fill="#0d9488" />
                                                    <rect x="92" y="18" width="16" height="34" rx="2" fill="#06b6d4" />
                                                    <rect x="128" y="22" width="16" height="30" rx="2" fill="#0d9488" />
                                                    <rect x="146" y="30" width="16" height="22" rx="2" fill="#06b6d4" />
                                                    <rect x="182" y="14" width="16" height="38" rx="2" fill="#0d9488" />
                                                    <rect x="200" y="26" width="16" height="26" rx="2" fill="#06b6d4" />
                                                </svg>
                                                <div class="flex items-center justify-between text-[10px] text-slate-500 px-2">
                                                    <span>Smg</span><span>Skr</span><span>Pwt</span><span>Ygy</span>
                                                </div>
                                            </div>
                                        </template>

                                        {{-- 3. target-realisasi-per-indikator (Bar Chart Mockup) --}}
                                        <template x-if="w.key === 'target-realisasi-per-indikator'">
                                            <div class="space-y-2">
                                                <div class="flex items-center justify-between text-[11px]">
                                                    <span class="font-bold text-slate-700 truncate">Target vs Realisasi Indikator</span>
                                                    <span class="rounded bg-emerald-50 px-1.5 py-0.5 text-[9px] font-bold text-emerald-700 border border-emerald-200 shrink-0">Terstruktur</span>
                                                </div>
                                                <div class="space-y-1.5 text-[11px]">
                                                    <div>
                                                        <div class="flex justify-between text-slate-600 mb-0.5">
                                                            <span class="truncate">Kesiapan Pembangkit</span>
                                                            <span class="font-bold text-teal-700 shrink-0">98.5% / 100%</span>
                                                        </div>
                                                        <div class="h-2 w-full rounded-full bg-slate-200 overflow-hidden flex">
                                                            <div class="bg-teal-600 h-full rounded-full" style="width: 98.5%;"></div>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <div class="flex justify-between text-slate-600 mb-0.5">
                                                            <span class="truncate">Keandalan Transmisi</span>
                                                            <span class="font-bold text-teal-700 shrink-0">104.2% / 100%</span>
                                                        </div>
                                                        <div class="h-2 w-full rounded-full bg-slate-200 overflow-hidden flex">
                                                            <div class="bg-teal-600 h-full rounded-full" style="width: 100%;"></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>

                                        {{-- 4. nko-donut (Donut Mockup) --}}
                                        <template x-if="w.key === 'nko-donut'">
                                            <div class="flex items-center justify-between gap-3">
                                                <div class="relative flex h-20 w-20 shrink-0 items-center justify-center">
                                                    <svg class="h-20 w-20 -rotate-90" viewBox="0 0 36 36">
                                                        <path class="text-slate-100" stroke-width="4.5" stroke="currentColor" fill="none" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                                                        <path class="text-emerald-500" stroke-dasharray="65, 100" stroke-width="4.5" stroke-linecap="round" stroke="currentColor" fill="none" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                                                        <path class="text-amber-500" stroke-dasharray="25, 100" stroke-dashoffset="-65" stroke-width="4.5" stroke-linecap="round" stroke="currentColor" fill="none" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                                                        <path class="text-teal-600" stroke-dasharray="10, 100" stroke-dashoffset="-90" stroke-width="4.5" stroke-linecap="round" stroke="currentColor" fill="none" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                                                    </svg>
                                                    <div class="absolute inset-0 flex flex-col items-center justify-center text-center">
                                                        <span class="text-xs font-bold text-primary">105.4</span>
                                                        <span class="text-[8px] font-semibold text-slate-400">NKO</span>
                                                    </div>
                                                </div>
                                                <div class="flex flex-1 flex-col justify-center space-y-1 text-[11px]">
                                                    <div class="flex items-center justify-between"><span class="flex items-center gap-1.5 text-slate-600 truncate"><span class="h-2 w-2 rounded-full bg-emerald-500 shrink-0"></span>Tercapai</span><span class="font-bold text-emerald-700 shrink-0">16 Indikator</span></div>
                                                    <div class="flex items-center justify-between"><span class="flex items-center gap-1.5 text-slate-600 truncate"><span class="h-2 w-2 rounded-full bg-amber-500 shrink-0"></span>Hampir</span><span class="font-bold text-amber-700 shrink-0">3 Indikator</span></div>
                                                    <div class="flex items-center justify-between"><span class="flex items-center gap-1.5 text-slate-600 truncate"><span class="h-2 w-2 rounded-full bg-teal-600 shrink-0"></span>Perhatian</span><span class="font-bold text-teal-800 shrink-0">2 Indikator</span></div>
                                                </div>
                                            </div>
                                        </template>

                                        {{-- 5. kpi-rag-status (Donut / Tile Status Mockup) --}}
                                        <template x-if="w.key === 'kpi-rag-status'">
                                            <div class="grid grid-cols-3 gap-2">
                                                <div class="rounded-lg border border-emerald-200 bg-emerald-50/80 p-2 text-center">
                                                    <span class="block text-[10px] font-bold uppercase tracking-wider text-emerald-700">Tercapai</span>
                                                    <span class="text-base font-extrabold text-emerald-950">16</span>
                                                    <span class="block text-[9px] text-emerald-600">&ge; 100%</span>
                                                </div>
                                                <div class="rounded-lg border border-amber-200 bg-amber-50/80 p-2 text-center">
                                                    <span class="block text-[10px] font-bold uppercase tracking-wider text-amber-700">Hampir</span>
                                                    <span class="text-base font-extrabold text-amber-950">3</span>
                                                    <span class="block text-[9px] text-amber-600">95 - 99%</span>
                                                </div>
                                                <div class="rounded-lg border border-teal-200 bg-teal-50/80 p-2 text-center">
                                                    <span class="block text-[10px] font-bold uppercase tracking-wider text-teal-700">Perhatian</span>
                                                    <span class="text-base font-extrabold text-teal-950">2</span>
                                                    <span class="block text-[9px] text-teal-600">&lt; 95%</span>
                                                </div>
                                            </div>
                                        </template>

                                        {{-- 6. kpi-summary (Scorecard Mockup) --}}
                                        <template x-if="w.key === 'kpi-summary'">
                                            <div class="grid grid-cols-3 gap-2">
                                                <div class="rounded-lg border border-sky-100 bg-sky-50/80 p-2">
                                                    <p class="text-[10px] font-bold uppercase tracking-wide text-sky-700 truncate">Rerata NKO</p>
                                                    <p class="mt-0.5 text-base font-bold text-sky-950">105.4%</p>
                                                    <div class="mt-1 h-1 w-full rounded-full bg-sky-200"><div class="h-full rounded-full bg-sky-600" style="width: 100%;"></div></div>
                                                </div>
                                                <div class="rounded-lg border border-emerald-100 bg-emerald-50/80 p-2">
                                                    <p class="text-[10px] font-bold uppercase tracking-wide text-emerald-700 truncate">Indikator</p>
                                                    <p class="mt-0.5 text-base font-bold text-emerald-950">16 Tercapai</p>
                                                    <div class="mt-1 h-1 w-full rounded-full bg-emerald-200"><div class="h-full rounded-full bg-emerald-600" style="width: 80%;"></div></div>
                                                </div>
                                                <div class="rounded-lg border border-amber-100 bg-amber-50/80 p-2">
                                                    <p class="text-[10px] font-bold uppercase tracking-wide text-amber-700 truncate">Periode</p>
                                                    <p class="mt-0.5 text-xs font-bold text-amber-950">Agu 2026</p>
                                                    <span class="mt-1 inline-block rounded bg-amber-200/60 px-1 text-[9px] font-bold text-amber-800">Final</span>
                                                </div>
                                            </div>
                                        </template>

                                        {{-- 7. dataset-status (Scorecard / Status Mockup) --}}
                                        <template x-if="w.key === 'dataset-status'">
                                            <div class="grid grid-cols-3 gap-2 text-center">
                                                <div class="rounded-lg border border-emerald-200 bg-emerald-50/70 p-2">
                                                    <span class="block text-[10px] font-bold uppercase tracking-wide text-emerald-700">Status</span>
                                                    <span class="text-sm font-extrabold text-emerald-900">READY</span>
                                                    <span class="block text-[9px] text-emerald-600 mt-0.5">12 Dataset</span>
                                                </div>
                                                <div class="rounded-lg border border-slate-200 bg-white p-2">
                                                    <span class="block text-[10px] font-bold uppercase tracking-wide text-slate-500">Baris Data</span>
                                                    <span class="text-sm font-extrabold text-primary">1.420</span>
                                                    <span class="block text-[9px] text-slate-400 mt-0.5">Aktif TJBT</span>
                                                </div>
                                                <div class="rounded-lg border border-slate-200 bg-white p-2">
                                                    <span class="block text-[10px] font-bold uppercase tracking-wide text-slate-500">Periode</span>
                                                    <span class="text-xs font-bold text-primary mt-0.5">Agu 2026</span>
                                                    <span class="block text-[9px] text-teal-600 font-semibold mt-0.5">Terverifikasi</span>
                                                </div>
                                            </div>
                                        </template>

                                        {{-- 8. pencapaian-nko (Scorecard / Bar Mockup) --}}
                                        <template x-if="w.key === 'pencapaian-nko'">
                                            <div class="space-y-1.5">
                                                <div class="flex items-center justify-between text-[11px]">
                                                    <span class="font-bold text-slate-700 truncate">Pencapaian % per Unit</span>
                                                    <span class="text-[10px] font-bold text-teal-700 shrink-0">Rerata 104.2%</span>
                                                </div>
                                                <div class="space-y-1">
                                                    <div class="flex items-center justify-between text-[10px]">
                                                        <span class="w-16 truncate text-slate-600">TJBT</span>
                                                        <div class="h-2 flex-1 mx-2 rounded-full bg-slate-200 overflow-hidden"><div class="h-full bg-teal-600 rounded-full" style="width: 100%;"></div></div>
                                                        <span class="font-bold text-teal-700 shrink-0">105.5%</span>
                                                    </div>
                                                    <div class="flex items-center justify-between text-[10px]">
                                                        <span class="w-16 truncate text-slate-600">TRANS</span>
                                                        <div class="h-2 flex-1 mx-2 rounded-full bg-slate-200 overflow-hidden"><div class="h-full bg-cyan-600 rounded-full" style="width: 96%;"></div></div>
                                                        <span class="font-bold text-cyan-700 shrink-0">100.7%</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>

                                        {{-- 9. admin-quick-actions (Scorecard / Actions Mockup) --}}
                                        <template x-if="w.key === 'admin-quick-actions'">
                                            <div class="grid grid-cols-3 gap-2">
                                                <div class="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white p-2 text-left">
                                                    <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-teal-50 text-teal-700">
                                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" /></svg>
                                                    </div>
                                                    <div class="min-w-0"><p class="truncate text-[10px] font-bold text-primary">Upload</p><p class="text-[8px] text-slate-400">Dataset</p></div>
                                                </div>
                                                <div class="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white p-2 text-left">
                                                    <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-indigo-50 text-indigo-700">
                                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                                                    </div>
                                                    <div class="min-w-0"><p class="truncate text-[10px] font-bold text-primary">Audit</p><p class="text-[8px] text-slate-400">Log</p></div>
                                                </div>
                                                <div class="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white p-2 text-left">
                                                    <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-slate-100 text-slate-700">
                                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
                                                    </div>
                                                    <div class="min-w-0"><p class="truncate text-[10px] font-bold text-primary">User</p><p class="text-[8px] text-slate-400">Kelola</p></div>
                                                </div>
                                            </div>
                                        </template>

                                        {{-- 10. admin-summary (Scorecard Mockup) --}}
                                        <template x-if="w.key === 'admin-summary'">
                                            <div class="grid grid-cols-3 gap-2">
                                                <div class="rounded-lg border border-slate-200 bg-white p-2">
                                                    <span class="block text-[9px] font-bold uppercase tracking-wider text-slate-500 truncate">Total Dataset</span>
                                                    <span class="mt-0.5 text-sm font-extrabold text-primary">24</span>
                                                    <span class="block text-[8px] text-emerald-600 font-semibold mt-0.5">22 Siap</span>
                                                </div>
                                                <div class="rounded-lg border border-slate-200 bg-white p-2">
                                                    <span class="block text-[9px] font-bold uppercase tracking-wider text-slate-500 truncate">Pengguna</span>
                                                    <span class="mt-0.5 text-sm font-extrabold text-primary">18</span>
                                                    <span class="block text-[8px] text-sky-600 font-semibold mt-0.5">4 Peran</span>
                                                </div>
                                                <div class="rounded-lg border border-slate-200 bg-white p-2">
                                                    <span class="block text-[9px] font-bold uppercase tracking-wider text-slate-500 truncate">Sistem</span>
                                                    <span class="mt-0.5 text-sm font-extrabold text-emerald-700">Optimal</span>
                                                    <span class="block text-[8px] text-slate-400 mt-0.5">Audit Aman</span>
                                                </div>
                                            </div>
                                        </template>

                                        {{-- 11. kinerja-detail (Table Mockup) --}}
                                        <template x-if="w.key === 'kinerja-detail'">
                                            <div class="overflow-hidden rounded-md border border-slate-200 text-[10px]">
                                                <div class="grid grid-cols-12 bg-slate-100 px-2 py-1 font-bold text-slate-600 uppercase tracking-wider">
                                                    <span class="col-span-6 truncate">Indikator Metrik</span>
                                                    <span class="col-span-3 text-right">Target</span>
                                                    <span class="col-span-3 text-right">Realisasi</span>
                                                </div>
                                                <div class="divide-y divide-slate-100 bg-white">
                                                    <div class="grid grid-cols-12 px-2 py-1 items-center">
                                                        <span class="col-span-6 truncate font-medium text-slate-700">Kesiapan Pembangkit</span>
                                                        <span class="col-span-3 text-right text-slate-500">100.0</span>
                                                        <span class="col-span-3 text-right font-bold text-teal-700">98.5</span>
                                                    </div>
                                                    <div class="grid grid-cols-12 px-2 py-1 items-center bg-slate-50/50">
                                                        <span class="col-span-6 truncate font-medium text-slate-700">Keandalan Transmisi</span>
                                                        <span class="col-span-3 text-right text-slate-500">100.0</span>
                                                        <span class="col-span-3 text-right font-bold text-emerald-700">104.2</span>
                                                    </div>
                                                    <div class="grid grid-cols-12 px-2 py-1 items-center">
                                                        <span class="col-span-6 truncate font-medium text-slate-700">Efisiensi Operasi</span>
                                                        <span class="col-span-3 text-right text-slate-500">95.0</span>
                                                        <span class="col-span-3 text-right font-bold text-teal-700">96.8</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>

                                        {{-- 12. kinerja-realisasi (Table / Bar Mockup) --}}
                                        <template x-if="w.key === 'kinerja-realisasi'">
                                            <div class="space-y-1.5">
                                                <div class="flex items-center justify-between text-[11px] font-bold text-slate-700">
                                                    <span class="truncate">Realisasi vs Target Bulanan</span>
                                                    <span class="rounded bg-teal-50 px-1.5 py-0.5 text-[9px] text-teal-700 shrink-0">105.4% Rerata</span>
                                                </div>
                                                <div class="space-y-1 text-[10px]">
                                                    <div>
                                                        <div class="flex justify-between text-slate-600 mb-0.5">
                                                            <span class="truncate">Realisasi Operasi</span>
                                                            <span class="font-bold text-teal-700 shrink-0">105.5</span>
                                                        </div>
                                                        <div class="h-1.5 w-full rounded-full bg-slate-200 overflow-hidden"><div class="h-full bg-teal-600 rounded-full" style="width: 100%;"></div></div>
                                                    </div>
                                                    <div>
                                                        <div class="flex justify-between text-slate-600 mb-0.5">
                                                            <span class="truncate">Target Anggaran</span>
                                                            <span class="font-bold text-cyan-700 shrink-0">100.0</span>
                                                        </div>
                                                        <div class="h-1.5 w-full rounded-full bg-slate-200 overflow-hidden"><div class="h-full bg-cyan-600 rounded-full" style="width: 95%;"></div></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>

                                        {{-- 13. recent-audit (Table / Log Mockup) --}}
                                        <template x-if="w.key === 'recent-audit'">
                                            <div class="space-y-1 text-[10px]">
                                                <div class="flex items-center justify-between rounded border border-slate-100 bg-white p-1.5">
                                                    <div class="flex items-center gap-1.5 truncate">
                                                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 shrink-0"></span>
                                                        <span class="font-bold text-primary truncate">admin@dashboard.local</span>
                                                        <span class="rounded bg-slate-100 px-1 text-[8px] text-slate-600 shrink-0">UPDATE</span>
                                                    </div>
                                                    <span class="text-slate-400 shrink-0 text-[9px]">12:30</span>
                                                </div>
                                                <div class="flex items-center justify-between rounded border border-slate-100 bg-white p-1.5">
                                                    <div class="flex items-center gap-1.5 truncate">
                                                        <span class="h-1.5 w-1.5 rounded-full bg-sky-500 shrink-0"></span>
                                                        <span class="font-bold text-primary truncate">staf.aset@dashboard.local</span>
                                                        <span class="rounded bg-sky-50 px-1 text-[8px] text-sky-700 shrink-0">IMPORT</span>
                                                    </div>
                                                    <span class="text-slate-400 shrink-0 text-[9px]">11:15</span>
                                                </div>
                                                <div class="flex items-center justify-between rounded border border-slate-100 bg-white p-1.5">
                                                    <div class="flex items-center gap-1.5 truncate">
                                                        <span class="h-1.5 w-1.5 rounded-full bg-slate-400 shrink-0"></span>
                                                        <span class="font-bold text-primary truncate">editor@dashboard.local</span>
                                                        <span class="rounded bg-slate-100 px-1 text-[8px] text-slate-600 shrink-0">LAYOUT</span>
                                                    </div>
                                                    <span class="text-slate-400 shrink-0 text-[9px]">10:02</span>
                                                </div>
                                            </div>
                                        </template>

                                        {{-- 14. failed-datasets (Table / Alert Mockup) --}}
                                        <template x-if="w.key === 'failed-datasets'">
                                            <div class="space-y-1.5 text-[10px]">
                                                <div class="flex items-center justify-between rounded border border-red-100 bg-red-50/50 p-2">
                                                    <div class="flex items-center gap-2 truncate">
                                                        <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600 font-bold text-[10px]">!</span>
                                                        <div class="min-w-0">
                                                            <p class="truncate font-bold text-red-900">laporan-kpi-agustus.xlsx</p>
                                                            <p class="text-[9px] text-red-600 truncate">Header sheet tidak valid</p>
                                                        </div>
                                                    </div>
                                                    <span class="rounded bg-red-100 px-1.5 py-0.5 font-bold text-red-700 text-[8px] shrink-0">FAILED</span>
                                                </div>
                                                <div class="flex items-center justify-between text-[10px] text-slate-400 px-1">
                                                    <span class="truncate">Antrean bersih (1 kendala terarsip)</span>
                                                    <span class="font-semibold text-teal-600 shrink-0">Aman</span>
                                                </div>
                                            </div>
                                        </template>

                                        {{-- 15. layout-editor-placeholder (Representative Editor Mockup) --}}
                                        <template x-if="w.key === 'layout-editor-placeholder'">
                                            <div class="flex items-center justify-between rounded border border-slate-200 bg-white p-2.5 text-[11px]">
                                                <div class="flex items-center gap-2 truncate">
                                                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-primary/10 text-primary font-bold text-xs">BI</span>
                                                    <div class="min-w-0">
                                                        <p class="font-bold text-primary truncate">Editor Tata Letak</p>
                                                        <p class="text-[9px] text-slate-400 truncate">Drag & drop kanvas</p>
                                                    </div>
                                                </div>
                                                <span class="rounded bg-teal-50 px-2 py-0.5 text-[10px] font-bold text-teal-700 shrink-0">Aktif</span>
                                            </div>
                                        </template>

                                        {{-- 16. Fallback untuk custom-chart / trend-chart / generic --}}
                                        <template x-if="['custom-chart', 'trend-chart'].includes(w.key)">
                                            <div class="space-y-1">
                                                <div class="flex items-center justify-between text-[11px] font-semibold text-slate-700">
                                                    <span class="truncate" x-text="w.config?.title || widgetLabel(w.key)"></span>
                                                    <span class="rounded bg-primary/10 px-1.5 py-0.5 text-[9px] font-bold text-primary shrink-0" x-text="(w.config?.chart_type || 'chart').toUpperCase()"></span>
                                                </div>
                                                <div class="flex items-center justify-between text-[10px] text-slate-400">
                                                    <span class="truncate">Interaktif ECharts Rendered</span>
                                                    <span class="font-semibold text-teal-600 shrink-0">Siap</span>
                                                </div>
                                            </div>
                                        </template>

                                        {{-- Footer metadata di dalam preview-sketch --}}
                                        <div class="mt-2.5 flex items-center justify-between border-t border-slate-200/80 pt-1.5 text-[10px] text-slate-400">
                                            <span class="font-medium text-slate-600" x-text="paletteVisual(w.key).label"></span>
                                            <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[9px] font-semibold text-slate-500" x-text="`${w.cols} Kolom (${w.cols === 1 ? '33%' : (w.cols === 2 ? '66%' : '100%')})`"></span>
                                        </div>
                                    </div>
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
                            <p class="mt-1 text-xs text-slate-400">Tarik komponen dari Palet Komponen di bawah dan lepas di sini untuk memulai.</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Block 3 (Bottom — #builder-config-pane): Landscape Component Grid --}}
            <div id="builder-config-pane" class="w-full">
                <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-4 shadow-2xs">
                    {{-- Header: Komponen title & badge on left, category filter tabs on right --}}
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-b border-slate-200 pb-3">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                            </span>
                            <h4 class="text-xs font-bold uppercase tracking-wider text-primary">Tambah Komponen</h4>
                            <span class="inline-flex items-center rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-bold text-primary">
                                {{ count($catalog) }}
                            </span>
                            <span class="sr-only">Komponen</span>
                        </div>

                        {{-- Filter Kategori Domain (Tabs on the right of header) --}}
                        <div class="flex flex-wrap items-center gap-1.5 text-xs font-semibold">
                            <button
                                type="button"
                                @click="catalogFilterCategory = 'all'"
                                :class="catalogFilterCategory === 'all' ? 'bg-primary text-white shadow-xs' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                                class="rounded-lg px-2.5 py-1 text-xs transition"
                            >
                                Semua (<span x-text="catalog.length"></span>)
                            </button>
                            <button
                                type="button"
                                @click="catalogFilterCategory = 'Kinerja / KPI'"
                                :class="catalogFilterCategory === 'Kinerja / KPI' ? 'bg-teal-700 text-white shadow-xs' : 'bg-teal-50 text-teal-800 hover:bg-teal-100'"
                                class="rounded-lg px-2.5 py-1 text-xs transition"
                            >
                                Kinerja / KPI
                            </button>
                            <button
                                type="button"
                                @click="catalogFilterCategory = 'Manajemen Aset'"
                                :class="catalogFilterCategory === 'Manajemen Aset' ? 'bg-indigo-700 text-white shadow-xs' : 'bg-indigo-50 text-indigo-800 hover:bg-indigo-100'"
                                class="rounded-lg px-2.5 py-1 text-xs transition"
                            >
                                Manajemen Aset
                            </button>
                            <button
                                type="button"
                                @click="catalogFilterCategory = 'Operasional & Admin'"
                                :class="catalogFilterCategory === 'Operasional & Admin' ? 'bg-slate-700 text-white shadow-xs' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"
                                class="rounded-lg px-2.5 py-1 text-xs transition"
                            >
                                Operasional & Admin
                            </button>
                        </div>
                    </div>

                    {{-- Landscape Component Grid: grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3 --}}
                    <div class="mt-4 grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3" aria-label="Palet komponen">
                        <template x-for="item in filteredCatalog()" :key="item.key">
                            <div
                                draggable="true"
                                @dragstart="startPaletteDrag(item.key)"
                                @dragend="endPaletteDrag()"
                                class="group relative flex flex-col justify-between rounded-lg border border-slate-200 bg-white p-2.5 shadow-2xs transition hover:border-accent hover:shadow-xs cursor-grab active:cursor-grabbing"
                                :class="(item.key !== 'custom-chart' && hasWidget(item.key)) ? 'opacity-60 bg-slate-50' : ''"
                            >
                                <div>
                                    {{-- Miniature Icon / Visual --}}
                                    <div class="palette-thumb relative mb-2 flex h-11 w-full items-center justify-center rounded-md bg-slate-50 border border-slate-100 text-primary/80 group-hover:bg-primary/5 group-hover:border-primary/20 transition-colors overflow-hidden">
                                        @include('components.widgets.partials.widget-sketch', ['keyExpr' => 'item.key', 'sizeClass' => 'h-8 w-full'])
                                    </div>

                                    {{-- Type Label & Default Size Badge --}}
                                    <div class="flex items-center justify-between gap-1 mb-1">
                                        <span
                                            class="palette-type-label rounded bg-primary/10 px-1.5 py-0.5 text-[9px] font-semibold text-primary truncate"
                                            x-text="paletteVisual(item.key).label"
                                        ></span>
                                        <span
                                            class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[9px] font-semibold text-slate-600"
                                            x-text="`${item.default_cols || 3} Kol`"
                                        ></span>
                                    </div>

                                    {{-- Short Component Name --}}
                                    <p class="text-xs font-bold text-primary line-clamp-1" x-text="item.label"></p>
                                </div>

                                {{-- Action: Drag hint & + Tambah button (NO long descriptions!) --}}
                                <div class="mt-2.5 pt-2 border-t border-slate-100 flex items-center justify-between">
                                    <span class="text-[9px] text-slate-400 group-hover:text-accent font-medium flex items-center gap-1">
                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                                        </svg>
                                        Tarik
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
                    catalogFilterCategory: 'all',
                    selectedTargetKey: initialTarget ? initialTarget.layout_key : '',
                    activeWidgets: [],
                    saving: false,
                    savingAction: '',
                    statusMessage: '',
                    errorMessage: '',
                    validationErrors: [],
                    dragIndex: null,
                    canvasDragIndex: null,
                    dropTargetIndex: null,
                    dropPlacement: 'before',
                    paletteDragKey: null,
                    isDragOverCanvas: false,
                    pointerDrag: {
                        active: false,
                        item: null,
                        width: 280,
                        x: 0,
                        y: 0,
                    },
                    pointerState: null,
                    autoScrollState: {
                        frame: null,
                        speed: 0,
                        container: null,
                        isWindow: false,
                    },
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

                        window.addEventListener('keydown', (e) => {
                            if (e.key === 'Escape' && (this.canvasDragIndex !== null || this.pointerDrag.active || this.paletteDragKey)) {
                                this.cancelDrag();
                            }
                        });
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
                        return false;
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
                            'kpi-rag-status': { type: 'scorecard', label: 'Tile Status RAG' },
                            'nko-ranking': { type: 'ranking', label: 'Peringkat' },
                            'kinerja-detail': { type: 'table', label: 'Tabel' },
                            'dataset-status': { type: 'table', label: 'Tabel' },
                            'failed-datasets': { type: 'table', label: 'Tabel' },
                            'recent-audit': { type: 'table', label: 'Tabel' },
                            'aset-upt-chart': { type: 'bar', label: 'Grafik Batang' },
                            'admin-quick-actions': { type: 'scorecard', label: 'Aksi Cepat' },
                            'layout-editor-placeholder': { type: 'generic', label: 'Editor Layout' },
                        };

                        return map[key] || { type: 'generic', label: 'Komponen' };
                    },

                    filteredCatalog() {
                        if (!this.catalogFilterCategory || this.catalogFilterCategory === 'all') {
                            return this.catalog;
                        }
                        return this.catalog.filter((c) => (c.category || this.getWidgetCategory(c.key)) === this.catalogFilterCategory);
                    },

                    getWidgetCategory(key) {
                        if (['kpi-summary', 'trend-chart', 'nko-ranking', 'kinerja-realisasi', 'kinerja-detail', 'target-realisasi-per-indikator', 'pencapaian-nko', 'nko-donut', 'kpi-rag-status', 'custom-chart'].includes(key)) {
                            return 'Kinerja / KPI';
                        }
                        if (['aset-upt-chart'].includes(key)) {
                            return 'Manajemen Aset';
                        }
                        return 'Operasional & Admin';
                    },

                    getWidgetDescription(key) {
                        const descMap = {
                            'kpi-summary': 'Ringkasan skor NKO, total indikator tercapai, dan periode aktif.',
                            'trend-chart': 'Grafik garis tren historis pergerakan kinerja bulanan unit.',
                            'nko-ranking': 'Peringkat unit kerja diurutkan dari skor NKO tertinggi.',
                            'dataset-status': 'Status dataset siap pakai, jumlah baris data, dan divisi pemilik.',
                            'kinerja-realisasi': 'Grafik komparasi capaian realisasi terhadap target kinerja.',
                            'kinerja-detail': 'Matriks tabular seluruh indikator kinerja dan satuan pengukuran.',
                            'target-realisasi-per-indikator': 'Panel terstruktur komparasi target vs realisasi per indikator kinerja.',
                            'pencapaian-nko': 'Perbandingan persentase pencapaian target seluruh unit kerja.',
                            'nko-donut': 'Grafik donat proporsi sebaran kategori skor NKO unit.',
                            'kpi-rag-status': 'Tile status RAG (Tercapai, Hampir Tercapai, Perlu Perhatian).',
                            'custom-chart': 'Grafik interaktif kustom (bar, line, donut) dengan metrik pilihan.',
                            'aset-upt-chart': 'Rekapitulasi stok trafo, daya MVA, transmisi, dan tower per UPT.',
                            'recent-audit': 'Catatan riwayat audit log aktivitas terbaru pada sistem.',
                            'failed-datasets': 'Monitoring antrean dataset yang mengalami kendala pemrosesan.',
                            'admin-summary': 'Metrik operasional sistem mencakup total dataset dan pengguna.',
                            'admin-quick-actions': 'Pintasan navigasi cepat menuju modul utama operasional sistem.',
                            'layout-editor-placeholder': 'Editor tata letak kanvas dashboard per peran pengguna.',
                        };
                        return descMap[key] || '';
                    },

                    isDirty() {
                        const target = this.currentTarget();
                        if (!target) return false;
                        const current = JSON.stringify(this.serializeWidgets());
                        const saved = JSON.stringify((target.active_widgets || []).map((w) => {
                            if (typeof w === 'string') {
                                const cat = this.catalog.find((c) => c.key === w);
                                return { key: w, cols: cat?.default_cols || 3, config: {} };
                            }
                            return { key: w.key, cols: parseInt(w.cols, 10) || 3, config: w.config || {} };
                        }));
                        return current !== saved;
                    },

                    startPaletteDrag(key) {
                        if (this.saving) return;
                        this.paletteDragKey = key;
                    },

                    endPaletteDrag() {
                        this.paletteDragKey = null;
                        this.isDragOverCanvas = false;
                        this.dropTargetIndex = null;
                    },

                    onCardPointerDown(event, index) {
                        if (this.saving) return;
                        if (event.button !== 0 && event.pointerType === 'mouse') return;

                        const handleEl = event.currentTarget;
                        const cardEl = handleEl.closest('.preview-card-item');
                        const width = cardEl ? Math.min(cardEl.offsetWidth, 360) : 280;
                        const pointerId = event.pointerId;

                        this.pointerState = {
                            pointerId: pointerId,
                            index: index,
                            startX: event.clientX,
                            startY: event.clientY,
                            isDragging: false,
                            handleEl: handleEl,
                            cardWidth: width,
                        };

                        try {
                            handleEl.setPointerCapture(pointerId);
                        } catch (e) {}

                        const onPointerMove = (e) => {
                            if (!this.pointerState || this.pointerState.pointerId !== e.pointerId) return;

                            if (!this.pointerState.isDragging) {
                                const dist = Math.hypot(e.clientX - this.pointerState.startX, e.clientY - this.pointerState.startY);
                                if (dist < 4) return;

                                this.pointerState.isDragging = true;
                                this.canvasDragIndex = this.pointerState.index;
                                this.dragIndex = this.pointerState.index;
                                this.isDragOverCanvas = true;
                                this.pointerDrag = {
                                    active: true,
                                    item: this.activeWidgets[this.pointerState.index],
                                    width: this.pointerState.cardWidth,
                                    x: e.clientX,
                                    y: e.clientY,
                                };
                            }

                            if (this.pointerState.isDragging) {
                                this.pointerDrag.x = e.clientX;
                                this.pointerDrag.y = e.clientY;
                                this.updateDropTargetFromPoint(e.clientX, e.clientY);
                                this.handleAutoScroll(e.clientY);
                            }
                        };

                        const onPointerUp = (e) => {
                            if (!this.pointerState || this.pointerState.pointerId !== e.pointerId) return;

                            const wasDragging = this.pointerState.isDragging;
                            const fromIndex = this.canvasDragIndex;
                            const targetIndex = this.dropTargetIndex;
                            const placement = this.dropPlacement;

                            cleanup();

                            if (wasDragging) {
                                if (fromIndex !== null && targetIndex !== null && fromIndex !== targetIndex) {
                                    this.executeReorder(fromIndex, targetIndex, placement);
                                }
                            }
                        };

                        const onPointerCancel = (e) => {
                            if (!this.pointerState || this.pointerState.pointerId !== e.pointerId) return;
                            cleanup();
                            this.cancelDrag();
                        };

                        const onLostPointerCapture = (e) => {
                            if (e.buttons === 0) {
                                onPointerUp(e);
                            }
                        };

                        const cleanup = () => {
                            try {
                                if (handleEl.hasPointerCapture(pointerId)) {
                                    handleEl.releasePointerCapture(pointerId);
                                }
                            } catch (e) {}

                            window.removeEventListener('pointermove', onPointerMove);
                            window.removeEventListener('pointerup', onPointerUp);
                            window.removeEventListener('pointercancel', onPointerCancel);
                            handleEl.removeEventListener('lostpointercapture', onLostPointerCapture);

                            this.stopAutoScroll();
                            this.canvasDragIndex = null;
                            this.dragIndex = null;
                            this.dropTargetIndex = null;
                            this.isDragOverCanvas = false;
                            this.pointerDrag = { active: false, item: null, width: 280, x: 0, y: 0 };
                            this.pointerState = null;
                            this._boundPointerCleanup = null;
                        };

                        this._boundPointerCleanup = cleanup;

                        window.addEventListener('pointermove', onPointerMove);
                        window.addEventListener('pointerup', onPointerUp);
                        window.addEventListener('pointercancel', onPointerCancel);
                        handleEl.addEventListener('lostpointercapture', onLostPointerCapture);
                    },

                    updateDropTargetFromPoint(clientX, clientY) {
                        const viewport = document.getElementById('preview-canvas-viewport');
                        if (!viewport) return;

                        const vRect = viewport.getBoundingClientRect();
                        if (clientX < vRect.left - 40 || clientX > vRect.right + 40) {
                            this.dropTargetIndex = null;
                            return;
                        }

                        const cardEls = Array.from(viewport.querySelectorAll('.preview-card-item'));
                        if (cardEls.length === 0) {
                            this.dropTargetIndex = null;
                            return;
                        }

                        let matchedIndex = -1;
                        let matchedPlacement = 'after';

                        for (let i = 0; i < cardEls.length; i++) {
                            const rect = cardEls[i].getBoundingClientRect();
                            if (
                                clientX >= rect.left &&
                                clientX <= rect.right &&
                                clientY >= rect.top &&
                                clientY <= rect.bottom
                            ) {
                                matchedIndex = i;
                                const cols = this.activeWidgets[i]?.cols ?? 3;
                                matchedPlacement = this.getDropPlacement({ clientX, clientY }, cardEls[i], cols);
                                break;
                            }
                        }

                        if (matchedIndex === -1) {
                            const firstRect = cardEls[0].getBoundingClientRect();
                            const lastRect = cardEls[cardEls.length - 1].getBoundingClientRect();

                            if (clientY < firstRect.top) {
                                matchedIndex = 0;
                                matchedPlacement = 'before';
                            } else if (clientY > lastRect.bottom) {
                                matchedIndex = cardEls.length - 1;
                                matchedPlacement = 'after';
                            } else {
                                let minDistance = Infinity;
                                for (let i = 0; i < cardEls.length; i++) {
                                    const rect = cardEls[i].getBoundingClientRect();
                                    const centerX = rect.left + rect.width / 2;
                                    const centerY = rect.top + rect.height / 2;
                                    const dist = Math.hypot(clientX - centerX, clientY - centerY);
                                    if (dist < minDistance) {
                                        minDistance = dist;
                                        matchedIndex = i;
                                        const cols = this.activeWidgets[i]?.cols ?? 3;
                                        matchedPlacement = this.getDropPlacement({ clientX, clientY }, cardEls[i], cols);
                                    }
                                }
                            }
                        }

                        const fromIdx = this.canvasDragIndex;
                        if (matchedIndex !== -1 && fromIdx !== null) {
                            if (matchedIndex === fromIdx) {
                                this.dropTargetIndex = null;
                                return;
                            }

                            if (matchedIndex === fromIdx + 1 && matchedPlacement === 'before') {
                                matchedPlacement = 'after';
                            }
                            if (matchedIndex === fromIdx - 1 && matchedPlacement === 'after') {
                                matchedPlacement = 'before';
                            }

                            this.dropTargetIndex = matchedIndex;
                            this.dropPlacement = matchedPlacement;
                        } else if (matchedIndex !== -1) {
                            this.dropTargetIndex = matchedIndex;
                            this.dropPlacement = matchedPlacement;
                        } else {
                            this.dropTargetIndex = null;
                        }
                    },

                    executeReorder(fromIndex, targetIndex, placement) {
                        if (fromIndex === null || targetIndex === null || fromIndex === targetIndex) {
                            return;
                        }
                        if (fromIndex < 0 || fromIndex >= this.activeWidgets.length) return;
                        if (targetIndex < 0 || targetIndex >= this.activeWidgets.length) return;

                        this.clearMessages();
                        const copy = [...this.activeWidgets];
                        const [moved] = copy.splice(fromIndex, 1);

                        let insertIndex;
                        if (fromIndex < targetIndex) {
                            insertIndex = placement === 'before' ? targetIndex - 1 : targetIndex;
                        } else {
                            insertIndex = placement === 'before' ? targetIndex : targetIndex + 1;
                        }

                        insertIndex = Math.max(0, Math.min(insertIndex, copy.length));
                        copy.splice(insertIndex, 0, moved);
                        this.activeWidgets = copy;

                        this.$nextTick(() => {
                            this.resizePreviewCharts();
                        });
                    },

                    cancelDrag() {
                        this.stopAutoScroll();
                        if (this._boundPointerCleanup) {
                            this._boundPointerCleanup();
                            this._boundPointerCleanup = null;
                        }
                        this.canvasDragIndex = null;
                        this.dragIndex = null;
                        this.dropTargetIndex = null;
                        this.paletteDragKey = null;
                        this.isDragOverCanvas = false;
                        this.pointerDrag = { active: false, item: null, width: 280, x: 0, y: 0 };
                        this.pointerState = null;
                    },

                    onCardDragStart(index, event) {
                        if (this.saving) return;

                        if (this.pointerState && this.pointerState.isDragging) {
                            if (event && typeof event.preventDefault === 'function') {
                                event.preventDefault();
                            }
                            return;
                        }

                        this.canvasDragIndex = index;
                        this.dragIndex = index;
                        this.dropTargetIndex = null;
                        if (event && event.dataTransfer) {
                            event.dataTransfer.effectAllowed = 'move';
                            event.dataTransfer.setData('text/plain', String(index));
                        }
                    },

                    getDropPlacement(event, cardEl, cols = 3) {
                        if (!cardEl) return 'after';
                        const rect = cardEl.getBoundingClientRect();
                        const relY = event.clientY - rect.top;
                        const relX = event.clientX - rect.left;
                        const height = Math.max(rect.height, 1);
                        const width = Math.max(rect.width, 1);

                        // Kartu penuh (3 kolom) atau tampilan mobile: ambang tengah vertikal
                        if (parseInt(cols, 10) === 3 || window.innerWidth < 768) {
                            return relY < height / 2 ? 'before' : 'after';
                        }

                        // Kartu berdampingan (1 atau 2 kolom):
                        // Jika jelas di 35% atas -> letakkan sebelum
                        if (relY < height * 0.35) return 'before';
                        // Jika jelas di 35% bawah -> letakkan sesudah
                        if (relY > height * 0.65) return 'after';
                        // Pada area tengah: cek koordinat horizontal (kiri = sebelum, kanan = sesudah)
                        return relX < width / 2 ? 'before' : 'after';
                    },

                    onCardDragOver(event, index) {
                        if (this.saving) return;
                        this.autoScrollPreview(event);

                        if (this.canvasDragIndex === index) {
                            this.dropTargetIndex = null;
                            return;
                        }

                        const cardEl = event.currentTarget;
                        this.dropTargetIndex = index;
                        const cols = this.activeWidgets[index]?.cols ?? 3;
                        this.dropPlacement = this.getDropPlacement(event, cardEl, cols);
                    },

                    onCardDragLeave(event, index) {
                        if (event.relatedTarget && event.currentTarget && event.currentTarget.contains(event.relatedTarget)) {
                            return;
                        }
                        const viewport = document.getElementById('preview-canvas-viewport');
                        if (event.relatedTarget && viewport && viewport.contains(event.relatedTarget)) {
                            return;
                        }
                        if (this.dropTargetIndex === index) {
                            this.dropTargetIndex = null;
                        }
                    },

                    onViewportDragLeave(event) {
                        if (event.relatedTarget && event.currentTarget && event.currentTarget.contains(event.relatedTarget)) {
                            return;
                        }
                        this.isDragOverCanvas = false;
                        this.dropTargetIndex = null;
                        this.stopAutoScroll();
                    },

                    autoScrollPreview(event) {
                        if (this.canvasDragIndex === null && !this.paletteDragKey) {
                            return;
                        }
                        this.isDragOverCanvas = true;
                        if (event && typeof event.preventDefault === 'function') {
                            event.preventDefault();
                        }
                        this.handleAutoScroll(event.clientY);
                    },

                    handleAutoScroll(clientY) {
                        if (this.canvasDragIndex === null && !this.paletteDragKey) {
                            this.stopAutoScroll();
                            return;
                        }

                        const viewport = document.getElementById('preview-canvas-viewport');
                        if (!viewport) {
                            this.stopAutoScroll();
                            return;
                        }

                        const isDesktop = window.innerWidth >= 1024;
                        const isScrollable = viewport.scrollHeight > viewport.clientHeight + 1;

                        let targetSpeed = 0;
                        let targetContainer = null;
                        let isWindow = false;

                        if (isDesktop) {
                            // On desktop, the preview pane is sticky and preview-canvas-viewport is the sole scroll container.
                            // If preview content fits (not scrollable), NEVER scroll the outer page!
                            if (!isScrollable) {
                                this.stopAutoScroll();
                                return;
                            }

                            const rect = viewport.getBoundingClientRect();
                            const visibleTop = Math.max(rect.top, 0);
                            const visibleBottom = Math.min(rect.bottom, window.innerHeight);

                            // Pointer must be inside or immediately adjacent to the preview viewport
                            if (clientY < visibleTop - 20 || clientY > visibleBottom + 20) {
                                this.stopAutoScroll();
                                return;
                            }

                            targetContainer = viewport;
                            isWindow = false;

                            const edgeZone = 60;
                            // Top edge check: scroll up
                            if (clientY - visibleTop < edgeZone && clientY >= visibleTop - 20) {
                                const dist = Math.max(0, clientY - visibleTop);
                                const ratio = 1 - (dist / edgeZone);
                                const speed = Math.round(2 + 12 * (ratio * ratio));
                                targetSpeed = -speed;
                            }
                            // Bottom edge check: scroll down
                            else if (visibleBottom - clientY < edgeZone && clientY <= visibleBottom + 20) {
                                const dist = Math.max(0, visibleBottom - clientY);
                                const ratio = 1 - (dist / edgeZone);
                                const speed = Math.round(2 + 12 * (ratio * ratio));
                                targetSpeed = speed;
                            }
                        } else {
                            // On mobile (< 1024px), preview-canvas-viewport does not have internal scroll.
                            // The scroll container is .app-content or window.
                            const appContainer = viewport.closest('.app-content');
                            if (appContainer && appContainer.scrollHeight > appContainer.clientHeight + 1) {
                                targetContainer = appContainer;
                                isWindow = false;
                            } else {
                                targetContainer = window;
                                isWindow = true;
                            }

                            const edgeZone = 60;
                            // On mobile, edge trigger is relative to the screen/window edges
                            if (clientY < edgeZone && clientY >= 0) {
                                const ratio = 1 - (clientY / edgeZone);
                                const speed = Math.round(2 + 12 * (ratio * ratio));
                                targetSpeed = -speed;
                            } else if (window.innerHeight - clientY < edgeZone && clientY <= window.innerHeight) {
                                const dist = Math.max(0, window.innerHeight - clientY);
                                const ratio = 1 - (dist / edgeZone);
                                const speed = Math.round(2 + 12 * (ratio * ratio));
                                targetSpeed = speed;
                            }
                        }

                        if (targetSpeed === 0) {
                            this.stopAutoScroll();
                            return;
                        }

                        this.autoScrollState.speed = targetSpeed;
                        this.autoScrollState.container = targetContainer;
                        this.autoScrollState.isWindow = isWindow;

                        if (!this.autoScrollState.frame) {
                            this.runAutoScrollLoop();
                        }
                    },

                    runAutoScrollLoop() {
                        const step = () => {
                            if (!this.autoScrollState.speed || !this.autoScrollState.container) {
                                this.autoScrollState.frame = null;
                                return;
                            }

                            const { container, speed, isWindow } = this.autoScrollState;

                            // Check container scroll limits: if at boundary, stop immediately
                            if (speed > 0) {
                                const maxScroll = isWindow
                                    ? (document.documentElement.scrollHeight - window.innerHeight)
                                    : (container.scrollHeight - container.clientHeight);
                                const currentScroll = isWindow ? window.scrollY : container.scrollTop;
                                if (currentScroll >= maxScroll - 1) {
                                    this.stopAutoScroll();
                                    return;
                                }
                            } else if (speed < 0) {
                                const currentScroll = isWindow ? window.scrollY : container.scrollTop;
                                if (currentScroll <= 1) {
                                    this.stopAutoScroll();
                                    return;
                                }
                            }

                            if (isWindow) {
                                window.scrollBy(0, speed);
                            } else if (container) {
                                container.scrollTop += speed;
                            }

                            if (this.pointerDrag && this.pointerDrag.active) {
                                this.updateDropTargetFromPoint(this.pointerDrag.x, this.pointerDrag.y);
                                this.handleAutoScroll(this.pointerDrag.y);
                            }

                            if (this.autoScrollState.speed && this.autoScrollState.container) {
                                this.autoScrollState.frame = requestAnimationFrame(step);
                            } else {
                                this.autoScrollState.frame = null;
                            }
                        };

                        this.autoScrollState.frame = requestAnimationFrame(step);
                    },

                    stopAutoScroll() {
                        if (this.autoScrollState.frame) {
                            cancelAnimationFrame(this.autoScrollState.frame);
                            this.autoScrollState.frame = null;
                        }
                        this.autoScrollState.speed = 0;
                        this.autoScrollState.container = null;
                    },

                    onCardDrop(event, targetIndex) {
                        if (this.saving) return;

                        this.stopAutoScroll();
                        const cardEl = event.currentTarget;
                        const cols = this.activeWidgets[targetIndex]?.cols ?? 3;
                        const placement = this.getDropPlacement(event, cardEl, cols);

                        this.dropTargetIndex = null;
                        this.isDragOverCanvas = false;

                        if (this.paletteDragKey) {
                            const key = this.paletteDragKey;
                            this.paletteDragKey = null;

                            if (key !== 'custom-chart' && this.hasWidget(key)) {
                                this.showToast(`Widget "${this.widgetLabel(key)}" sudah ada di kanvas.`, 'error');
                                return;
                            }
                            this.clearMessages();
                            const newItem = this.normalizeItem(key);
                            const insertIdx = placement === 'before' ? targetIndex : targetIndex + 1;
                            this.activeWidgets.splice(insertIdx, 0, newItem);
                            this.showToast(`Widget "${this.widgetLabel(key)}" berhasil ditambahkan ke kanvas.`, 'success');
                            this.$nextTick(() => {
                                this.resizePreviewCharts();
                            });
                            return;
                        }

                        const fromIndex = this.canvasDragIndex;
                        this.canvasDragIndex = null;
                        this.dragIndex = null;

                        this.executeReorder(fromIndex, targetIndex, placement);
                    },

                    onCardDragEnd() {
                        this.stopAutoScroll();
                        this.canvasDragIndex = null;
                        this.dragIndex = null;
                        this.dropTargetIndex = null;
                        this.isDragOverCanvas = false;
                    },

                    onCanvasDrop(event) {
                        if (this.saving) return;
                        this.stopAutoScroll();
                        this.isDragOverCanvas = false;
                        this.dropTargetIndex = null;

                        if (this.paletteDragKey) {
                            const key = this.paletteDragKey;
                            this.paletteDragKey = null;
                            if (key !== 'custom-chart' && this.hasWidget(key)) {
                                this.showToast(`Widget "${this.widgetLabel(key)}" sudah ada di kanvas.`, 'error');
                                return;
                            }
                            this.clearMessages();
                            const newItem = this.normalizeItem(key);
                            this.activeWidgets.push(newItem);
                            this.showToast(`Widget "${this.widgetLabel(key)}" berhasil ditambahkan ke kanvas.`, 'success');
                            this.$nextTick(() => {
                                this.resizePreviewCharts();
                            });
                            return;
                        }

                        if (this.canvasDragIndex !== null) {
                            const fromIndex = this.canvasDragIndex;
                            this.canvasDragIndex = null;
                            this.dragIndex = null;

                            if (fromIndex < this.activeWidgets.length - 1) {
                                this.clearMessages();
                                const copy = [...this.activeWidgets];
                                const [moved] = copy.splice(fromIndex, 1);
                                copy.push(moved);
                                this.activeWidgets = copy;
                                this.$nextTick(() => {
                                    this.resizePreviewCharts();
                                });
                            }
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
