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
        @resize.window="viewportWidth = window.innerWidth; resizePreviewCharts(); $nextTick(() => syncStickyTop())"
        class="w-full"
    >
        {{-- Floating Drag Proxy (mengikuti pointer saat Pointer Events drag berlangsung) --}}
        <div
            x-show="pointerDrag.active && pointerDrag.item"
            x-cloak
            class="pointer-events-none fixed z-50 rounded-xl border-2 border-[#16a3b8] bg-white/95 px-3.5 py-2.5 shadow-xl select-none"
            :style="`left: ${pointerDrag.x}px; top: ${pointerDrag.y}px; width: ${pointerDrag.width}px; transform: translate3d(-50%, -50%, 0) scale(1.02); opacity: 0.95;`"
        >
            <div class="flex items-center gap-2">
                <span class="inline-flex h-5 w-5 items-center justify-center rounded-md bg-[#e6f4f6] text-[#0b6175] ring-1 ring-[#0b6175]/15">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <circle cx="9" cy="6" r="1.5" />
                        <circle cx="15" cy="6" r="1.5" />
                        <circle cx="9" cy="12" r="1.5" />
                        <circle cx="15" cy="12" r="1.5" />
                        <circle cx="9" cy="18" r="1.5" />
                        <circle cx="15" cy="18" r="1.5" />
                    </svg>
                </span>
                <span class="truncate text-xs font-semibold text-slate-900" x-text="pointerDrag.item ? (pointerDrag.item.key === 'custom-chart' ? (pointerDrag.item.config?.title || widgetLabel(pointerDrag.item.key)) : widgetLabel(pointerDrag.item.key)) : ''"></span>
                <span class="ml-auto shrink-0 rounded-md bg-[#e6f4f6] px-1.5 py-0.5 text-[10px] font-semibold text-[#0b6175] tabular-nums" x-text="`${pointerDrag.item?.cols || 3} Kol`"></span>
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
            :class="toast.type === 'error' ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800'"
            class="fixed top-20 right-6 z-50 flex items-center gap-3 rounded-xl border px-4 py-3 shadow-lg"
            role="status"
        >
            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            <p class="text-xs font-semibold" x-text="toast.message"></p>
            <button type="button" @click="toast.show = false" class="ml-1 text-slate-400 hover:text-slate-600">
                <span class="sr-only">Tutup</span>
                &times;
            </button>
        </div>

        {{-- Status feedback AJAX (sukses / validasi 422 / error server) --}}
        <div
            x-show="saving"
            x-cloak
            class="mb-4 rounded-xl border border-sky-200 bg-sky-50 px-3.5 py-2.5 text-xs font-medium text-sky-800"
            role="status"
        >
            <span x-text="savingAction === 'reset' ? 'Mereset tata letak ke konfigurasi bawaan...' : 'Menyimpan perubahan tata letak...'"></span>
        </div>

        <div
            x-show="!saving && statusMessage !== ''"
            x-cloak
            class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-3.5 py-2.5 text-xs font-medium text-emerald-800"
            role="status"
            x-text="statusMessage"
        ></div>

        <div
            x-show="!saving && (errorMessage !== '' || validationErrors.length > 0)"
            x-cloak
            class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-3.5 py-2.5 text-xs text-rose-800"
            role="alert"
        >
            <p x-show="errorMessage !== ''" class="font-semibold" x-text="errorMessage"></p>
            <ul x-show="validationErrors.length > 0" class="mt-1 list-inside list-disc space-y-0.5">
                <template x-for="(err, idx) in validationErrors" :key="idx">
                    <li x-text="err"></li>
                </template>
            </ul>
        </div>

        {{-- Quiet Luxury Editorial Studio Command Bar --}}
        <div
            id="builder-top-bar"
            class="lg:sticky lg:top-20 z-20 mb-5 flex flex-col gap-2 rounded-2xl border border-slate-200/85 bg-white/95 px-3 py-2 text-slate-800 shadow-[0_2px_10px_-3px_rgba(15,23,42,0.06),0_1px_2px_0_rgba(15,23,42,0.03)] ring-1 ring-slate-900/[0.02] backdrop-blur-md xl:flex-row xl:items-center xl:justify-between xl:gap-3"
        >
            {{-- Left Side: Visual Target Role Switcher Pills + Preserved Accessible Select --}}
            <div class="flex flex-wrap items-center gap-2 xl:shrink-0">
                <label for="layout-editor-target" class="sr-only">Target Layout:</label>
                <select
                    id="layout-editor-target"
                    name="target_key"
                    x-model="selectedTargetKey"
                    @change="onTargetChange()"
                    :disabled="saving"
                    class="sr-only"
                >
                    @foreach ($targets as $target)
                        <option value="{{ $target['layout_key'] }}">{{ $target['label'] }}</option>
                    @endforeach
                </select>

                <div
                    class="inline-flex flex-wrap items-center gap-1 rounded-xl border border-slate-200/80 bg-slate-100/85 p-1 xl:flex-nowrap"
                    role="tablist"
                    aria-label="Pilih target tata letak peran"
                >
                    <template x-for="target in targets" :key="target.layout_key">
                        <button
                            type="button"
                            role="tab"
                            :aria-selected="selectedTargetKey === (target.key || target.layout_key) ? 'true' : 'false'"
                            @click="selectedTargetKey = target.key; onTargetChange()"
                            :disabled="saving"
                            :class="selectedTargetKey === (target.key || target.layout_key)
                                ? 'bg-white text-[#0b6175] font-semibold shadow-[0_1px_2px_rgba(15,23,42,0.07)] ring-1 ring-[#0b6175]/15'
                                : 'text-slate-600 hover:bg-white/60 hover:text-slate-900 font-medium'"
                            class="inline-flex h-7 items-center gap-1.5 whitespace-nowrap rounded-lg px-2 text-[11px] 2xl:px-2.5 2xl:text-xs transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/50 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <span x-text="target.label"></span>
                        </button>
                    </template>
                </div>
            </div>

            {{-- Right Side: Studio Status Badges & Actions --}}
            <div class="flex flex-wrap items-center gap-1.5 xl:justify-end">
                <span
                    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-slate-200/80 bg-slate-50/90 px-2 py-1 text-[11px] 2xl:px-2.5 2xl:text-xs font-medium text-slate-700"
                >
                    <span
                        class="h-1.5 w-1.5 rounded-full"
                        :class="isCustomized() ? 'bg-[#16a3b8]' : 'bg-emerald-500'"
                        aria-hidden="true"
                    ></span>
                    <span x-text="isCustomized() ? 'Kustom' : 'Bawaan'">Bawaan</span>
                </span>

                <span
                    class="inline-flex items-center whitespace-nowrap rounded-lg border border-[#0b6175]/15 bg-[#e6f4f6]/75 px-2 py-1 text-[11px] 2xl:px-2.5 2xl:text-xs font-semibold text-[#0b6175] tabular-nums"
                    x-text="`${activeWidgets.length} Widget`"
                ></span>

                <span
                    x-show="isDirty()"
                    x-cloak
                    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-amber-200/90 bg-amber-50/90 px-2 py-1 text-[11px] 2xl:px-2.5 2xl:text-xs font-semibold text-amber-800"
                >
                    <span class="h-1.5 w-1.5 rounded-full bg-amber-500" aria-hidden="true"></span>
                    <span>Belum disimpan</span>
                </span>

                <button
                    type="button"
                    @click="resetLayout()"
                    :disabled="saving"
                    title="Reset ke Default"
                    class="inline-flex h-8 items-center justify-center whitespace-nowrap rounded-xl border border-slate-200/90 bg-white px-2.5 py-1.5 text-[11px] 2xl:text-xs font-medium text-slate-600 transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/40 disabled:cursor-not-allowed disabled:opacity-45"
                >
                    Reset ke Bawaan
                </button>

                <a
                    href="{{ route('dashboard') }}"
                    class="inline-flex h-8 items-center justify-center gap-1.5 whitespace-nowrap rounded-xl border border-slate-200/90 bg-white px-2.5 py-1.5 text-[11px] 2xl:text-xs font-medium text-slate-700 transition hover:border-[#0b6175]/30 hover:bg-[#e6f4f6]/50 hover:text-[#0b6175] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/40"
                >
                    <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                    </svg>
                    <span>Buka Dashboard</span>
                </a>

                <button
                    type="button"
                    @click="saveLayout()"
                    :disabled="saving || activeWidgets.length === 0"
                    title="Simpan Tata Letak"
                    :class="isDirty() ? 'ring-2 ring-[#16a3b8]/40 font-semibold' : 'font-medium'"
                    class="inline-flex h-8 items-center justify-center gap-1.5 whitespace-nowrap rounded-xl bg-[#0b6175] px-2.5 2xl:px-3 py-1.5 text-[11px] 2xl:text-xs text-white shadow-[0_1px_2px_rgba(11,97,117,0.25)] transition hover:bg-[#094e5e] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/60 disabled:cursor-not-allowed disabled:opacity-45"
                >
                    <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4" />
                    </svg>
                    <span>Simpan Tata Letak</span>
                    <span class="sr-only">Simpan Layout</span>
                </button>
            </div>
        </div>

        {{-- Side-by-Side Studio Workspace --}}
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">
            {{-- Left Panel: Pinned Component Library Dock --}}
            <aside id="builder-config-pane" :style="desktopStickyStyle()" class="lg:col-span-4 xl:col-span-3 lg:sticky lg:top-44 xl:top-40">
                <div class="ibs-card flex flex-col overflow-hidden border-slate-200/85 bg-white shadow-[0_1px_3px_0_rgba(15,23,42,0.04)] lg:max-h-[var(--builder-pane-max-h,calc(100vh-11.5rem))]">
                    {{-- Dock Header --}}
                    <div class="shrink-0 space-y-2.5 border-b border-slate-200/80 bg-slate-50/75 px-3.5 py-3">
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-[#e6f4f6] text-[#0b6175] ring-1 ring-[#0b6175]/15">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                    </svg>
                                </span>
                                <h4 class="ibs-section-title text-slate-900">Tambah Komponen</h4>
                                <span class="sr-only">Komponen</span>
                            </div>
                            <span
                                class="inline-flex items-center rounded-md border border-[#0b6175]/15 bg-[#e6f4f6]/80 px-2 py-0.5 text-[11px] font-semibold text-[#0b6175] tabular-nums"
                                x-text="filteredCatalog().length"
                            >{{ count($catalog) }}</span>
                        </div>

                        <div class="relative">
                            <svg class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            <input
                                type="search"
                                x-model="catalogSearch"
                                @input="$refs.catalogList && ($refs.catalogList.scrollTop = 0)"
                                placeholder="Cari komponen..."
                                aria-label="Cari komponen"
                                class="h-8 w-full rounded-lg border border-slate-200/90 bg-white pl-8 pr-3 text-xs text-slate-800 placeholder:text-slate-400 outline-none transition focus:border-[#16a3b8] focus:ring-2 focus:ring-[#16a3b8]/15"
                            />
                        </div>

                        <div class="grid grid-cols-4 gap-1 rounded-lg border border-slate-200/80 bg-slate-100/85 p-0.5 text-[11px] font-medium">
                            <button
                                type="button"
                                @click="catalogFilterCategory = 'all'; $refs.catalogList && ($refs.catalogList.scrollTop = 0)"
                                :class="catalogFilterCategory === 'all' ? 'bg-white text-[#0b6175] font-semibold shadow-2xs ring-1 ring-[#0b6175]/15' : 'text-slate-600 hover:bg-white/60 hover:text-slate-900'"
                                class="rounded-md px-1.5 py-1 text-center transition"
                            >Semua</button>
                            <button
                                type="button"
                                @click="catalogFilterCategory = 'Kinerja / KPI'; $refs.catalogList && ($refs.catalogList.scrollTop = 0)"
                                :class="catalogFilterCategory === 'Kinerja / KPI' ? 'bg-white text-[#0b6175] font-semibold shadow-2xs ring-1 ring-[#0b6175]/15' : 'text-slate-600 hover:bg-white/60 hover:text-slate-900'"
                                class="rounded-md px-1.5 py-1 text-center transition"
                            >KPI</button>
                            <button
                                type="button"
                                @click="catalogFilterCategory = 'Manajemen Aset'; $refs.catalogList && ($refs.catalogList.scrollTop = 0)"
                                :class="catalogFilterCategory === 'Manajemen Aset' ? 'bg-white text-[#0b6175] font-semibold shadow-2xs ring-1 ring-[#0b6175]/15' : 'text-slate-600 hover:bg-white/60 hover:text-slate-900'"
                                class="rounded-md px-1.5 py-1 text-center transition"
                            >Aset</button>
                            <button
                                type="button"
                                @click="catalogFilterCategory = 'Operasional & Admin'; $refs.catalogList && ($refs.catalogList.scrollTop = 0)"
                                :class="catalogFilterCategory === 'Operasional & Admin' ? 'bg-white text-[#0b6175] font-semibold shadow-2xs ring-1 ring-[#0b6175]/15' : 'text-slate-600 hover:bg-white/60 hover:text-slate-900'"
                                class="rounded-md px-1.5 py-1 text-center transition"
                            >Admin</button>
                        </div>
                    </div>

                    {{-- Compact Studio Row List (Anti-Clipping Container) --}}
                    <div
                        x-ref="catalogList"
                        class="min-h-0 flex-1 max-h-[26rem] lg:max-h-none overflow-y-auto overscroll-contain px-3 pt-3 pb-4 scroll-pt-3"
                        aria-label="Palet komponen"
                    >
                        <div class="flex flex-col gap-1.5">
                            <template x-for="item in filteredCatalog()" :key="item.key">
                                <div
                                    draggable="true"
                                    @dragstart="startPaletteDrag($event, item.key)"
                                    @dragend="endPaletteDrag()"
                                    :title="item.label"
                                    class="group flex items-center justify-between gap-2 rounded-xl border border-slate-200/75 bg-white px-2.5 py-2 transition duration-150 hover:border-[#16a3b8]/45 hover:bg-[#f4f8fa]/75 hover:shadow-2xs cursor-grab active:cursor-grabbing"
                                    :class="(item.key !== 'custom-chart' && hasWidget(item.key)) ? 'bg-slate-50/80 border-slate-200/70' : ''"
                                >
                                    <div
                                        class="palette-thumb h-8 w-8 shrink-0 rounded-lg flex items-center justify-center transition-colors"
                                        :class="paletteThumbClass(item.key)"
                                    >
                                        @include('components.widgets.partials.widget-sketch', ['keyExpr' => 'item.key', 'sizeClass' => 'h-5 w-5'])
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <p class="text-xs font-semibold leading-snug text-slate-800 truncate" x-text="item.label"></p>
                                        <div class="mt-0.5 flex items-center gap-1.5 text-[10px] leading-tight">
                                            <span class="palette-type-label truncate font-medium text-slate-500" x-text="paletteVisual(item.key).label"></span>
                                            <span class="text-slate-300" aria-hidden="true">&bull;</span>
                                            <span class="shrink-0 font-semibold text-slate-600 tabular-nums" x-text="`${item.default_cols || 3} Kol`"></span>
                                        </div>
                                    </div>

                                    <template x-if="item.key !== 'custom-chart' && hasWidget(item.key)">
                                        <span class="inline-flex shrink-0 items-center rounded-lg border border-emerald-200/80 bg-emerald-50/80 px-2 py-1 text-[11px] font-medium text-emerald-700">
                                            Aktif
                                        </span>
                                    </template>
                                    <template x-if="item.key === 'custom-chart' || !hasWidget(item.key)">
                                        <button
                                            type="button"
                                            @click="addWidget(item.key)"
                                            :disabled="saving"
                                            class="inline-flex shrink-0 items-center rounded-lg border border-slate-200/90 bg-white px-2 py-1 text-[11px] font-semibold text-slate-700 shadow-2xs transition hover:border-[#0b6175] hover:bg-[#0b6175] hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/40 disabled:cursor-not-allowed disabled:opacity-45"
                                        >
                                            + Tambah
                                        </button>
                                    </template>
                                </div>
                            </template>

                            <div
                                x-show="filteredCatalog().length === 0"
                                x-cloak
                                class="flex flex-col items-center justify-center py-8 px-3 text-center"
                            >
                                <p class="text-xs font-semibold text-slate-700">Komponen tidak ditemukan</p>
                                <button
                                    type="button"
                                    @click="catalogSearch = ''; catalogFilterCategory = 'all'; $refs.catalogList && ($refs.catalogList.scrollTop = 0)"
                                    class="mt-2 rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 transition hover:border-[#0b6175] hover:bg-[#e6f4f6] hover:text-[#0b6175]"
                                >
                                    Reset Filter
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </aside>

            {{-- Right Panel: Architectural Dot-Grid Canvas --}}
            <section id="builder-preview-pane" class="lg:col-span-8 xl:col-span-9">
                <div class="ibs-card overflow-hidden border-slate-200/85 bg-white shadow-[0_1px_3px_0_rgba(15,23,42,0.04)]">
                    {{-- Clean 1-row Canvas Header --}}
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200/80 bg-slate-50/75 px-4 py-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-[#e6f4f6] text-[#0b6175] ring-1 ring-[#0b6175]/15">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
                                </svg>
                            </span>
                            <h4 class="ibs-section-title text-slate-900">Pratinjau Tata Letak</h4>
                            <span class="rounded-md border border-slate-200/80 bg-white px-2 py-0.5 text-[11px] font-medium text-slate-600">Kanvas Grid 3 Kolom</span>
                            <span class="rounded-md border border-amber-200/80 bg-amber-50/90 px-2 py-0.5 text-[11px] font-medium text-amber-800">Pratinjau contoh</span>
                            <span
                                class="rounded-md border border-[#0b6175]/15 bg-[#e6f4f6]/80 px-2 py-0.5 text-[11px] font-semibold text-[#0b6175] tabular-nums"
                                x-text="`${activeWidgets.reduce((acc, w) => acc + (parseInt(w.cols, 10) || 3), 0)} Kolom Terpakai`"
                            ></span>
                        </div>
                        <p class="hidden xl:block text-xs font-medium text-slate-500">
                            Tarik kartu untuk mengurutkan atau klik ukuran kolom pada kartu
                        </p>
                    </div>

                    {{-- Quiet Architectural Dot-Grid Canvas Viewport --}}
                    <div
                        id="preview-canvas-viewport"
                        @dragover="autoScrollPreview($event)"
                        @dragleave="onViewportDragLeave($event)"
                        @drop.prevent="isDragOverCanvas = false; onCanvasDrop($event)"
                        :class="isDragOverCanvas ? 'ring-2 ring-inset ring-[#16a3b8]/60 bg-[#e6f4f6]/40' : ''"
                        class="border-t border-slate-200/80 bg-[#f7fafb] p-4 sm:p-5 min-h-[540px] transition"
                        style="background-image: radial-gradient(#cbd5e1 0.85px, transparent 0.85px); background-size: 20px 20px;"
                    >
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 items-stretch">
                            <template x-for="(w, index) in activeWidgets" :key="w._uid || index">
                                <div
                                    @dragover.prevent="onCardDragOver($event, index)"
                                    @dragleave="onCardDragLeave($event, index)"
                                    @drop.prevent.stop="onCardDrop($event, index)"
                                    :class="[
                                        previewSpanClass(w.cols),
                                        canvasDragIndex === index ? 'opacity-40 border-dashed border-[#16a3b8]' : 'border-slate-200/90',
                                        (dropTargetIndex === index && (canvasDragIndex !== index || paletteDragKey)) ? 'ring-2 ring-[#16a3b8] ring-offset-1' : ''
                                    ]"
                                    class="preview-card-item group relative flex min-w-0 flex-col justify-between rounded-2xl border bg-white shadow-[0_1px_3px_0_rgba(15,23,42,0.05),0_1px_2px_-1px_rgba(15,23,42,0.03)] transition duration-150 hover:border-[#16a3b8]/50 hover:shadow-[0_6px_16px_-4px_rgba(11,97,117,0.10),0_2px_6px_-2px_rgba(15,23,42,0.04)]"
                                >
                                    {{-- Indikator garis posisi drop (sebelum / sesudah) --}}
                                    <div
                                        x-show="dropTargetIndex === index && (canvasDragIndex !== index || paletteDragKey)"
                                        x-cloak
                                        :class="dropPlacement === 'before' ? '-top-2 left-1 right-1 h-1.5' : '-bottom-2 left-1 right-1 h-1.5'"
                                        class="pointer-events-none absolute z-30 rounded-full bg-[#0b6175] shadow"
                                    ></div>

                                    {{-- Card Header with Direct 1-Click Controls --}}
                                    <div class="canvas-card-toolbar relative flex flex-wrap items-center justify-between gap-2 rounded-t-2xl border-b border-slate-200/75 bg-slate-50/60 px-3.5 py-2.5" :class="(canvasDragIndex !== null || paletteDragKey) ? 'pointer-events-none' : ''">
                                        <div class="flex min-w-0 flex-1 items-center gap-1.5 pointer-events-auto">
                                            <span
                                                @pointerdown="onCardPointerDown($event, index)"
                                                class="canvas-drag-handle inline-flex h-7 w-7 shrink-0 cursor-grab items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-200/60 hover:text-[#0b6175] active:cursor-grabbing select-none touch-none"
                                                title="Tarik untuk memindahkan urutan widget"
                                            >
                                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                                    <circle cx="9" cy="6" r="1.5" />
                                                    <circle cx="15" cy="6" r="1.5" />
                                                    <circle cx="9" cy="12" r="1.5" />
                                                    <circle cx="15" cy="12" r="1.5" />
                                                    <circle cx="9" cy="18" r="1.5" />
                                                    <circle cx="15" cy="18" r="1.5" />
                                                </svg>
                                            </span>
                                            <span
                                                class="inline-flex items-center justify-center rounded-md border border-[#0b6175]/15 bg-[#e6f4f6] px-2 py-0.5 text-[11px] font-semibold text-[#0b6175] tabular-nums select-none shrink-0"
                                                x-text="`#${index + 1}`"
                                                title="Posisi widget"
                                            ></span>
                                            <p
                                                class="text-xs sm:text-[13px] font-semibold text-slate-900 truncate select-none"
                                                x-text="w.key === 'custom-chart' ? (w.config?.title || widgetLabel(w.key)) : widgetLabel(w.key)"
                                            ></p>
                                        </div>

                                        <div class="flex shrink-0 items-center gap-1 pointer-events-auto">
                                            <span class="sr-only">Lebar Kolom</span>
                                            <div class="inline-flex rounded-lg border border-slate-200/85 bg-slate-100/80 p-0.5 tabular-nums" role="group" aria-label="Atur Lebar Kolom">
                                                <button
                                                    type="button"
                                                    draggable="false"
                                                    @mousedown.stop
                                                    @pointerdown.stop
                                                    @click="setWidgetCols(index, 1)"
                                                    :disabled="saving"
                                                    :class="parseInt(w.cols, 10) === 1 ? 'bg-white text-[#0b6175] font-semibold shadow-2xs ring-1 ring-[#0b6175]/20' : 'text-slate-500 hover:bg-white/60 hover:text-slate-900 font-medium'"
                                                    class="rounded-md px-2 py-0.5 text-[11px] transition disabled:cursor-not-allowed"
                                                    aria-label="Lebar 1 kolom"
                                                >1 Kol</button>
                                                <button
                                                    type="button"
                                                    draggable="false"
                                                    @mousedown.stop
                                                    @pointerdown.stop
                                                    @click="setWidgetCols(index, 2)"
                                                    :disabled="saving"
                                                    :class="parseInt(w.cols, 10) === 2 ? 'bg-white text-[#0b6175] font-semibold shadow-2xs ring-1 ring-[#0b6175]/20' : 'text-slate-500 hover:bg-white/60 hover:text-slate-900 font-medium'"
                                                    class="rounded-md px-2 py-0.5 text-[11px] transition disabled:cursor-not-allowed"
                                                    aria-label="Lebar 2 kolom"
                                                >2 Kol</button>
                                                <button
                                                    type="button"
                                                    draggable="false"
                                                    @mousedown.stop
                                                    @pointerdown.stop
                                                    @click="setWidgetCols(index, 3)"
                                                    :disabled="saving"
                                                    :class="parseInt(w.cols, 10) === 3 ? 'bg-white text-[#0b6175] font-semibold shadow-2xs ring-1 ring-[#0b6175]/20' : 'text-slate-500 hover:bg-white/60 hover:text-slate-900 font-medium'"
                                                    class="rounded-md px-2 py-0.5 text-[11px] transition disabled:cursor-not-allowed"
                                                    aria-label="Lebar 3 kolom"
                                                >3 Kol</button>
                                            </div>

                                            <button
                                                type="button"
                                                draggable="false"
                                                @mousedown.stop
                                                @pointerdown.stop
                                                @click="moveUp(index)"
                                                :disabled="saving || index === 0"
                                                :aria-label="`Geser ke kiri atau atas ${widgetLabel(w.key)}`"
                                                title="Geser ke kiri atau atas"
                                                class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg border border-transparent text-slate-500 transition hover:bg-slate-200/70 hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-35"
                                            >
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
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
                                                :aria-label="`Geser ke kanan atau bawah ${widgetLabel(w.key)}`"
                                                title="Geser ke kanan atau bawah"
                                                class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg border border-transparent text-slate-500 transition hover:bg-slate-200/70 hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-35"
                                            >
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                                </svg>
                                            </button>

                                            <template x-if="w.key === 'custom-chart'">
                                                <button
                                                    type="button"
                                                    draggable="false"
                                                    @mousedown.stop
                                                    @pointerdown.stop
                                                    @click="toggleConfigPanel(index)"
                                                    :title="w._openConfig ? 'Tutup Pengaturan' : 'Atur Grafik'"
                                                    :aria-label="`Atur grafik ${widgetLabel(w.key)}`"
                                                    :class="w._openConfig ? 'bg-[#e6f4f6] text-[#0b6175] ring-1 ring-[#0b6175]/15' : 'text-slate-500 hover:bg-slate-200/60 hover:text-[#0b6175]'"
                                                    class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg transition"
                                                >
                                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
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
                                                :aria-label="`Hapus ${widgetLabel(w.key)}`"
                                                :title="isLockedWidget(w.key) ? 'Widget wajib ada untuk peran ini' : 'Hapus widget dari dashboard'"
                                                class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg border border-transparent text-slate-400 transition hover:bg-rose-50 hover:text-rose-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-400/40 disabled:cursor-not-allowed disabled:opacity-40"
                                            >
                                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>

                                    {{-- Card Body Wrapper --}}
                                    <div class="flex flex-1 flex-col gap-2.5 p-3.5">
                                        {{-- Panel Konfigurasi Inline untuk Custom Chart --}}
                                        <template x-if="w.key === 'custom-chart' && w._openConfig">
                                            <div class="space-y-2 rounded-lg border border-slate-200 bg-slate-50/90 p-2.5 text-xs">
                                                <div>
                                                    <label class="mb-1 block font-semibold text-slate-700">Judul Grafik</label>
                                                    <input
                                                        type="text"
                                                        x-model="w.config.title"
                                                        maxlength="60"
                                                        class="w-full rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs text-slate-800 outline-none focus:border-[#16a3b8]"
                                                        placeholder="Contoh: Distribusi Skor NKO"
                                                    />
                                                </div>
                                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                                    <div>
                                                        <label class="mb-1 block font-semibold text-slate-700">Tipe Grafik</label>
                                                        <select
                                                            x-model="w.config.chart_type"
                                                            class="w-full rounded-md border border-slate-300 bg-white px-2 py-1 text-xs text-slate-800 outline-none focus:border-[#16a3b8]"
                                                        >
                                                            <option value="bar">Batang</option>
                                                            <option value="line">Garis</option>
                                                            <option value="donut">Donat</option>
                                                        </select>
                                                    </div>
                                                    <div>
                                                        <label class="mb-1 block font-semibold text-slate-700">Metrik</label>
                                                        <select
                                                            x-model="w.config.metric"
                                                            class="w-full rounded-md border border-slate-300 bg-white px-2 py-1 text-xs text-slate-800 outline-none focus:border-[#16a3b8]"
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
                                                class="preview-chart-container canvas-chart-container relative h-44 w-full rounded-xl border border-slate-200/80 bg-slate-50/70 p-2"
                                                x-effect="initPreviewChart($el, w)"
                                            >
                                                <div class="h-full w-full echarts-instance" style="width: 100%; height: 158px;" :id="`preview-chart-${w._uid}`"></div>
                                            </div>
                                        </template>

                                        <template x-if="w.key === 'trend-chart'">
                                            <div
                                                class="preview-chart-container canvas-chart-container relative h-40 w-full rounded-xl border border-slate-200/80 bg-slate-50/70 p-2"
                                                x-effect="initPreviewChart($el, w)"
                                            >
                                                <div class="h-full w-full echarts-instance" style="width: 100%; height: 142px;" :id="`preview-chart-${w._uid}`"></div>
                                            </div>
                                        </template>

                                        {{-- High-Fidelity Representative Visual Mini-Mockups --}}
                                        <div class="preview-sketch flex flex-1 flex-col justify-between rounded-xl border border-slate-200/75 bg-slate-50/70 p-3" data-mini-mockup="true">
                                            {{-- 1. nko-ranking --}}
                                            <template x-if="w.key === 'nko-ranking'">
                                                <div class="space-y-1.5">
                                                    <div class="flex items-center justify-between text-[11px] font-semibold text-slate-800">
                                                        <span class="flex items-center gap-1.5 truncate">
                                                            <span class="h-2 w-2 rounded-full bg-[#0b6175] shrink-0"></span>
                                                            Unit A
                                                        </span>
                                                        <span class="rounded bg-[#e6f4f6] px-1.5 py-0.5 text-[10px] font-semibold text-[#0b6175] ring-1 ring-[#0b6175]/15 shrink-0">Contoh</span>
                                                    </div>
                                                    <div class="h-2 w-full overflow-hidden rounded-full bg-slate-200/80">
                                                        <div class="h-full rounded-full bg-[#0b6175]" style="width: 82%;"></div>
                                                    </div>
                                                    <div class="flex items-center justify-between text-[11px] font-semibold text-slate-800">
                                                        <span class="flex items-center gap-1.5 truncate">
                                                            <span class="h-2 w-2 rounded-full bg-[#16a3b8] shrink-0"></span>
                                                            Unit B
                                                        </span>
                                                        <span class="rounded bg-cyan-50 px-1.5 py-0.5 text-[10px] font-semibold text-cyan-800 ring-1 ring-cyan-600/15 shrink-0">Contoh</span>
                                                    </div>
                                                    <div class="h-2 w-full overflow-hidden rounded-full bg-slate-200/80">
                                                        <div class="h-full rounded-full bg-[#16a3b8]" style="width: 68%;"></div>
                                                    </div>
                                                    <div class="flex items-center justify-between text-[11px] font-semibold text-slate-700">
                                                        <span class="flex items-center gap-1.5 truncate">
                                                            <span class="h-2 w-2 rounded-full bg-slate-500 shrink-0"></span>
                                                            Unit C
                                                        </span>
                                                        <span class="rounded bg-slate-200/75 px-1.5 py-0.5 text-[10px] font-semibold text-slate-700 shrink-0">Contoh</span>
                                                    </div>
                                                    <div class="h-2 w-full overflow-hidden rounded-full bg-slate-200/80">
                                                        <div class="h-full rounded-full bg-slate-500" style="width: 54%;"></div>
                                                    </div>
                                                </div>
                                            </template>

                                            {{-- 2. aset-upt-chart --}}
                                            <template x-if="w.key === 'aset-upt-chart'">
                                                <div class="space-y-2">
                                                    <div class="flex items-center justify-between text-[11px]">
                                                        <span class="font-bold text-slate-800 truncate">Rekapitulasi Aset Unit</span>
                                                        <span class="rounded bg-[#e6f4f6] px-1.5 py-0.5 text-[9px] font-semibold text-[#0b6175] border border-[#0b6175]/15 shrink-0">Contoh</span>
                                                    </div>
                                                    <svg class="h-16 w-full" viewBox="0 0 240 60" fill="none">
                                                        <line x1="10" y1="52" x2="230" y2="52" stroke="#cbd5e1" stroke-width="1" />
                                                        <rect x="20" y="16" width="16" height="36" rx="2" fill="#0b6175" />
                                                        <rect x="38" y="24" width="16" height="28" rx="2" fill="#16a3b8" />
                                                        <rect x="74" y="10" width="16" height="42" rx="2" fill="#0b6175" />
                                                        <rect x="92" y="18" width="16" height="34" rx="2" fill="#16a3b8" />
                                                        <rect x="128" y="22" width="16" height="30" rx="2" fill="#0b6175" />
                                                        <rect x="146" y="30" width="16" height="22" rx="2" fill="#16a3b8" />
                                                        <rect x="182" y="14" width="16" height="38" rx="2" fill="#0b6175" />
                                                        <rect x="200" y="26" width="16" height="26" rx="2" fill="#16a3b8" />
                                                    </svg>
                                                    <div class="flex items-center justify-between text-[10px] font-medium text-slate-500 px-2">
                                                        <span>Unit A</span><span>Unit B</span><span>Unit C</span><span>Unit D</span>
                                                    </div>
                                                </div>
                                            </template>

                                            {{-- 3. target-realisasi-per-indikator --}}
                                            <template x-if="w.key === 'target-realisasi-per-indikator'">
                                                <div class="space-y-2">
                                                    <div class="flex items-center justify-between text-[11px]">
                                                        <span class="font-bold text-slate-800 truncate">Target vs Realisasi Indikator</span>
                                                        <span class="rounded bg-emerald-50 px-1.5 py-0.5 text-[9px] font-bold text-emerald-700 border border-emerald-200/80 shrink-0">Terstruktur</span>
                                                    </div>
                                                    <div class="space-y-1.5 text-[11px]">
                                                        <div>
                                                            <div class="flex justify-between text-slate-700 mb-0.5">
                                                                <span class="truncate font-medium">Indikator Contoh A</span>
                                                                <span class="font-semibold text-[#0b6175] shrink-0">Contoh</span>
                                                            </div>
                                                            <div class="h-2 w-full rounded-full bg-slate-200/80 overflow-hidden flex">
                                                                <div class="bg-[#0b6175] h-full rounded-full" style="width: 78%;"></div>
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <div class="flex justify-between text-slate-700 mb-0.5">
                                                                <span class="truncate font-medium">Indikator Contoh B</span>
                                                                <span class="font-semibold text-[#0e7490] shrink-0">Contoh</span>
                                                            </div>
                                                            <div class="h-2 w-full rounded-full bg-slate-200/80 overflow-hidden flex">
                                                                <div class="bg-[#16a3b8] h-full rounded-full" style="width: 64%;"></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </template>

                                            {{-- 4. nko-donut --}}
                                            <template x-if="w.key === 'nko-donut'">
                                                <div class="flex items-center justify-between gap-3">
                                                    <div class="relative flex h-20 w-20 shrink-0 items-center justify-center">
                                                        <svg class="h-20 w-20 -rotate-90" viewBox="0 0 36 36">
                                                            <path class="text-slate-200" stroke-width="4.5" stroke="currentColor" fill="none" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                                                            <path class="text-emerald-500" stroke-dasharray="65, 100" stroke-width="4.5" stroke-linecap="round" stroke="currentColor" fill="none" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                                                            <path class="text-[#0b6175]" stroke-dasharray="25, 100" stroke-dashoffset="-65" stroke-width="4.5" stroke-linecap="round" stroke="currentColor" fill="none" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                                                            <path class="text-amber-500" stroke-dasharray="10, 100" stroke-dashoffset="-90" stroke-width="4.5" stroke-linecap="round" stroke="currentColor" fill="none" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                                                        </svg>
                                                        <div class="absolute inset-0 flex flex-col items-center justify-center text-center">
                                                            <span class="text-[10px] font-bold text-slate-800">Contoh</span>
                                                            <span class="text-[8px] font-bold text-slate-500">NKO</span>
                                                        </div>
                                                    </div>
                                                    <div class="flex flex-1 flex-col justify-center space-y-1 text-[11px]">
                                                        <div class="flex items-center justify-between"><span class="flex items-center gap-1.5 font-medium text-slate-700 truncate"><span class="h-2 w-2 rounded-full bg-emerald-500 shrink-0"></span>Tercapai</span><span class="font-semibold text-emerald-700 shrink-0">Contoh</span></div>
                                                        <div class="flex items-center justify-between"><span class="flex items-center gap-1.5 font-medium text-slate-700 truncate"><span class="h-2 w-2 rounded-full bg-[#0b6175] shrink-0"></span>Hampir</span><span class="font-semibold text-[#0b6175] shrink-0">Contoh</span></div>
                                                        <div class="flex items-center justify-between"><span class="flex items-center gap-1.5 font-medium text-slate-700 truncate"><span class="h-2 w-2 rounded-full bg-amber-500 shrink-0"></span>Perhatian</span><span class="font-semibold text-amber-700 shrink-0">Contoh</span></div>
                                                    </div>
                                                </div>
                                            </template>

                                            {{-- 5. kpi-rag-status --}}
                                            <template x-if="w.key === 'kpi-rag-status'">
                                                <div class="grid grid-cols-3 gap-1.5 tabular-nums">
                                                    <div class="min-w-0 rounded-lg border border-emerald-200/85 bg-emerald-50/80 px-1.5 py-2 text-center">
                                                        <span class="block truncate text-[8.5px] font-bold uppercase tracking-tight text-emerald-700">Tercapai</span>
                                                        <span class="block py-0.5 text-xs font-bold text-emerald-950">Contoh</span>
                                                        <span class="block text-[9px] font-semibold text-emerald-700">&ge; 100%</span>
                                                    </div>
                                                    <div class="min-w-0 rounded-lg border border-amber-200/85 bg-amber-50/80 px-1.5 py-2 text-center">
                                                        <span class="block truncate text-[8.5px] font-bold uppercase tracking-tight text-amber-700">Hampir</span>
                                                        <span class="block py-0.5 text-xs font-bold text-amber-950">Contoh</span>
                                                        <span class="block text-[9px] font-semibold text-amber-700">95 s.d. 99%</span>
                                                    </div>
                                                    <div class="min-w-0 rounded-lg border border-[#bce3e8] bg-[#e6f4f6]/80 px-1.5 py-2 text-center">
                                                        <span class="block truncate text-[8.5px] font-bold uppercase tracking-tight text-[#0b6175]">Perhatian</span>
                                                        <span class="block py-0.5 text-xs font-bold text-slate-900">Contoh</span>
                                                        <span class="block text-[9px] font-semibold text-[#0b6175]">&lt; 95%</span>
                                                    </div>
                                                </div>
                                            </template>

                                            {{-- 6. kpi-summary --}}
                                            <template x-if="w.key === 'kpi-summary'">
                                                <div class="grid grid-cols-3 gap-2">
                                                    <div class="rounded-lg border border-[#bce3e8] bg-[#e6f4f6]/65 p-2">
                                                        <p class="text-[10px] font-bold uppercase tracking-wide text-[#0b6175] truncate">Rerata NKO</p>
                                                        <p class="mt-0.5 text-xs font-bold text-slate-900">Contoh</p>
                                                        <div class="mt-1.5 h-1.5 w-full rounded-full bg-[#bce3e8]/70"><div class="h-full rounded-full bg-[#0b6175]" style="width: 78%;"></div></div>
                                                    </div>
                                                    <div class="rounded-lg border border-emerald-200/80 bg-emerald-50/70 p-2">
                                                        <p class="text-[10px] font-bold uppercase tracking-wide text-emerald-700 truncate">Indikator</p>
                                                        <p class="mt-0.5 text-xs font-bold text-emerald-950">Contoh</p>
                                                        <div class="mt-1.5 h-1.5 w-full rounded-full bg-emerald-200/75"><div class="h-full rounded-full bg-emerald-600" style="width: 68%;"></div></div>
                                                    </div>
                                                    <div class="rounded-lg border border-slate-200/85 bg-white p-2">
                                                        <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500 truncate">Periode</p>
                                                        <p class="mt-0.5 text-xs font-bold text-slate-900">Contoh</p>
                                                        <span class="mt-1 inline-block rounded bg-[#e6f4f6] px-1.5 text-[9px] font-semibold text-[#0b6175]">Final</span>
                                                    </div>
                                                </div>
                                            </template>

                                            {{-- 7. dataset-status --}}
                                            <template x-if="w.key === 'dataset-status'">
                                                <div class="grid grid-cols-3 gap-2 text-center">
                                                    <div class="rounded-lg border border-emerald-200/80 bg-emerald-50/70 p-2">
                                                        <span class="block text-[10px] font-bold uppercase tracking-wide text-emerald-700">Status</span>
                                                        <span class="text-sm font-extrabold text-emerald-950">{{ \App\Enums\DatasetStatus::READY->label() }}</span>
                                                        <span class="block text-[9px] font-semibold text-emerald-700 mt-0.5">Contoh</span>
                                                    </div>
                                                    <div class="rounded-lg border border-slate-200/85 bg-white p-2">
                                                        <span class="block text-[10px] font-bold uppercase tracking-wide text-slate-500">Baris Data</span>
                                                        <span class="text-xs font-bold text-slate-900 mt-0.5 block">Contoh</span>
                                                        <span class="block text-[9px] font-medium text-slate-500 mt-0.5">Unit contoh</span>
                                                    </div>
                                                    <div class="rounded-lg border border-slate-200/85 bg-white p-2">
                                                        <span class="block text-[10px] font-bold uppercase tracking-wide text-slate-500">Periode</span>
                                                        <span class="text-xs font-bold text-slate-900 mt-0.5 block">Contoh</span>
                                                        <span class="block text-[9px] text-[#0b6175] font-semibold mt-0.5">Terverifikasi</span>
                                                    </div>
                                                </div>
                                            </template>

                                            {{-- 8. pencapaian-nko --}}
                                            <template x-if="w.key === 'pencapaian-nko'">
                                                <div class="space-y-1.5">
                                                    <div class="flex items-center justify-between text-[11px]">
                                                        <span class="font-bold text-slate-800 truncate">Pencapaian % per Unit</span>
                                                        <span class="text-[10px] font-semibold text-[#0b6175] shrink-0">Contoh</span>
                                                    </div>
                                                    <div class="space-y-1">
                                                        <div class="flex items-center justify-between text-[10px]">
                                                            <span class="w-16 truncate font-medium text-slate-700">Unit A</span>
                                                            <div class="h-2 flex-1 mx-2 rounded-full bg-slate-200/80 overflow-hidden"><div class="h-full bg-[#0b6175] rounded-full" style="width: 80%;"></div></div>
                                                            <span class="font-semibold text-[#0b6175] shrink-0">Contoh</span>
                                                        </div>
                                                        <div class="flex items-center justify-between text-[10px]">
                                                            <span class="w-16 truncate font-medium text-slate-700">Unit B</span>
                                                            <div class="h-2 flex-1 mx-2 rounded-full bg-slate-200/80 overflow-hidden"><div class="h-full bg-[#16a3b8] rounded-full" style="width: 65%;"></div></div>
                                                            <span class="font-semibold text-[#0e7490] shrink-0">Contoh</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </template>

                                            {{-- 9. admin-quick-actions --}}
                                            <template x-if="w.key === 'admin-quick-actions'">
                                                <div class="grid grid-cols-3 gap-2">
                                                    <div class="flex items-center gap-1.5 rounded-lg border border-slate-200/85 bg-white p-2 text-left">
                                                        <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-[#e6f4f6] text-[#0b6175] ring-1 ring-[#0b6175]/15">
                                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" /></svg>
                                                        </div>
                                                        <div class="min-w-0"><p class="truncate text-[10px] font-bold text-slate-900">Upload</p><p class="text-[8px] font-medium text-slate-500">Dataset</p></div>
                                                    </div>
                                                    <div class="flex items-center gap-1.5 rounded-lg border border-slate-200/85 bg-white p-2 text-left">
                                                        <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-emerald-50 text-emerald-700 ring-1 ring-emerald-600/15">
                                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                                                        </div>
                                                        <div class="min-w-0"><p class="truncate text-[10px] font-bold text-slate-900">Audit</p><p class="text-[8px] font-medium text-slate-500">Log</p></div>
                                                    </div>
                                                    <div class="flex items-center gap-1.5 rounded-lg border border-slate-200/85 bg-white p-2 text-left">
                                                        <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-amber-50 text-amber-700 ring-1 ring-amber-600/15">
                                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
                                                        </div>
                                                        <div class="min-w-0"><p class="truncate text-[10px] font-bold text-slate-900">User</p><p class="text-[8px] font-medium text-slate-500">Kelola</p></div>
                                                    </div>
                                                </div>
                                            </template>

                                            {{-- 10. admin-summary --}}
                                            <template x-if="w.key === 'admin-summary'">
                                                <div class="grid grid-cols-3 gap-2">
                                                    <div class="rounded-lg border border-slate-200/85 bg-white p-2">
                                                        <span class="block text-[9px] font-bold uppercase tracking-wider text-slate-500 truncate">Total Dataset</span>
                                                        <span class="mt-0.5 block text-xs font-bold text-slate-900">Contoh</span>
                                                        <span class="block text-[8px] text-emerald-700 font-semibold mt-0.5">Status siap</span>
                                                    </div>
                                                    <div class="rounded-lg border border-slate-200/85 bg-white p-2">
                                                        <span class="block text-[9px] font-bold uppercase tracking-wider text-slate-500 truncate">Pengguna</span>
                                                        <span class="mt-0.5 block text-xs font-bold text-slate-900">Contoh</span>
                                                        <span class="block text-[8px] text-[#0b6175] font-semibold mt-0.5">Peran aktif</span>
                                                    </div>
                                                    <div class="rounded-lg border border-slate-200/85 bg-white p-2">
                                                        <span class="block text-[9px] font-bold uppercase tracking-wider text-slate-500 truncate">Sistem</span>
                                                        <span class="mt-0.5 block text-xs font-bold text-emerald-700">Optimal</span>
                                                        <span class="block text-[8px] font-medium text-slate-500 mt-0.5">Audit Aman</span>
                                                    </div>
                                                </div>
                                            </template>

                                            {{-- 11. kinerja-detail --}}
                                            <template x-if="w.key === 'kinerja-detail'">
                                                <div class="overflow-hidden rounded-lg border border-slate-200/85 text-[10px]">
                                                    <div class="grid grid-cols-12 border-b border-slate-200 bg-slate-50 px-2 py-1.5 font-semibold text-slate-600 uppercase tracking-wider">
                                                        <span class="col-span-6 truncate">Indikator Metrik</span>
                                                        <span class="col-span-3 text-right">Target</span>
                                                        <span class="col-span-3 text-right">Realisasi</span>
                                                    </div>
                                                    <div class="divide-y divide-slate-200/70 bg-white">
                                                        <div class="grid grid-cols-12 px-2 py-1 items-center">
                                                            <span class="col-span-6 truncate font-medium text-slate-800">Indikator Contoh A</span>
                                                            <span class="col-span-3 text-right text-slate-500">Contoh</span>
                                                            <span class="col-span-3 text-right font-semibold text-[#0b6175]">Contoh</span>
                                                        </div>
                                                        <div class="grid grid-cols-12 px-2 py-1 items-center bg-slate-50/50">
                                                            <span class="col-span-6 truncate font-medium text-slate-800">Indikator Contoh B</span>
                                                            <span class="col-span-3 text-right text-slate-500">Contoh</span>
                                                            <span class="col-span-3 text-right font-semibold text-emerald-700">Contoh</span>
                                                        </div>
                                                        <div class="grid grid-cols-12 px-2 py-1 items-center">
                                                            <span class="col-span-6 truncate font-medium text-slate-800">Indikator Contoh C</span>
                                                            <span class="col-span-3 text-right text-slate-500">Contoh</span>
                                                            <span class="col-span-3 text-right font-semibold text-[#0e7490]">Contoh</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </template>

                                            {{-- 12. kinerja-realisasi --}}
                                            <template x-if="w.key === 'kinerja-realisasi'">
                                                <div class="space-y-1.5">
                                                    <div class="flex items-center justify-between text-[11px] font-bold text-slate-800">
                                                        <span class="truncate">Realisasi vs Target Bulanan</span>
                                                        <span class="rounded bg-[#e6f4f6] px-1.5 py-0.5 text-[9px] font-semibold text-[#0b6175] shrink-0">Contoh</span>
                                                    </div>
                                                    <div class="space-y-1 text-[10px]">
                                                        <div>
                                                            <div class="flex justify-between text-slate-700 mb-0.5">
                                                                <span class="truncate font-medium">Realisasi Contoh</span>
                                                                <span class="font-semibold text-[#0b6175] shrink-0">Contoh</span>
                                                            </div>
                                                            <div class="h-1.5 w-full rounded-full bg-slate-200/80 overflow-hidden"><div class="h-full bg-[#0b6175] rounded-full" style="width: 78%;"></div></div>
                                                        </div>
                                                        <div>
                                                            <div class="flex justify-between text-slate-700 mb-0.5">
                                                                <span class="truncate font-medium">Target Contoh</span>
                                                                <span class="font-semibold text-[#0e7490] shrink-0">Contoh</span>
                                                            </div>
                                                            <div class="h-1.5 w-full rounded-full bg-slate-200/80 overflow-hidden"><div class="h-full bg-[#16a3b8] rounded-full" style="width: 65%;"></div></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </template>

                                            {{-- 13. recent-audit --}}
                                            <template x-if="w.key === 'recent-audit'">
                                                <div class="space-y-1 text-[10px]">
                                                    <div class="flex items-center justify-between rounded-lg border border-slate-200/80 bg-white p-1.5">
                                                        <div class="flex items-center gap-1.5 truncate">
                                                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 shrink-0"></span>
                                                            <span class="font-semibold text-slate-800 truncate">contoh@domain</span>
                                                            <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[8px] font-semibold text-slate-600 shrink-0">Perbarui</span>
                                                        </div>
                                                        <span class="text-slate-500 shrink-0 text-[9px]">Contoh</span>
                                                    </div>
                                                    <div class="flex items-center justify-between rounded-lg border border-slate-200/80 bg-white p-1.5">
                                                        <div class="flex items-center gap-1.5 truncate">
                                                            <span class="h-1.5 w-1.5 rounded-full bg-[#16a3b8] shrink-0"></span>
                                                            <span class="font-semibold text-slate-800 truncate">contoh@domain</span>
                                                            <span class="rounded bg-[#e6f4f6] px-1.5 py-0.5 text-[8px] font-semibold text-[#0b6175] shrink-0">Impor</span>
                                                        </div>
                                                        <span class="text-slate-500 shrink-0 text-[9px]">Contoh</span>
                                                    </div>
                                                    <div class="flex items-center justify-between rounded-lg border border-slate-200/80 bg-white p-1.5">
                                                        <div class="flex items-center gap-1.5 truncate">
                                                            <span class="h-1.5 w-1.5 rounded-full bg-slate-400 shrink-0"></span>
                                                            <span class="font-semibold text-slate-800 truncate">contoh@domain</span>
                                                            <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[8px] font-semibold text-slate-600 shrink-0">Tata Letak</span>
                                                        </div>
                                                        <span class="text-slate-500 shrink-0 text-[9px]">Contoh</span>
                                                    </div>
                                                </div>
                                            </template>

                                            {{-- 14. failed-datasets --}}
                                            <template x-if="w.key === 'failed-datasets'">
                                                <div class="space-y-1.5 text-[10px]">
                                                    <div class="flex items-center justify-between rounded-lg border border-rose-200/85 bg-rose-50/75 p-2">
                                                        <div class="flex items-center gap-2 truncate">
                                                            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-rose-100 text-rose-700 font-bold text-[10px]">!</span>
                                                            <div class="min-w-0">
                                                                <p class="truncate font-bold text-rose-950">berkas-contoh.xlsx</p>
                                                                <p class="text-[9px] text-rose-700 truncate">Contoh catatan validasi</p>
                                                            </div>
                                                        </div>
                                                        <span class="rounded bg-rose-100 px-1.5 py-0.5 font-bold text-rose-800 text-[8px] shrink-0">{{ \App\Enums\DatasetStatus::FAILED->label() }}</span>
                                                    </div>
                                                    <div class="flex items-center justify-between text-[10px] text-slate-600 px-1">
                                                        <span class="truncate">Antrean contoh</span>
                                                        <span class="font-semibold text-emerald-700 shrink-0">Aman</span>
                                                    </div>
                                                </div>
                                            </template>

                                            {{-- 15. layout-editor-placeholder --}}
                                            <template x-if="w.key === 'layout-editor-placeholder'">
                                                <div class="flex items-center justify-between rounded-lg border border-slate-200/85 bg-white p-2.5 text-[11px]">
                                                    <div class="flex items-center gap-2 truncate">
                                                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-[#e6f4f6] text-[#0b6175] ring-1 ring-[#0b6175]/15 font-bold text-xs">BI</span>
                                                        <div class="min-w-0">
                                                            <p class="font-bold text-slate-900 truncate">Editor Tata Letak</p>
                                                            <p class="text-[9px] text-slate-500 truncate">Drag &amp; drop kanvas</p>
                                                        </div>
                                                    </div>
                                                    <span class="rounded bg-[#e6f4f6] px-2 py-0.5 text-[10px] font-semibold text-[#0b6175] shrink-0">Aktif</span>
                                                </div>
                                            </template>

                                            {{-- 16. Fallback untuk custom-chart / trend-chart --}}
                                            <template x-if="['custom-chart', 'trend-chart'].includes(w.key)">
                                                <div class="space-y-1">
                                                    <div class="flex items-center justify-between text-[11px] font-semibold text-slate-800">
                                                        <span class="truncate" x-text="w.config?.title || widgetLabel(w.key)"></span>
                                                        <span class="rounded bg-[#e6f4f6] px-1.5 py-0.5 text-[9px] font-semibold text-[#0b6175] shrink-0" x-text="(w.config?.chart_type || 'chart').toUpperCase()"></span>
                                                    </div>
                                                    <div class="flex items-center justify-between text-[10px] text-slate-500">
                                                        <span class="truncate">Interaktif ECharts Rendered</span>
                                                        <span class="font-semibold text-emerald-700 shrink-0">Siap</span>
                                                    </div>
                                                </div>
                                            </template>

                                            {{-- Compact Bottom Row: Visualization Type + Grid Proportion --}}
                                            <div class="mt-2.5 flex items-center justify-between border-t border-slate-200/75 pt-1.5 text-[10px] text-slate-600 tabular-nums">
                                                <span class="font-semibold text-slate-700" x-text="paletteVisual(w.key).label"></span>
                                                <span
                                                    class="rounded-md border border-slate-200/75 bg-white px-1.5 py-0.5 text-[9px] font-semibold text-slate-600"
                                                    x-text="`${parseInt(w.cols, 10) || 3} Kolom (${parseInt(w.cols, 10) === 1 ? '33%' : (parseInt(w.cols, 10) === 2 ? '66%' : '100%')})`"
                                                ></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>

                        {{-- Empty Canvas State --}}
                        <div
                            x-show="activeWidgets.length === 0"
                            x-cloak
                            class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-white/95 p-10 text-center shadow-2xs"
                        >
                            <span class="mb-3 inline-flex h-11 w-11 items-center justify-center rounded-xl bg-[#e6f4f6] text-[#0b6175] ring-1 ring-[#0b6175]/15">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                </svg>
                            </span>
                            <p class="text-sm font-semibold text-slate-800">Kanvas Masih Kosong</p>
                            <p class="mt-1 text-xs text-slate-500">Tarik atau klik + Tambah pada panel komponen di sebelah kiri.</p>
                        </div>
                    </div>
                </div>
            </section>
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
                const targets = (Array.isArray(initial.targets) ? initial.targets : []).map((t) => ({
                    ...t,
                    key: t.key || t.layout_key,
                }));
                const catalog = Array.isArray(initial.catalog) ? initial.catalog : [];
                const defaultTargetKey = initial.initial_target_key || (targets[0]?.layout_key ?? '');
                const initialTarget = targets.find((t) => t.layout_key === defaultTargetKey) || targets[0] || null;

                return {
                    catalog,
                    targets,
                    catalogSearch: '',
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
                    stickyConfigTop: 160,
                    topBarResizeObserver: null,
                    currentUserLayoutKey: initial.current_user_layout_key || '',
                    toast: { show: false, message: '', type: 'success', timer: null },
                    previewChartInstances: {},
                    previewResizeFrame: null,

                    syncStickyTop() {
                        const bar = document.getElementById('builder-top-bar');
                        if (!bar) {
                            return;
                        }
                        // Sticky top-20 = 80px below viewport top; reserve 16px breathing gap below #builder-top-bar.
                        this.stickyConfigTop = Math.ceil(80 + bar.offsetHeight + 16);
                    },

                    desktopStickyStyle() {
                        if (this.viewportWidth < 1024) {
                            return {};
                        }
                        const topPx = this.stickyConfigTop;
                        return {
                            top: `${topPx}px`,
                            '--builder-pane-max-h': `calc(100vh - ${topPx + 16}px)`,
                        };
                    },

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

                        this.$nextTick(() => {
                            this.syncStickyTop();
                            const bar = document.getElementById('builder-top-bar');
                            if (bar && typeof ResizeObserver !== 'undefined') {
                                this.topBarResizeObserver = new ResizeObserver(() => this.syncStickyTop());
                                this.topBarResizeObserver.observe(bar);
                            }
                        });

                        window.addEventListener('keydown', (e) => {
                            if (e.key !== 'Escape') {
                                return;
                            }
                            if (this.canvasDragIndex !== null || this.pointerDrag.active || this.paletteDragKey) {
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
                            cols: parseInt(item.cols, 10) || cat?.default_cols || 3,
                            config: item.config || (item.key === 'custom-chart' ? { title: 'Grafik Kustom', chart_type: 'bar', metric: 'nko_score' } : {}),
                            _openConfig: false,
                        };
                    },

                    currentTarget() {
                        return this.targets.find((t) => t.layout_key === this.selectedTargetKey || t.key === this.selectedTargetKey) || null;
                    },

                    isCustomized() {
                        return Boolean(this.currentTarget()?.is_customized);
                    },

                    onTargetChange() {
                        this.selectTarget(this.selectedTargetKey);
                        this.$nextTick(() => {
                            this.resizePreviewCharts();
                        });
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
                        this.showToast(`Widget "${this.widgetLabel(key)}" ditambahkan ke kanvas.`, 'success');
                        this.$nextTick(() => {
                            this.resizePreviewCharts();
                        });
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
                        this.$nextTick(() => {
                            this.resizePreviewCharts();
                        });
                    },

                    toggleConfigPanel(index) {
                        const item = this.activeWidgets[index];
                        if (!item) {
                            return;
                        }
                        item._openConfig = !item._openConfig;
                        this.$nextTick(() => {
                            this.resizePreviewCharts();
                        });
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
                        this.$nextTick(() => {
                            this.resizePreviewCharts();
                        });
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
                        this.$nextTick(() => {
                            this.resizePreviewCharts();
                        });
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
                            'layout-editor-placeholder': { type: 'generic', label: 'Editor Tata Letak' },
                        };

                        return map[key] || { type: 'generic', label: 'Komponen' };
                    },

                    filteredCatalog() {
                        const q = (this.catalogSearch || '').trim().toLowerCase();
                        return this.catalog.filter((c) => {
                            const cat = c.category || this.getWidgetCategory(c.key);
                            if (this.catalogFilterCategory && this.catalogFilterCategory !== 'all' && cat !== this.catalogFilterCategory) {
                                return false;
                            }
                            if (q !== '') {
                                const label = String(c.label || '').toLowerCase();
                                const key = String(c.key || '').toLowerCase();
                                const visualLabel = String(this.paletteVisual(c.key).label || '').toLowerCase();
                                return label.includes(q) || key.includes(q) || visualLabel.includes(q);
                            }
                            return true;
                        });
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

                    paletteThumbClass(key) {
                        const cat = this.getWidgetCategory(key);
                        if (cat === 'Kinerja / KPI') {
                            return 'bg-[#e6f4f6] text-[#0b6175] ring-1 ring-[#0b6175]/15';
                        }
                        if (cat === 'Manajemen Aset') {
                            return 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-600/15';
                        }
                        return 'bg-amber-50 text-amber-700 ring-1 ring-amber-600/15';
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

                    startPaletteDrag(eventOrKey, maybeKey) {
                        if (this.saving) return;
                        const key = typeof maybeKey === 'string' ? maybeKey : eventOrKey;
                        const event = typeof maybeKey === 'string' ? eventOrKey : null;
                        this.paletteDragKey = key;
                        if (event && event.dataTransfer) {
                            event.dataTransfer.effectAllowed = 'copyMove';
                            event.dataTransfer.setData('text/plain', key);
                        }
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

                        if (parseInt(cols, 10) === 3 || window.innerWidth < 1024) {
                            return relY < height / 2 ? 'before' : 'after';
                        }

                        if (relY < height * 0.35) return 'before';
                        if (relY > height * 0.65) return 'after';
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

                        if (isDesktop && isScrollable) {
                            const rect = viewport.getBoundingClientRect();
                            const visibleTop = Math.max(rect.top, 0);
                            const visibleBottom = Math.min(rect.bottom, window.innerHeight);

                            if (clientY < visibleTop - 20 || clientY > visibleBottom + 20) {
                                this.stopAutoScroll();
                                return;
                            }

                            targetContainer = viewport;
                            isWindow = false;

                            const edgeZone = 60;
                            if (clientY - visibleTop < edgeZone && clientY >= visibleTop - 20) {
                                const dist = Math.max(0, clientY - visibleTop);
                                const ratio = 1 - (dist / edgeZone);
                                targetSpeed = -Math.round(2 + 12 * (ratio * ratio));
                            } else if (visibleBottom - clientY < edgeZone && clientY <= visibleBottom + 20) {
                                const dist = Math.max(0, visibleBottom - clientY);
                                const ratio = 1 - (dist / edgeZone);
                                targetSpeed = Math.round(2 + 12 * (ratio * ratio));
                            }
                        } else {
                            const appContainer = viewport.closest('.app-content');
                            if (appContainer && appContainer.scrollHeight > appContainer.clientHeight + 1) {
                                targetContainer = appContainer;
                                isWindow = false;
                            } else {
                                targetContainer = window;
                                isWindow = true;
                            }

                            const edgeZone = 60;
                            if (clientY < edgeZone && clientY >= 0) {
                                const ratio = 1 - (clientY / edgeZone);
                                targetSpeed = -Math.round(2 + 12 * (ratio * ratio));
                            } else if (window.innerHeight - clientY < edgeZone && clientY <= window.innerHeight) {
                                const dist = Math.max(0, window.innerHeight - clientY);
                                const ratio = 1 - (dist / edgeZone);
                                targetSpeed = Math.round(2 + 12 * (ratio * ratio));
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
                                color: ['#0b6175'],
                                grid: { top: 15, right: 15, bottom: 25, left: 20 },
                                tooltip: { show: false },
                                xAxis: { type: 'category', data: ['P1', 'P2', 'P3', 'P4', 'P5', 'P6'], axisLabel: { fontSize: 10, color: '#475569' } },
                                yAxis: { type: 'value', min: 0, max: 100, axisLabel: { show: false }, splitLine: { lineStyle: { color: '#e2e8f0' } } },
                                series: [{ data: [35, 50, 45, 65, 60, 80], type: 'line', smooth: true, areaStyle: { opacity: 0.10 } }]
                            };
                        }

                        const chartType = widget.config?.chart_type || 'bar';
                        const isNko = (widget.config?.metric || 'nko_score') === 'nko_score';
                        const units = ['Unit A', 'Unit B', 'Unit C', 'Unit D'];
                        const values = isNko ? [75, 60, 50, 40] : [70, 55, 65, 45];

                        if (chartType === 'donut') {
                            return {
                                color: ['#0b6175', '#16a3b8', '#10b981', '#64748b'],
                                tooltip: { trigger: 'item', formatter: '{b}' },
                                series: [{
                                    name: widget.config?.title || 'Contoh',
                                    type: 'pie',
                                    radius: ['45%', '70%'],
                                    avoidLabelOverlap: false,
                                    label: { show: false },
                                    data: [
                                        { value: 40, name: 'Unit A' },
                                        { value: 25, name: 'Unit B' },
                                        { value: 20, name: 'Unit C' },
                                        { value: 15, name: 'Lainnya' }
                                    ]
                                }]
                            };
                        }

                        return {
                            color: ['#0b6175'],
                            grid: { top: 15, right: 15, bottom: 25, left: 20 },
                            tooltip: { show: false },
                            xAxis: { type: 'category', data: units, axisLabel: { fontSize: 10, color: '#475569' } },
                            yAxis: { type: 'value', min: 0, max: 100, axisLabel: { show: false }, splitLine: { lineStyle: { color: '#e2e8f0' } } },
                            series: chartType === 'line'
                                ? [{ data: values, type: 'line', smooth: true, areaStyle: { opacity: 0.12 } }]
                                : [{ data: values, type: 'bar', barWidth: '45%', itemStyle: { borderRadius: [4, 4, 0, 0] } }]
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

                    setWidgetCols(widgetOrIndex, cols) {
                        const target = typeof widgetOrIndex === 'number'
                            ? this.activeWidgets[widgetOrIndex]
                            : widgetOrIndex;
                        if (!target) {
                            return;
                        }
                        const parsed = parseInt(cols, 10);
                        if (![1, 2, 3].includes(parsed)) {
                            return;
                        }
                        target.cols = parsed;
                        this.$nextTick(() => {
                            this.resizePreviewCharts();
                            window.dispatchEvent(new Event('resize'));
                        });
                    },

                    previewSpanClass(cols) {
                        const c = parseInt(cols, 10);
                        if (c === 1) return 'lg:col-span-1';
                        if (c === 2) return 'lg:col-span-2';
                        return 'lg:col-span-3';
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
