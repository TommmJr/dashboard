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

        <div class="flex flex-col gap-4 border-b border-slate-200 pb-4 sm:flex-row sm:items-center sm:justify-between">
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
                <p class="mt-1 text-sm text-slate-500">
                    Kelola susunan, lebar kolom, urutan, dan visibilitas widget dashboard per role atau divisi.
                </p>
            </div>

            <div class="flex flex-col gap-1.5 sm:min-w-[280px]">
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
            class="mt-4 rounded border border-sky-200 bg-sky-50 px-4 py-3 text-sm font-medium text-sky-800"
            role="status"
        >
            <span x-text="savingAction === 'reset' ? 'Mereset layout ke konfigurasi bawaan...' : 'Menyimpan perubahan layout...'"></span>
        </div>

        <div
            x-show="!saving && statusMessage !== ''"
            x-cloak
            class="mt-4 rounded border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800"
            role="status"
            x-text="statusMessage"
        ></div>

        <div
            x-show="!saving && (errorMessage !== '' || validationErrors.length > 0)"
            x-cloak
            class="mt-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
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
        <div class="mt-6 rounded-lg border border-slate-200 bg-slate-50/70 p-4">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between border-b border-slate-200 pb-3">
                <div>
                    <div class="flex items-center gap-2">
                        <h4 class="text-sm font-bold uppercase tracking-wide text-primary">Palet Komponen (Katalog Widget Tersedia)</h4>
                        <span class="inline-flex items-center rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary">
                            {{ count($catalog) }} Komponen Tersedia
                        </span>
                    </div>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Tarik (drag) kartu komponen dari palet ini ke dalam Kanvas Presentasi di bawah, atau klik tombol Tambah.
                    </p>
                </div>
            </div>

            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5" aria-label="Palet komponen">
                <template x-for="item in catalog" :key="item.key">
                    <div
                        draggable="true"
                        @dragstart="startPaletteDrag(item.key)"
                        @dragend="endPaletteDrag()"
                        class="group relative flex flex-col justify-between rounded-lg border border-slate-200 bg-white p-3 shadow-xs transition hover:border-accent hover:shadow-md cursor-grab active:cursor-grabbing"
                        :class="(item.key !== 'custom-chart' && hasWidget(item.key)) ? 'opacity-60 bg-slate-50' : ''"
                    >
                        <div>
                            <div class="flex items-center justify-between gap-1 mb-1.5">
                                <span
                                    class="inline-flex h-6 w-6 items-center justify-center rounded bg-primary/10 text-primary text-xs font-bold"
                                    x-text="item.key.substring(0, 2).toUpperCase()"
                                ></span>
                                <span
                                    class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-600"
                                    x-text="`${item.default_cols || 3} Kolom`"
                                ></span>
                            </div>
                            <p class="text-xs font-bold text-primary line-clamp-2" x-text="item.label"></p>
                            <p class="mt-0.5 text-[11px] text-slate-400 font-mono truncate" x-text="item.key"></p>
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

        {{-- Kanvas Presentasi Dashboard WYSIWYG 3-Kolom --}}
        <div class="mt-6 rounded-lg border border-slate-200 bg-slate-50/50 p-4">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between border-b border-slate-200 pb-3">
                <div>
                    <div class="flex items-center gap-2">
                        <h4 class="text-sm font-bold uppercase tracking-wide text-primary">Kanvas Presentasi Dashboard (Widget Aktif)</h4>
                        <span class="inline-flex items-center rounded-full bg-accent/15 px-2.5 py-0.5 text-xs font-semibold text-primary">
                            Pratinjau Tata Letak Grid 3 Kolom
                        </span>
                    </div>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Kanvas visual interaktif merefleksikan tampilan dashboard nyata. Atur posisi dengan drag-and-drop, sesuaikan lebar, dan konfigurasikan grafik.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <span
                        class="inline-flex items-center rounded bg-primary/10 px-2.5 py-1 text-xs font-semibold text-primary"
                        x-text="`${activeWidgets.length} Widget di Kanvas`"
                    ></span>
                </div>
            </div>

            {{-- Area Kanvas Drop Grid 3 Kolom --}}
            <div
                @dragover.prevent="isDragOverCanvas = true"
                @dragleave="isDragOverCanvas = false"
                @drop.prevent="isDragOverCanvas = false; onCanvasDrop(activeWidgets.length)"
                :class="isDragOverCanvas ? 'ring-2 ring-accent ring-dashed bg-accent/5' : ''"
                class="mt-4 min-h-[220px] rounded-lg transition"
            >
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <template x-for="(w, index) in activeWidgets" :key="index">
                        <div
                            draggable="true"
                            @dragstart="onCardDragStart(index)"
                            @dragover.prevent="onCardDragOver(index)"
                            @drop.prevent="onCardDrop(index)"
                            @dragend="onCardDragEnd()"
                            :class="[
                                colSpanClass(w.cols),
                                canvasDragIndex === index ? 'opacity-40 border-dashed border-accent' : 'border-slate-200'
                            ]"
                            class="flex flex-col rounded-lg border bg-white p-4 shadow-sm transition hover:shadow-md"
                        >
                            <div class="canvas-card-toolbar flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-3">
                                <div class="flex min-w-0 items-center gap-2.5">
                                    <span
                                        class="canvas-drag-handle inline-flex h-7 w-7 shrink-0 items-center justify-center rounded bg-slate-100 text-xs font-bold text-slate-600 cursor-grab active:cursor-grabbing hover:bg-slate-200 transition"
                                        title="Tarik untuk memindahkan urutan kartu di kanvas"
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
                                    <div class="flex items-center rounded-lg border border-slate-200 bg-slate-50 p-0.5 text-xs font-medium" title="Atur Lebar Kolom">
                                        <span class="text-[11px] font-semibold text-slate-500 px-1.5">Atur Lebar:</span>
                                        <button
                                            type="button"
                                            @click="setWidgetCols(w, 1)"
                                            :class="(w.cols === 1) ? 'bg-primary text-white shadow-xs' : 'text-slate-600 hover:text-primary'"
                                            class="rounded px-2 py-0.5 text-[11px] font-semibold transition"
                                        >
                                            1
                                        </button>
                                        <button
                                            type="button"
                                            @click="setWidgetCols(w, 2)"
                                            :class="(w.cols === 2) ? 'bg-primary text-white shadow-xs' : 'text-slate-600 hover:text-primary'"
                                            class="rounded px-2 py-0.5 text-[11px] font-semibold transition"
                                        >
                                            2
                                        </button>
                                        <button
                                            type="button"
                                            @click="setWidgetCols(w, 3)"
                                            :class="(w.cols === 3) ? 'bg-primary text-white shadow-xs' : 'text-slate-600 hover:text-primary'"
                                            class="rounded px-2 py-0.5 text-[11px] font-semibold transition"
                                        >
                                            3
                                        </button>
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
                                        :title="isLockedWidget(w.key) ? 'Widget wajib ada untuk peran ini' : 'Hapus widget dari kanvas'"
                                    >
                                        Hapus
                                    </button>
                                </div>
                            </div>

                            {{-- Live In-Canvas WYSIWYG Previews --}}
                            <template x-if="w.key === 'custom-chart'">
                                <div
                                    class="canvas-chart-container relative mt-3 h-44 w-full rounded border border-slate-100 bg-slate-50/60 p-2"
                                    x-init="$nextTick(() => initCanvasChart($el, w))"
                                >
                                    <div class="h-full w-full echarts-instance"></div>
                                </div>
                            </template>

                            <template x-if="w.key === 'trend-chart'">
                                <div
                                    class="canvas-chart-container relative mt-3 h-40 w-full rounded border border-slate-100 bg-slate-50/60 p-2"
                                    x-init="$nextTick(() => initTrendPreviewChart($el, w))"
                                >
                                    <div class="h-full w-full echarts-instance"></div>
                                </div>
                            </template>

                            <template x-if="w.key === 'kpi-summary'">
                                <div class="mt-3 grid grid-cols-2 sm:grid-cols-3 gap-2">
                                    <div class="rounded bg-sky-50/80 p-2.5 border border-sky-100">
                                        <p class="text-[10px] font-bold text-sky-700 uppercase tracking-wide">Rata-rata NKO</p>
                                        <p class="mt-1 text-base font-bold text-sky-950">105.4%</p>
                                    </div>
                                    <div class="rounded bg-emerald-50/80 p-2.5 border border-emerald-100">
                                        <p class="text-[10px] font-bold text-emerald-700 uppercase tracking-wide">Indikator</p>
                                        <p class="mt-1 text-base font-bold text-emerald-950">16 Tercapai</p>
                                    </div>
                                    <div class="col-span-2 sm:col-span-1 rounded bg-amber-50/80 p-2.5 border border-amber-100">
                                        <p class="text-[10px] font-bold text-amber-700 uppercase tracking-wide">Periode Aktif</p>
                                        <p class="mt-1 text-xs font-bold text-amber-950">Agustus 2026</p>
                                    </div>
                                </div>
                            </template>

                            <template x-if="w.key === 'nko-ranking'">
                                <div class="mt-3 rounded border border-slate-100 bg-slate-50/60 p-2.5 space-y-1.5 text-xs">
                                    <div class="flex items-center justify-between font-medium">
                                        <span class="text-slate-700">1. TJBT (Jawa Bagian Tengah)</span>
                                        <span class="font-bold text-emerald-600">105.5</span>
                                    </div>
                                    <div class="flex items-center justify-between font-medium">
                                        <span class="text-slate-700">2. TRANS (Transmisi)</span>
                                        <span class="font-bold text-emerald-600">100.7</span>
                                    </div>
                                    <div class="flex items-center justify-between text-slate-400 text-[11px]">
                                        <span>+13 Unit Lainnya</span>
                                        <span class="font-semibold text-slate-500">100.0</span>
                                    </div>
                                </div>
                            </template>

                            <template x-if="!['custom-chart', 'trend-chart', 'kpi-summary', 'nko-ranking'].includes(w.key)">
                                <div class="mt-3 rounded border border-dashed border-slate-200 bg-slate-50/40 p-3 text-center">
                                    <p class="text-xs font-semibold text-slate-600" x-text="`Pratinjau Visual: ${widgetLabel(w.key)}`"></p>
                                    <p class="mt-0.5 text-[10px] text-slate-400">Komponen akan dirender penuh pada tata letak dashboard utama.</p>
                                </div>
                            </template>

                            {{-- Pengaturan Lebar Kolom & Konfigurasi --}}
                            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
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
                                <div class="mt-3 rounded-lg bg-slate-50 p-3 border border-slate-200 space-y-2.5 text-xs">
                                    <div>
                                        <label class="block font-semibold text-slate-600 mb-1">Judul Grafik</label>
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
                                            <label class="block font-semibold text-slate-600 mb-1">Tipe Grafik</label>
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
                                            <label class="block font-semibold text-slate-600 mb-1">Metrik</label>
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
                        </div>
                    </template>
                </div>

                {{-- Placeholder Saat Kanvas Kosong --}}
                <div
                    x-show="activeWidgets.length === 0"
                    x-cloak
                    @dragover.prevent
                    @drop.prevent="onCanvasDrop(0)"
                    class="flex flex-col items-center justify-center rounded-lg border-2 border-dashed border-slate-300 bg-white p-8 text-center"
                >
                    <svg class="h-10 w-10 text-slate-400 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                    </svg>
                    <p class="text-sm font-bold text-slate-600">Kanvas Presentasi Masih Kosong</p>
                    <p class="mt-1 text-xs text-slate-400">Tarik komponen dari Palet Komponen di atas dan lepas di sini untuk memulai.</p>
                </div>
            </div>
        </div>

        {{-- Footer aksi simpan & reset --}}
        <div class="mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-4">
            <p class="text-xs text-slate-500">
                Target aktif:
                <span class="font-semibold text-primary" x-text="currentTarget()?.layout_key ?? '-'"></span>
            </p>

            <div class="flex flex-wrap items-center gap-3">
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
                    currentUserLayoutKey: initial.current_user_layout_key || '',
                    toast: { show: false, message: '', type: 'success', timer: null },

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
                        if (typeof item === 'string') {
                            const cat = this.catalog.find((c) => c.key === item);
                            return {
                                key: item,
                                cols: cat?.default_cols || 3,
                                config: item === 'custom-chart' ? { title: 'Grafik Kustom', chart_type: 'bar', metric: 'nko_score' } : {},
                                _openConfig: false,
                            };
                        }
                        const cat = this.catalog.find((c) => c.key === item.key);
                        return {
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
                        this.clearMessages();
                        this.activeWidgets.splice(index, 1);
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

                    initCanvasChart(containerEl, widget) {
                        if (typeof window.echarts === 'undefined' || !containerEl) {
                            return;
                        }
                        const chartDom = containerEl.querySelector('.echarts-instance') || containerEl;
                        let chartInstance = window.echarts.getInstanceByDom(chartDom);
                        if (!chartInstance) {
                            chartInstance = window.echarts.init(chartDom);
                        }
                        const chartType = widget?.config?.chart_type || 'bar';
                        const isNko = (widget?.config?.metric || 'nko_score') === 'nko_score';
                        const units = ['TJBT', 'Smg', 'Skr', 'Pwt', 'Ygy'];
                        const values = isNko ? [105.5, 102.3, 101.8, 100.4, 99.8] : [100, 95.2, 98.4, 92.1, 91.5];

                        let option = {};
                        if (chartType === 'donut') {
                            option = {
                                color: ['#005b96', '#00a896', '#f39c12', '#2ecc71'],
                                tooltip: { trigger: 'item' },
                                series: [{
                                    name: widget?.config?.title || 'Metrik',
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
                        } else if (chartType === 'line') {
                            option = {
                                color: ['#005b96'],
                                grid: { top: 15, right: 15, bottom: 25, left: 35 },
                                xAxis: { type: 'category', data: units, axisLabel: { fontSize: 10 } },
                                yAxis: { type: 'value', axisLabel: { fontSize: 10 } },
                                series: [{ data: values, type: 'line', smooth: true, areaStyle: { opacity: 0.15 } }]
                            };
                        } else {
                            option = {
                                color: ['#005b96'],
                                grid: { top: 15, right: 15, bottom: 25, left: 35 },
                                xAxis: { type: 'category', data: units, axisLabel: { fontSize: 10 } },
                                yAxis: { type: 'value', axisLabel: { fontSize: 10 } },
                                series: [{ data: values, type: 'bar', barWidth: '45%', itemStyle: { borderRadius: [3, 3, 0, 0] } }]
                            };
                        }
                        chartInstance.setOption(option);
                        setTimeout(() => chartInstance.resize(), 50);
                    },

                    initTrendPreviewChart(containerEl, widget) {
                        if (typeof window.echarts === 'undefined' || !containerEl) {
                            return;
                        }
                        const chartDom = containerEl.querySelector('.echarts-instance') || containerEl;
                        let chartInstance = window.echarts.getInstanceByDom(chartDom);
                        if (!chartInstance) {
                            chartInstance = window.echarts.init(chartDom);
                        }
                        const option = {
                            color: ['#00a896'],
                            grid: { top: 15, right: 15, bottom: 25, left: 35 },
                            xAxis: { type: 'category', data: ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu'], axisLabel: { fontSize: 10 } },
                            yAxis: { type: 'value', min: 95, axisLabel: { fontSize: 10 } },
                            series: [{ data: [98.2, 99.1, 100.4, 101.2, 102.5, 103.8, 104.9, 105.5], type: 'line', smooth: true }]
                        };
                        chartInstance.setOption(option);
                        setTimeout(() => chartInstance.resize(), 50);
                    },

                    setWidgetCols(widget, cols) {
                        widget.cols = cols;
                        this.$nextTick(() => {
                            window.dispatchEvent(new Event('resize'));
                        });
                    },

                    colSpanClass(cols) {
                        const c = parseInt(cols, 10);
                        if (c === 1) return 'col-span-1';
                        if (c === 2) return 'col-span-2';
                        return 'col-span-3';
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

                            const restoredWidgets = Array.isArray(payload.widgets)
                                ? payload.widgets
                                : [...(target.fallback_widgets || [])];
                            target.active_widgets = [...restoredWidgets];
                            target.is_customized = false;
                            this.activeWidgets = restoredWidgets.map((w) => this.normalizeItem(w));
                            this.statusMessage = payload.message || 'Layout dashboard dikembalikan ke bawaan.';
                            this.showToast(this.statusMessage, 'success');

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
