@extends('layouts.app')

@section('title', 'Dataset: '.$dataset->original_name)

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8" x-data="{
    confirmModalOpen: false,
    confirmTitle: '',
    confirmMessage: '',
    confirmActionUrl: '',
    confirmMethodSpoof: '',
    confirmButtonText: 'Konfirmasi',
    confirmButtonClass: 'bg-rose-600 hover:bg-rose-700 text-white',
    openConfirm(title, message, url, methodSpoof, btnText, btnClass) {
        this.confirmTitle = title;
        this.confirmMessage = message;
        this.confirmActionUrl = url;
        this.confirmMethodSpoof = methodSpoof || '';
        this.confirmButtonText = btnText || 'Konfirmasi';
        this.confirmButtonClass = btnClass || 'bg-rose-600 hover:bg-rose-700 text-white';
        this.confirmModalOpen = true;
    }
}">
    {{-- Alpine Confirmation Modal --}}
    <div id="dataset-confirm-modal"
         x-show="confirmModalOpen"
         x-cloak
         class="fixed inset-0 z-50 overflow-y-auto"
         role="dialog"
         aria-modal="true">
        <div class="flex min-h-screen items-center justify-center p-4 text-center">
            <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-xs transition-opacity"
                 x-show="confirmModalOpen"
                 x-transition:enter="ease-out duration-200"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="ease-in duration-150"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 @click="confirmModalOpen = false"></div>

            <div class="relative w-full max-w-md transform overflow-hidden rounded-xl border border-slate-200/80 bg-white p-6 text-left shadow-xl transition-all"
                 x-show="confirmModalOpen"
                 x-transition:enter="ease-out duration-200"
                 x-transition:enter-start="opacity-0 scale-95"
                 x-transition:enter-end="opacity-100 scale-100"
                 x-transition:leave="ease-in duration-150"
                 x-transition:leave-start="opacity-100 scale-100"
                 x-transition:leave-end="opacity-0 scale-95">
                <h3 class="text-base font-bold text-slate-900" x-text="confirmTitle">Konfirmasi Tindakan</h3>
                <p class="mt-2 text-xs leading-relaxed text-slate-600" x-text="confirmMessage"></p>

                <form method="POST" :action="confirmActionUrl" class="mt-5 flex justify-end gap-2">
                    @csrf
                    <template x-if="confirmMethodSpoof">
                        <input type="hidden" name="_method" :value="confirmMethodSpoof">
                    </template>
                    <button type="button"
                            @click="confirmModalOpen = false"
                            class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-2xs hover:bg-slate-50">
                        Batal
                    </button>
                    <button type="submit"
                            :class="confirmButtonClass"
                            class="inline-flex items-center rounded-lg px-3 py-1.5 text-xs font-semibold shadow-2xs transition-all"
                            x-text="confirmButtonText">
                        Konfirmasi
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- Back Link --}}
    <div>
        <a href="{{ route('datasets.index') }}"
           class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200/90 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-2xs transition-all duration-150 hover:border-slate-300 hover:bg-slate-50">
            <svg class="h-3.5 w-3.5 text-slate-500 transition-transform group-hover:-translate-x-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M19 12H5M12 19l-7-7 7-7"/>
            </svg>
            Kembali ke daftar
        </a>
    </div>

    {{-- Card Ringkasan Dataset --}}
    <div class="ibs-card animate-entrance stagger-1 mt-4 p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0 flex-1">
                <h1 class="text-2xl font-bold tracking-tight text-slate-900 break-words">{{ $dataset->original_name }}</h1>
                <dl class="mt-4 grid grid-cols-2 gap-x-8 gap-y-3 text-xs sm:grid-cols-3">
                    <div>
                        <dt class="font-semibold text-slate-500 uppercase tracking-wider text-2xs">Divisi</dt>
                        <dd class="mt-0.5 text-slate-800 font-medium">
                            @if ($dataset->division?->name)
                                {{ $dataset->division->name }}
                            @else
                                <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500">Tidak tercatat</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-slate-500 uppercase tracking-wider text-2xs">Pengunggah</dt>
                        <dd class="mt-0.5 text-slate-800 font-medium">
                            @if ($dataset->user?->name)
                                {{ $dataset->user->name }}
                            @else
                                <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500">Tidak tercatat</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-slate-500 uppercase tracking-wider text-2xs">Ukuran</dt>
                        <dd class="mt-0.5 font-sans tabular-nums text-slate-800">{{ number_format($dataset->size / 1024, 1) }} KB</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-slate-500 uppercase tracking-wider text-2xs">Tipe File</dt>
                        <dd class="mt-0.5 font-sans text-slate-800 break-all">{{ $dataset->mime_type }}</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-slate-500 uppercase tracking-wider text-2xs">Diunggah</dt>
                        <dd class="mt-0.5 font-sans tabular-nums text-slate-800">{{ $dataset->created_at->format('d M Y H:i') }}</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-slate-500 uppercase tracking-wider text-2xs">Status</dt>
                        <dd class="mt-0.5"><x-dataset-status-badge :status="$dataset->status->value" /></dd>
                    </div>
                </dl>

                @if ($dataset->status === \App\Enums\DatasetStatus::FAILED && $dataset->error_message)
                    <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/50 p-3 text-xs text-rose-700">
                        {{ $dataset->error_message }}
                    </div>
                @endif
            </div>

            <div class="inline-flex flex-wrap items-center gap-2 shrink-0">
                @can('dataset.update')
                    @if ($dataset->status !== \App\Enums\DatasetStatus::ARCHIVED)
                        <form method="POST" action="{{ route('datasets.archive', $dataset) }}"
                              data-confirm="Arsipkan dataset ini? Data keluar dari chart tetapi tetap tersimpan.">
                            @csrf @method('PATCH')
                            <button type="submit"
                                    @click.prevent="openConfirm('Arsipkan Dataset', 'Arsipkan dataset ini? Data keluar dari chart tetapi tetap tersimpan.', '{{ route('datasets.archive', $dataset) }}', 'PATCH', 'Arsipkan', 'bg-amber-600 hover:bg-amber-700 text-white')"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200/90 bg-white px-3 py-1.5 text-xs font-medium text-amber-700 shadow-2xs transition-all duration-150 hover:border-amber-200 hover:bg-amber-50/60">
                                <svg class="h-3.5 w-3.5 text-amber-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="21 8 21 21 3 21 3 8"/>
                                    <rect x="1" y="3" width="22" height="5"/>
                                    <line x1="10" y1="12" x2="14" y2="12"/>
                                </svg>
                                Arsipkan
                            </button>
                        </form>
                    @endif
                @endcan

                @can('dataset.delete')
                    <form method="POST" action="{{ route('datasets.destroy', $dataset) }}"
                          data-confirm="HAPUS PERMANEN dataset ini beserta seluruh baris datanya?">
                        @csrf @method('DELETE')
                        <button type="submit"
                                @click.prevent="openConfirm('Hapus Permanen Dataset', 'HAPUS PERMANEN dataset ini beserta seluruh baris datanya?', '{{ route('datasets.destroy', $dataset) }}', 'DELETE', 'Hapus Permanen', 'bg-rose-600 hover:bg-rose-700 text-white')"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200/90 bg-white px-3 py-1.5 text-xs font-medium text-rose-600 shadow-2xs transition-all duration-150 hover:border-rose-200 hover:bg-rose-50/60">
                            <svg class="h-3.5 w-3.5 text-rose-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 6h18"/>
                                <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/>
                                <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>
                                <line x1="10" y1="11" x2="10" y2="17"/>
                                <line x1="14" y1="11" x2="14" y2="17"/>
                            </svg>
                            Hapus Permanen
                        </button>
                    </form>
                @endcan
            </div>
        </div>

        @php
            $validationReport = $dataset->validation_report ?? [];
            $conflictObservationsQuery = $dataset->observations()->where('period_conflict', true);
            $periodConflictCount = is_int($validationReport['period_conflict'] ?? null)
                ? $validationReport['period_conflict']
                : (int) ($validationReport['period_conflict_count'] ?? 0);

            if ($periodConflictCount === 0) {
                $periodConflictCount = $conflictObservationsQuery->count();
            }

            if ($periodConflictCount === 0 && ! empty($validationReport['period_conflict'])) {
                $periodConflictCount = 1;
            }

            $conflictingSheetPeriods = $validationReport['conflicting_sheet_periods']
                ?? ($periodConflictCount > 0
                    ? (clone $conflictObservationsQuery)
                        ->whereNotNull('sheet_period')
                        ->distinct()
                        ->orderBy('sheet_period')
                        ->pluck('sheet_period')
                        ->all()
                    : []);

            $okCount = (int) ($validationReport['ok'] ?? 0);
            $warningCount = (int) ($validationReport['warning'] ?? 0);
            $fatalCount = (int) ($validationReport['fatal'] ?? 0);
            $issuesList = is_array($validationReport['issues'] ?? null) ? $validationReport['issues'] : [];
        @endphp

        @if ($dataset->validation_report || $periodConflictCount > 0)
            <div id="validation-summary-panel"
                 class="mt-6 border-t border-slate-100 pt-5"
                 x-data="{ showIssues: true }">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Ringkasan validasi</h2>

                    <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs tabular-nums">
                        <span class="inline-flex items-center gap-1.5 font-medium text-slate-700">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                            Sesuai: {{ $okCount }}
                        </span>
                        <span class="inline-flex items-center gap-1.5 font-medium {{ $warningCount > 0 ? 'text-amber-800' : 'text-slate-500' }}">
                            <span class="h-1.5 w-1.5 rounded-full {{ $warningCount > 0 ? 'bg-amber-500' : 'bg-slate-300' }}" aria-hidden="true"></span>
                            Peringatan: {{ $warningCount }}
                        </span>
                        <span class="inline-flex items-center gap-1.5 font-medium {{ $fatalCount > 0 ? 'text-rose-700' : 'text-slate-500' }}">
                            <span class="h-1.5 w-1.5 rounded-full {{ $fatalCount > 0 ? 'bg-rose-500' : 'bg-slate-300' }}" aria-hidden="true"></span>
                            Kesalahan: {{ $fatalCount }}
                        </span>
                        @if ($periodConflictCount > 0)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-0.5 font-medium text-amber-800 ring-1 ring-inset ring-amber-200/80">
                                <span class="h-1.5 w-1.5 rounded-full bg-amber-500" aria-hidden="true"></span>
                                Konflik Periode: {{ $periodConflictCount }}
                            </span>
                        @endif
                    </div>
                </div>

                @if ($periodConflictCount > 0)
                    <div class="mt-3.5 flex items-start gap-2.5 rounded-xl bg-amber-50/50 px-3.5 py-2.5 text-xs text-amber-900 ring-1 ring-inset ring-amber-200/70">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="8" x2="12" y2="12"/>
                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                        <div class="min-w-0">
                            <p class="font-semibold text-amber-950">Konflik periode banner sheet ({{ $periodConflictCount }} observasi)</p>
                            <p class="mt-0.5 leading-relaxed text-amber-800">
                                Periode banner sheet berbeda dari periode upload efektif. Data tetap disimpan menggunakan periode upload{{ $conflictingSheetPeriods !== [] ? ' (banner sheet: '.implode(', ', $conflictingSheetPeriods).')' : '' }}.
                            </p>
                        </div>
                    </div>
                @endif

                @if (! empty($issuesList))
                    <div class="mt-3.5 overflow-hidden rounded-xl border border-slate-200/80 bg-white">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 bg-slate-50/60 px-3.5 py-2 text-[11px] text-slate-600">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold text-slate-700 tabular-nums">Catatan Baris ({{ count($issuesList) }})</span>
                                @if (! empty($validationReport['issues_truncated']))
                                    <span class="text-slate-500">Hanya menampilkan 50 masalah pertama. Total: {{ $fatalCount + $warningCount }}.</span>
                                @endif
                            </div>
                            <button type="button"
                                    @click="showIssues = !showIssues"
                                    :aria-expanded="showIssues.toString()"
                                    class="cursor-pointer rounded-md px-2 py-0.5 text-[11px] font-medium text-slate-600 transition hover:bg-slate-200/60 hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                                <span x-text="showIssues ? 'Sembunyikan' : 'Tampilkan'">Sembunyikan</span>
                            </button>
                        </div>

                        <ul x-show="showIssues" class="max-h-44 divide-y divide-slate-100 overflow-y-auto text-xs text-slate-600">
                            @foreach ($issuesList as $issue)
                                <li class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 px-3.5 py-2 transition-colors hover:bg-slate-50/70">
                                    <span class="shrink-0 font-semibold {{ $issue['severity'] === 'FATAL' ? 'text-rose-700' : 'text-amber-700' }}">[{{ $issue['severity'] === 'FATAL' ? 'Kesalahan' : 'Peringatan' }}]</span>
                                    <span class="min-w-0 flex-1 text-slate-600">
                                        <span class="font-medium tabular-nums text-slate-800">Baris {{ $issue['row'] }}:</span> {{ $issue['message'] }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif
    </div>

    @php
        $formatAssetDate = static function ($rawDate): string {
            $value = trim((string) $rawDate);
            if ($value === '' || $value === '-') {
                return 'Tidak tercatat';
            }
            try {
                return \Illuminate\Support\Carbon::parse($value)->locale('id')->translatedFormat('d M Y');
            } catch (\Throwable) {
                return str_replace('-', ' ', $value);
            }
        };
    @endphp

    {{-- Inspeksi & Analisis Rekonsiliasi Data Sync Aset (UIT JBT vs 8 UPT) --}}
    @if (! empty($isAssetDataset) && ! empty($assetAnalysis))
        @php
            $assetSummary = $assetAnalysis['summary'];
            $reconciledTotal = max(1, (int) $assetSummary['reconciled_count']);
            $matchPercent = $assetSummary['reconciled_count'] > 0
                ? round(($assetSummary['matched_count'] / $reconciledTotal) * 100, 1)
                : 100.0;
            $mismatchPercent = max(0.0, round(100.0 - $matchPercent, 1));
            $reconciliationMeta = array_map(static fn (array $r): array => [
                'kategori' => $r['kategori'],
                'is_matched' => $r['is_matched'],
                'search' => mb_strtolower($r['label'].' '.$r['uit_label'].' '.$r['upt_label']),
            ], $assetAnalysis['reconciliation']);
            $matrixMeta = array_map(static fn (array $r): array => [
                'kategori' => $r['kategori'],
                'search' => mb_strtolower($r['atribut']),
            ], $assetAnalysis['upt_matrix']);
            $uitMeta = array_map(static fn (array $r): array => [
                'kategori' => $r['kategori'],
                'search' => mb_strtolower($r['kelompok'].' '.$r['satuan'].' '.$r['label']),
            ], $assetAnalysis['uit_rows']);
            $assetCategories = ['Semua', 'Tower', 'Transmisi', 'Trafo', 'Gardu Induk & Bay', 'Peralatan Lain'];
        @endphp
        <section id="asset-sync-reconciliation-inspector"
                 class="ibs-card animate-entrance stagger-2 mt-6 overflow-hidden"
                 x-data="{
                     activeTab: 'reconciliation',
                     category: 'Semua',
                     status: 'Semua',
                     search: '',
                     reconciliationMeta: @js($reconciliationMeta),
                     matrixMeta: @js($matrixMeta),
                     uitMeta: @js($uitMeta),
                     matchesCommon(kategori, searchText) {
                         if (this.category !== 'Semua' && kategori !== this.category) {
                             return false;
                         }
                         const q = this.search.trim().toLowerCase();
                         if (q !== '' && !searchText.includes(q)) {
                             return false;
                         }
                         return true;
                     },
                     matchesReconciliation(kategori, isMatched, searchText) {
                         if (!this.matchesCommon(kategori, searchText)) {
                             return false;
                         }
                         if (this.status === 'Sinkron' && !isMatched) {
                             return false;
                         }
                         if (this.status === 'Selisih' && isMatched) {
                             return false;
                         }
                         return true;
                     },
                     visibleReconciliationCount() {
                         return this.reconciliationMeta.filter(i => this.matchesReconciliation(i.kategori, i.is_matched, i.search)).length;
                     },
                     visibleMatrixCount() {
                         return this.matrixMeta.filter(i => this.matchesCommon(i.kategori, i.search)).length;
                     },
                     visibleUitCount() {
                         return this.uitMeta.filter(i => this.matchesCommon(i.kategori, i.search)).length;
                     },
                     resetFilters() {
                         this.category = 'Semua';
                         this.status = 'Semua';
                         this.search = '';
                     }
                 }">
            <div class="flex flex-col gap-2 border-b border-slate-100 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="ibs-section-title text-base">Rekonsiliasi Data Aset UIT vs 8 UPT</h2>
                    <span class="sr-only">Inspeksi &amp; Analisis Rekonsiliasi Data Sync Aset (UIT JBT vs 8 UPT)</span>
                </div>
                <span class="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs font-semibold {{ $assetSummary['mismatched_count'] === 0 ? 'border-emerald-200 bg-emerald-50/70 text-emerald-800' : 'border-rose-200 bg-rose-50/70 text-rose-800' }}">
                    <span class="h-1.5 w-1.5 rounded-full {{ $assetSummary['mismatched_count'] === 0 ? 'bg-emerald-500' : 'bg-rose-500' }}"></span>
                    {{ $assetSummary['mismatched_count'] === 0 ? 'Rekonsiliasi Sinkron' : $assetSummary['mismatched_count'].' Item Selisih' }}
                </span>
            </div>

            {{-- Unified Reconciliation Summary Strip --}}
            <div class="border-b border-slate-100 bg-slate-50/30">
                <div class="border-b border-slate-100 px-6 py-3">
                    <div class="flex flex-wrap items-center justify-between gap-2 text-[11px]">
                        <span class="font-medium text-slate-500">Proporsi Kesesuaian Induk UIT vs 8 UPT</span>
                        <div class="flex items-center gap-3 font-sans tabular-nums">
                            <span class="inline-flex items-center gap-1.5 font-semibold text-emerald-700">
                                <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                                Sinkron {{ number_format($matchPercent, 1, ',', '.') }}%
                            </span>
                            <span class="inline-flex items-center gap-1.5 font-semibold {{ $assetSummary['mismatched_count'] > 0 ? 'text-rose-700' : 'text-slate-500' }}">
                                <span class="h-2 w-2 rounded-full {{ $assetSummary['mismatched_count'] > 0 ? 'bg-rose-500' : 'bg-slate-300' }}"></span>
                                Selisih {{ number_format($mismatchPercent, 1, ',', '.') }}%
                            </span>
                        </div>
                    </div>
                    <div class="mt-2 flex h-1.5 w-full overflow-hidden rounded-full bg-slate-200/80">
                        <div class="h-full bg-emerald-500 transition-all duration-300" style="width: {{ $matchPercent }}%"></div>
                        @if ($mismatchPercent > 0)
                            <div class="h-full bg-rose-500 transition-all duration-300" style="width: {{ $mismatchPercent }}%"></div>
                        @endif
                    </div>
                </div>

                <div class="grid grid-cols-1 divide-y divide-slate-100 bg-white sm:grid-cols-2 lg:grid-cols-5 sm:divide-y-0 sm:divide-x">
                    <div class="px-5 py-4">
                        <div class="ibs-metric-label uppercase tracking-wider text-[10px]">Total Baris Sync UIT + UPT</div>
                        <div class="ibs-metric-value mt-1.5 text-lg">{{ number_format($assetSummary['total_sync_rows'], 0, ',', '.') }}</div>
                        <div class="mt-1 text-[11px] text-slate-500 tabular-nums">
                            {{ number_format($assetSummary['uit_row_count'], 0, ',', '.') }} UIT · {{ number_format($assetSummary['upt_row_count'], 0, ',', '.') }} UPT
                        </div>
                    </div>

                    <div class="px-5 py-4">
                        <div class="ibs-metric-label uppercase tracking-wider text-[10px]">Status Rekonsiliasi Sinkron</div>
                        <div class="ibs-metric-value mt-1.5 flex items-center gap-2 text-lg">
                            <span class="h-2 w-2 rounded-full {{ $assetSummary['mismatched_count'] === 0 ? 'bg-emerald-500' : 'bg-rose-500' }}"></span>
                            <span>{{ $assetSummary['matched_count'] }} / {{ $assetSummary['reconciled_count'] }}</span>
                        </div>
                        <div class="mt-1 text-[11px] text-slate-500 tabular-nums">
                            {{ $assetSummary['mismatched_count'] }} selisih terdeteksi
                        </div>
                    </div>

                    <div class="px-5 py-4">
                        <div class="ibs-metric-label uppercase tracking-wider text-[10px]">Total Tower UIT vs 8 UPT</div>
                        <div class="ibs-metric-value mt-1.5 text-sm">
                            {{ number_format($assetSummary['tower_uit'], 0, ',', '.') }} <span class="font-normal text-slate-400">vs</span> {{ number_format($assetSummary['tower_upt'], 0, ',', '.') }}
                        </div>
                        <div class="mt-1 inline-flex items-center gap-1.5 text-[11px] font-medium {{ $assetSummary['tower_matched'] ? 'text-emerald-700' : 'text-rose-700' }}">
                            <span class="h-1.5 w-1.5 rounded-full {{ $assetSummary['tower_matched'] ? 'bg-emerald-500' : 'bg-rose-500' }}"></span>
                            {{ $assetSummary['tower_matched'] ? 'Sinkron (unit)' : 'Selisih (unit)' }}
                        </div>
                    </div>

                    <div class="px-5 py-4">
                        <div class="ibs-metric-label uppercase tracking-wider text-[10px]">Total Transmisi kms UIT vs 8 UPT</div>
                        <div class="ibs-metric-value mt-1.5 text-sm">
                            {{ number_format($assetSummary['transmisi_uit'], 1, ',', '.') }} <span class="font-normal text-slate-400">vs</span> {{ number_format($assetSummary['transmisi_upt'], 1, ',', '.') }}
                        </div>
                        <div class="mt-1 inline-flex items-center gap-1.5 text-[11px] font-medium {{ $assetSummary['transmisi_matched'] ? 'text-emerald-700' : 'text-rose-700' }}">
                            <span class="h-1.5 w-1.5 rounded-full {{ $assetSummary['transmisi_matched'] ? 'bg-emerald-500' : 'bg-rose-500' }}"></span>
                            {{ $assetSummary['transmisi_matched'] ? 'Sinkron (kms)' : 'Selisih (kms)' }}
                        </div>
                    </div>

                    <div class="px-5 py-4">
                        <div class="ibs-metric-label uppercase tracking-wider text-[10px]">Total Trafo MVA UIT vs 8 UPT</div>
                        <div class="ibs-metric-value mt-1.5 text-sm">
                            {{ number_format($assetSummary['trafo_mva_uit'], 1, ',', '.') }} <span class="font-normal text-slate-400">vs</span> {{ number_format($assetSummary['trafo_mva_upt'], 1, ',', '.') }}
                        </div>
                        <div class="mt-1 inline-flex items-center gap-1.5 text-[11px] font-medium {{ $assetSummary['trafo_mva_matched'] ? 'text-emerald-700' : 'text-rose-700' }}">
                            <span class="h-1.5 w-1.5 rounded-full {{ $assetSummary['trafo_mva_matched'] ? 'bg-emerald-500' : 'bg-rose-500' }}"></span>
                            {{ $assetSummary['trafo_mva_matched'] ? 'Sinkron (MVA)' : 'Selisih (MVA)' }}
                        </div>
                    </div>
                </div>
            </div>

            {{-- Interactive Filter Bar --}}
            <div class="space-y-3 border-b border-slate-100 px-6 py-3.5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="inline-flex flex-wrap items-center gap-1 rounded-xl border border-slate-200/80 bg-slate-100/70 p-1" role="tablist">
                        <button type="button"
                                @click="activeTab = 'reconciliation'"
                                :class="activeTab === 'reconciliation' ? 'bg-white text-primary shadow-2xs ring-1 ring-slate-200/80' : 'text-slate-600 hover:text-slate-900'"
                                class="pill-tab-transition inline-flex items-center rounded-lg px-3 py-1.5 text-xs font-semibold">
                            Rekonsiliasi UIT vs 8 UPT
                        </button>
                        <button type="button"
                                @click="activeTab = 'matrix'"
                                :class="activeTab === 'matrix' ? 'bg-white text-primary shadow-2xs ring-1 ring-slate-200/80' : 'text-slate-600 hover:text-slate-900'"
                                class="pill-tab-transition inline-flex items-center rounded-lg px-3 py-1.5 text-xs font-semibold">
                            Matriks Distribusi 8 UPT
                        </button>
                        <button type="button"
                                @click="activeTab = 'uit'"
                                :class="activeTab === 'uit' ? 'bg-white text-primary shadow-2xs ring-1 ring-slate-200/80' : 'text-slate-600 hover:text-slate-900'"
                                class="pill-tab-transition inline-flex items-center rounded-lg px-3 py-1.5 text-xs font-semibold">
                            Rekap Induk UIT
                        </button>
                        <span class="sr-only">Matriks Distribusi 8 UPT (Tab 1b) Rekap Induk UIT (Tab 1a)</span>
                    </div>

                    <div class="w-full sm:w-64">
                        <label for="asset-inspector-search" class="sr-only">Cari atribut atau kelompok aset</label>
                        <input id="asset-inspector-search"
                               type="search"
                               x-model="search"
                               placeholder="Cari atribut aset..."
                               class="h-8.5 w-full rounded-lg border border-slate-200 bg-white px-3 text-xs text-slate-700 placeholder:text-slate-400 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-1.5">
                        @foreach ($assetCategories as $cat)
                            <button type="button"
                                    @click="category = @js($cat)"
                                    :class="category === @js($cat) ? 'border-primary bg-primary-soft text-primary font-semibold' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'"
                                    class="pill-tab-transition inline-flex items-center rounded-lg border px-2.5 py-1 text-[11px] font-medium">
                                {{ $cat }}
                            </button>
                        @endforeach
                    </div>

                    <div class="flex items-center gap-1.5" x-show="activeTab === 'reconciliation'">
                        <button type="button"
                                @click="status = 'Semua'"
                                :class="status === 'Semua' ? 'border-slate-800 bg-slate-800 text-white' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'"
                                class="pill-tab-transition inline-flex items-center rounded-lg border px-2.5 py-1 text-[11px] font-medium">
                            Semua
                        </button>
                        <button type="button"
                                @click="status = 'Sinkron'"
                                :class="status === 'Sinkron' ? 'border-emerald-600 bg-emerald-50 text-emerald-800' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'"
                                class="pill-tab-transition inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-[11px] font-medium">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            Sinkron
                        </button>
                        <button type="button"
                                @click="status = 'Selisih'"
                                :class="status === 'Selisih' ? 'border-rose-600 bg-rose-50 text-rose-800' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'"
                                class="pill-tab-transition inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-[11px] font-medium">
                            <span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span>
                            Selisih
                        </button>
                    </div>
                </div>
            </div>

            {{-- Tab 1: Rekonsiliasi UIT vs 8 UPT --}}
            <div x-show="activeTab === 'reconciliation'" class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-xs">
                    <thead class="bg-slate-50/80 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-6 py-2.5">Atribut / Ringkasan</th>
                            <th class="px-4 py-2.5">Kategori</th>
                            <th class="px-4 py-2.5 text-right">Induk UIT (Tab 1a)</th>
                            <th class="px-4 py-2.5 text-right">Akumulasi 8 UPT (Tab 1b)</th>
                            <th class="px-4 py-2.5 text-right">Delta</th>
                            <th class="px-6 py-2.5 text-right">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @foreach ($assetAnalysis['reconciliation'] as $row)
                            <tr x-show="matchesReconciliation(@js($row['kategori']), @js($row['is_matched']), @js(mb_strtolower($row['label'].' '.$row['uit_label'].' '.$row['upt_label'])))"
                                class="{{ $row['is_summary'] ? 'bg-slate-50/60 font-semibold' : 'hover:bg-slate-50/60' }} transition-colors">
                                <td class="px-6 py-2.5 text-slate-800">
                                    <div>{{ $row['label'] !== '' ? $row['label'] : 'Tidak tercatat' }}</div>
                                    @if (! $row['is_summary'] && $row['upt_label'] !== $row['uit_label'] && $row['upt_label'] !== '')
                                        <div class="text-[11px] text-slate-400">Sumber UPT: {{ $row['upt_label'] }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="inline-flex items-center rounded-md border border-slate-200 bg-slate-50 px-2 py-0.5 text-[11px] font-medium text-slate-600">
                                        {{ $row['kategori'] ?: 'Tidak tercatat' }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-right font-sans tabular-nums text-slate-900">{{ number_format((float) ($row['uit_value'] ?? 0), 2, ',', '.') }}</td>
                                <td class="px-4 py-2.5 text-right font-sans tabular-nums text-slate-900">{{ number_format((float) ($row['upt_sum'] ?? 0), 2, ',', '.') }}</td>
                                <td class="px-4 py-2.5 text-right font-sans tabular-nums {{ $row['is_matched'] ? 'text-slate-600' : 'font-semibold text-rose-700' }}">
                                    {{ number_format((float) ($row['delta'] ?? 0), 2, ',', '.') }}
                                </td>
                                <td class="px-6 py-2.5 text-right">
                                    <span class="inline-flex items-center gap-1.5 rounded-md border px-2 py-0.5 text-[11px] font-medium {{ $row['is_matched'] ? 'border-slate-200 bg-white text-slate-700' : 'border-rose-200 bg-rose-50 text-rose-700' }}">
                                        <span class="h-1.5 w-1.5 rounded-full {{ $row['is_matched'] ? 'bg-emerald-500' : 'bg-rose-500' }}"></span>
                                        {{ $row['is_matched'] ? 'Sinkron' : 'Selisih' }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                        <tr x-show="visibleReconciliationCount() === 0" x-cloak>
                            <td colspan="6" class="px-6 py-8 text-center text-xs text-slate-500">
                                Tidak ada data rekonsiliasi yang sesuai filter.
                                <button type="button" @click="resetFilters()" class="ml-2 font-semibold text-primary hover:underline">Reset Filter</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- Tab 2: Matriks Distribusi 8 UPT (Tab 1b) --}}
            <div x-show="activeTab === 'matrix'" x-cloak class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-xs">
                    <thead class="bg-slate-50/80 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-4 py-2.5">Atribut Aset</th>
                            <th class="px-3 py-2.5">Kategori</th>
                            @foreach ($assetAnalysis['canonical_upts'] as $upt)
                                <th class="px-3 py-2.5 text-right">{{ $upt }}</th>
                            @endforeach
                            <th class="px-4 py-2.5 text-right">Total 8 UPT</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @foreach ($assetAnalysis['upt_matrix'] as $row)
                            <tr x-show="matchesCommon(@js($row['kategori']), @js(mb_strtolower($row['atribut'])))"
                                class="transition-colors hover:bg-slate-50/60">
                                <td class="px-4 py-2.5 font-medium text-slate-800">{{ $row['atribut'] !== '' ? $row['atribut'] : 'Tidak tercatat' }}</td>
                                <td class="px-3 py-2.5">
                                    <span class="inline-flex items-center rounded-md border border-slate-200 bg-slate-50 px-2 py-0.5 text-[11px] font-medium text-slate-600">
                                        {{ $row['kategori'] ?: 'Tidak tercatat' }}
                                    </span>
                                </td>
                                @foreach ($assetAnalysis['canonical_upts'] as $upt)
                                    @php $uptCell = $row['upt_values'][$upt] ?? null; @endphp
                                    <td class="px-3 py-2.5 text-right font-sans tabular-nums text-slate-700">
                                        @if ($uptCell === null)
                                            <span class="inline-flex items-center rounded-md bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-500">Tidak tercatat</span>
                                        @else
                                            {{ number_format((float) $uptCell, 2, ',', '.') }}
                                        @endif
                                    </td>
                                @endforeach
                                <td class="px-4 py-2.5 text-right font-sans font-semibold tabular-nums text-slate-900">
                                    {{ number_format((float) ($row['upt_sum'] ?? 0), 2, ',', '.') }}
                                </td>
                            </tr>
                        @endforeach
                        <tr x-show="visibleMatrixCount() === 0" x-cloak>
                            <td colspan="{{ count($assetAnalysis['canonical_upts']) + 3 }}" class="px-6 py-8 text-center text-xs text-slate-500">
                                Tidak ada atribut distribusi yang sesuai filter.
                                <button type="button" @click="resetFilters()" class="ml-2 font-semibold text-primary hover:underline">Reset Filter</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- Tab 3: Rekap Induk UIT (Tab 1a) --}}
            <div x-show="activeTab === 'uit'" x-cloak class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-xs">
                    <thead class="bg-slate-50/80 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-6 py-2.5">Kelompok Aset</th>
                            <th class="px-4 py-2.5">Satuan</th>
                            <th class="px-4 py-2.5">Kategori</th>
                            <th class="px-4 py-2.5">Periode</th>
                            <th class="px-6 py-2.5 text-right">Nilai Induk UIT</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @foreach ($assetAnalysis['uit_rows'] as $row)
                            <tr x-show="matchesCommon(@js($row['kategori']), @js(mb_strtolower($row['kelompok'].' '.$row['satuan'].' '.$row['label'])))"
                                class="transition-colors hover:bg-slate-50/60">
                                <td class="px-6 py-2.5 font-medium text-slate-800">{{ $row['kelompok'] !== '' ? $row['kelompok'] : 'Tidak tercatat' }}</td>
                                <td class="px-4 py-2.5 text-slate-600">
                                    @if (blank($row['satuan']) || trim((string) $row['satuan']) === '-')
                                        <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500">Tidak tercatat</span>
                                    @else
                                        {{ $row['satuan'] }}
                                    @endif
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="inline-flex items-center rounded-md border border-slate-200 bg-slate-50 px-2 py-0.5 text-[11px] font-medium text-slate-600">
                                        {{ $row['kategori'] ?: 'Tidak tercatat' }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 font-sans tabular-nums text-slate-600">{{ $formatAssetDate($row['period']) }}</td>
                                <td class="px-6 py-2.5 text-right font-sans font-semibold tabular-nums text-slate-900">
                                    {{ number_format((float) ($row['value'] ?? 0), 2, ',', '.') }}
                                </td>
                            </tr>
                        @endforeach
                        <tr x-show="visibleUitCount() === 0" x-cloak>
                            <td colspan="5" class="px-6 py-8 text-center text-xs text-slate-500">
                                Tidak ada data induk UIT yang sesuai filter.
                                <button type="button" @click="resetFilters()" class="ml-2 font-semibold text-primary hover:underline">Reset Filter</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    {{-- Tabel Metrik Data (Read-Only) --}}
    <div class="ibs-card animate-entrance stagger-3 mt-6 overflow-hidden">
        <div class="flex flex-col gap-2 border-b border-slate-100 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-2">
                <h2 class="ibs-section-title text-base">Rincian Metrik Data</h2>
                <span class="rounded-md bg-slate-100 px-2 py-0.5 font-sans text-[11px] font-medium tabular-nums text-slate-600">
                    {{ number_format($metrics->total(), 0, ',', '.') }} baris
                </span>
                <span class="sr-only">Single Source of Truth: Detektor, Bukan Generator</span>
            </div>
            <span class="inline-flex items-center rounded-md border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-medium text-slate-600">
                Sinkronisasi Baca Saja<span class="sr-only"> Read-Only</span>
            </span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-100 text-xs">
                <thead class="bg-slate-50/80 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-6 py-3">Nama Metrik / Atribut</th>
                        <th class="px-6 py-3">Periode</th>
                        <th class="px-6 py-3 text-right">Nilai</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @forelse ($metrics as $metric)
                        <tr class="transition hover:bg-slate-50/60">
                            <td class="px-6 py-2.5 font-medium text-slate-800">{{ $metric->metric_name }}</td>
                            <td class="px-6 py-2.5 font-sans tabular-nums text-slate-600 text-xs">{{ $formatAssetDate($metric->period) }}</td>
                            <td class="px-6 py-2.5 text-right font-sans tabular-nums font-semibold text-slate-900">{{ number_format((float) $metric->value, 2, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-6 py-8 text-center text-xs text-slate-400">Belum ada baris data terimpor.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-100 px-6 py-4">{{ $metrics->links() }}</div>
    </div>
</div>
@endsection
