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

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            {{-- Kolom 1: Daftar Widget Aktif pada Layout --}}
            <div class="flex flex-col rounded-lg border border-slate-200 bg-slate-50/50 p-4">
                <div class="flex items-center justify-between border-b border-slate-200 pb-3">
                    <div>
                        <h4 class="text-sm font-bold uppercase tracking-wide text-primary">Widget Aktif</h4>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Atur urutan (Naik/Turun/Geser) dan lebar grid untuk setiap widget.
                        </p>
                    </div>
                    <span
                        class="inline-flex items-center rounded bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary"
                        x-text="`${activeWidgets.length} widget`"
                    ></span>
                </div>

                <ul class="mt-3 space-y-2.5" aria-label="Daftar widget aktif">
                    <template x-for="(w, index) in activeWidgets" :key="index">
                        <li
                            draggable="true"
                            @dragstart="onDragStart(index)"
                            @dragover.prevent="onDragOver(index)"
                            @drop.prevent="onDrop(index)"
                            @dragend="onDragEnd()"
                            :class="dragIndex === index ? 'opacity-50 border-accent' : 'border-slate-200'"
                            class="flex flex-col rounded border bg-white p-3 shadow-xs transition"
                        >
                            <div class="flex items-center justify-between gap-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span
                                        class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded bg-slate-100 text-xs font-bold text-slate-600 cursor-move"
                                        x-text="index + 1"
                                        title="Tarik untuk memindahkan urutan"
                                    ></span>
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-primary" x-text="widgetLabel(w.key)"></p>
                                        <p class="truncate text-xs text-slate-500" x-text="w.key"></p>
                                    </div>
                                </div>

                                <div class="flex shrink-0 items-center gap-1.5">
                                    <button
                                        type="button"
                                        @click="moveUp(index)"
                                        :disabled="saving || index === 0"
                                        class="inline-flex h-8 items-center rounded border border-slate-200 bg-white px-2.5 text-xs font-semibold text-primary transition hover:border-accent hover:text-accent disabled:cursor-not-allowed disabled:opacity-40"
                                        :aria-label="`Naikkan ${widgetLabel(w.key)}`"
                                    >
                                        Naik
                                    </button>
                                    <button
                                        type="button"
                                        @click="moveDown(index)"
                                        :disabled="saving || index === activeWidgets.length - 1"
                                        class="inline-flex h-8 items-center rounded border border-slate-200 bg-white px-2.5 text-xs font-semibold text-primary transition hover:border-accent hover:text-accent disabled:cursor-not-allowed disabled:opacity-40"
                                        :aria-label="`Turunkan ${widgetLabel(w.key)}`"
                                    >
                                        Turun
                                    </button>
                                    <button
                                        type="button"
                                        @click="removeWidget(index)"
                                        :disabled="saving || activeWidgets.length <= 1 || isLockedWidget(w.key)"
                                        class="inline-flex h-8 items-center rounded border border-red-200 bg-red-50 px-2.5 text-xs font-semibold text-red-700 transition hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-40"
                                        :aria-label="`Hapus ${widgetLabel(w.key)}`"
                                    >
                                        Hapus
                                    </button>
                                </div>
                            </div>

                            {{-- Pengaturan Lebar Kolom & Konfigurasi --}}
                            <div class="mt-2.5 pt-2 border-t border-slate-100 flex flex-wrap items-center justify-between gap-2 text-xs">
                                <div class="flex items-center gap-2">
                                    <label class="font-medium text-slate-500">Lebar Kolom:</label>
                                    <select
                                        x-model.number="w.cols"
                                        class="rounded border border-slate-200 bg-white px-2 py-1 text-xs text-primary font-medium outline-none focus:border-accent"
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
                                        class="inline-flex items-center gap-1 text-accent hover:underline font-medium"
                                    >
                                        <span x-text="w._openConfig ? 'Tutup Konfigurasi' : 'Atur Grafik'"></span>
                                    </button>
                                </template>
                            </div>

                            {{-- Panel Konfigurasi untuk Custom Chart --}}
                            <template x-if="w.key === 'custom-chart' && w._openConfig">
                                <div class="mt-2.5 rounded bg-slate-50 p-3 border border-slate-200 space-y-2.5 text-xs">
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
                        </li>
                    </template>
                </ul>

                <p
                    x-show="activeWidgets.length === 0"
                    x-cloak
                    class="mt-3 rounded border border-dashed border-slate-300 bg-white p-4 text-center text-xs text-slate-500"
                >
                    Belum ada widget dipilih. Tambahkan minimal satu widget dari katalog di samping.
                </p>
            </div>

            {{-- Kolom 2: Katalog Widget Tersedia --}}
            <div class="flex flex-col rounded-lg border border-slate-200 bg-slate-50/50 p-4">
                <div class="flex items-center justify-between border-b border-slate-200 pb-3">
                    <div>
                        <h4 class="text-sm font-bold uppercase tracking-wide text-primary">Katalog Widget Tersedia</h4>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Pilih komponen dari katalog resmi untuk ditambahkan ke layout target.
                        </p>
                    </div>
                    <span class="inline-flex items-center rounded bg-slate-200/70 px-2 py-0.5 text-xs font-semibold text-slate-700">
                        {{ count($catalog) }} katalog
                    </span>
                </div>

                <ul class="mt-3 space-y-2" aria-label="Katalog widget tersedia">
                    <template x-for="item in catalog" :key="item.key">
                        <li class="flex items-center justify-between gap-3 rounded border border-slate-200 bg-white px-3 py-2.5 shadow-xs">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-primary" x-text="item.label"></p>
                                <div class="mt-0.5 flex flex-wrap items-center gap-2">
                                    <span class="text-xs text-slate-500" x-text="item.key"></span>
                                    <span class="text-xs text-slate-400" x-text="`Bawaan: ${item.default_cols || 3} Kolom`"></span>
                                    <template x-if="item.permission">
                                        <span
                                            class="inline-flex items-center rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-600"
                                            x-text="`Izin: ${item.permission}`"
                                        ></span>
                                    </template>
                                </div>
                            </div>

                            <button
                                type="button"
                                @click="addWidget(item.key)"
                                :disabled="saving || (item.key !== 'custom-chart' && hasWidget(item.key))"
                                class="inline-flex h-8 shrink-0 items-center rounded px-3 text-xs font-semibold transition disabled:cursor-not-allowed"
                                :class="(item.key !== 'custom-chart' && hasWidget(item.key))
                                    ? 'border border-slate-200 bg-slate-100 text-slate-400'
                                    : 'bg-primary text-white hover:bg-accent'"
                                x-text="(item.key !== 'custom-chart' && hasWidget(item.key)) ? 'Sudah Aktif' : 'Tambah'"
                            ></button>
                        </li>
                    </template>
                </ul>
            </div>
        </div>

        {{-- Pratinjau Tata Letak Real-Time (Grid 3 Kolom) --}}
        <div class="mt-6 rounded-lg border border-slate-200 bg-slate-50/50 p-4">
            <div class="flex items-center justify-between border-b border-slate-200 pb-3">
                <div>
                    <h4 class="text-sm font-bold uppercase tracking-wide text-primary">Pratinjau Tata Letak</h4>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Gambaran susunan grid 3 kolom dashboard sesuai lebar dan urutan aktif saat ini.
                    </p>
                </div>
                <span class="inline-flex items-center rounded bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary">
                    Pratinjau Grid 3 Kolom
                </span>
            </div>

            <div class="mt-4 grid grid-cols-3 gap-3">
                <template x-for="(w, idx) in activeWidgets" :key="idx">
                    <div
                        :class="colSpanClass(w.cols)"
                        class="rounded border border-primary/20 bg-white p-3 shadow-xs transition flex flex-col justify-between min-h-[4.5rem]"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <span class="font-semibold text-xs text-primary truncate" x-text="widgetLabel(w.key)"></span>
                            <span
                                class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-slate-100 text-slate-600 shrink-0"
                                x-text="`${w.cols || 3} / 3 Kolom`"
                            ></span>
                        </div>
                        <div class="mt-2 flex items-center justify-between text-[11px] text-slate-400">
                            <span x-text="`Urutan #${idx + 1}`"></span>
                            <template x-if="w.key === 'custom-chart'">
                                <span class="text-accent font-medium truncate" x-text="w.config?.title || 'Grafik Kustom'"></span>
                            </template>
                        </div>
                    </div>
                </template>
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
                    currentUserLayoutKey: initial.current_user_layout_key || '',

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

                    onDragStart(index) {
                        if (this.saving) {
                            return;
                        }
                        this.dragIndex = index;
                    },

                    onDragOver(index) {
                        if (this.saving || this.dragIndex === null || this.dragIndex === index) {
                            return;
                        }
                    },

                    onDrop(index) {
                        if (this.saving || this.dragIndex === null || this.dragIndex === index) {
                            this.dragIndex = null;
                            return;
                        }
                        this.clearMessages();
                        const copy = [...this.activeWidgets];
                        const [moved] = copy.splice(this.dragIndex, 1);
                        copy.splice(index, 0, moved);
                        this.activeWidgets = copy;
                        this.dragIndex = null;
                    },

                    onDragEnd() {
                        this.dragIndex = null;
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
                                return;
                            }

                            if (!response.ok) {
                                this.errorMessage = payload.message || `Gagal menyimpan layout (HTTP ${response.status}).`;
                                return;
                            }

                            const savedWidgets = Array.isArray(payload.widgets) ? payload.widgets : this.serializeWidgets();
                            target.active_widgets = [...savedWidgets];
                            target.is_customized = true;
                            this.activeWidgets = savedWidgets.map((w) => this.normalizeItem(w));
                            this.statusMessage = payload.message || 'Layout dashboard berhasil disimpan.';

                            if (target.layout_key === this.currentUserLayoutKey) {
                                window.location.reload();
                            }
                        } catch (e) {
                            this.errorMessage = 'Terjadi gangguan jaringan saat menyimpan layout.';
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
                                return;
                            }

                            if (!response.ok) {
                                this.errorMessage = payload.message || `Gagal mereset layout (HTTP ${response.status}).`;
                                return;
                            }

                            const restoredWidgets = Array.isArray(payload.widgets)
                                ? payload.widgets
                                : [...(target.fallback_widgets || [])];
                            target.active_widgets = [...restoredWidgets];
                            target.is_customized = false;
                            this.activeWidgets = restoredWidgets.map((w) => this.normalizeItem(w));
                            this.statusMessage = payload.message || 'Layout dashboard dikembalikan ke bawaan.';

                            if (target.layout_key === this.currentUserLayoutKey) {
                                window.location.reload();
                            }
                        } catch (e) {
                            this.errorMessage = 'Terjadi gangguan jaringan saat mereset layout.';
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
