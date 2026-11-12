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

    {{-- Pesan Flash --}}
    @if (session('success'))
        <div class="mt-4 rounded-lg border border-emerald-200/80 border-l-4 border-l-emerald-500 bg-emerald-50/40 p-3.5 text-sm text-emerald-800 shadow-2xs">
            {{ session('success') }}
        </div>
    @endif

    @if (session('error'))
        <div class="mt-4 rounded-lg border border-rose-200/80 border-l-4 border-l-rose-500 bg-rose-50/40 p-3.5 text-sm text-rose-800 shadow-2xs">
            {{ session('error') }}
        </div>
    @endif

    {{-- Card Ringkasan Dataset --}}
    <div class="mt-4 rounded-xl border border-slate-200/80 bg-white p-6 shadow-2xs">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0 flex-1">
                <h1 class="text-2xl font-bold tracking-tight text-slate-900 break-words">{{ $dataset->original_name }}</h1>
                <dl class="mt-4 grid grid-cols-2 gap-x-8 gap-y-3 text-xs sm:grid-cols-3">
                    <div>
                        <dt class="font-semibold text-slate-500 uppercase tracking-wider text-2xs">Divisi</dt>
                        <dd class="mt-0.5 text-slate-800 font-medium">{{ $dataset->division?->name ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-slate-500 uppercase tracking-wider text-2xs">Pengunggah</dt>
                        <dd class="mt-0.5 text-slate-800 font-medium">{{ $dataset->user?->name ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-slate-500 uppercase tracking-wider text-2xs">Ukuran</dt>
                        <dd class="mt-0.5 font-mono text-slate-800">{{ number_format($dataset->size / 1024, 1) }} KB</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-slate-500 uppercase tracking-wider text-2xs">MIME</dt>
                        <dd class="mt-0.5 font-mono text-slate-800 break-all">{{ $dataset->mime_type }}</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-slate-500 uppercase tracking-wider text-2xs">Diunggah</dt>
                        <dd class="mt-0.5 font-mono text-slate-800">{{ $dataset->created_at->format('d M Y H:i') }}</dd>
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
                @endphp
                @if ($dataset->validation_report || $periodConflictCount > 0)
                    <div class="mt-4 rounded-lg border border-slate-200/90 bg-slate-50/40 p-4 text-xs">
                        <p class="font-semibold text-slate-800">Ringkasan validasi</p>
                        <div class="mt-2 flex flex-wrap gap-2 text-2xs">
                            <span class="inline-flex items-center rounded-md border border-emerald-200 bg-emerald-50 px-2 py-0.5 font-semibold text-emerald-800">OK: {{ $validationReport['ok'] ?? 0 }}</span>
                            <span class="inline-flex items-center rounded-md border border-amber-200 bg-amber-50 px-2 py-0.5 font-semibold text-amber-800">WARNING: {{ $validationReport['warning'] ?? 0 }}</span>
                            <span class="inline-flex items-center rounded-md border border-rose-200 bg-rose-50 px-2 py-0.5 font-semibold text-rose-800">FATAL: {{ $validationReport['fatal'] ?? 0 }}</span>
                            @if ($periodConflictCount > 0)
                                <span class="inline-flex items-center rounded-md border border-amber-300 bg-amber-100 px-2 py-0.5 font-semibold text-amber-900">KONFLIK PERIODE: {{ $periodConflictCount }}</span>
                            @endif
                        </div>
                        @if ($periodConflictCount > 0)
                            <div class="mt-2.5 rounded-lg border border-amber-200 bg-amber-50/70 p-3 text-xs text-amber-800">
                                <p class="font-semibold">Konflik periode banner sheet ({{ $periodConflictCount }} observasi)</p>
                                <p class="mt-0.5">
                                    Periode banner sheet berbeda dari periode upload efektif. Data tetap disimpan menggunakan periode upload{{ $conflictingSheetPeriods !== [] ? ' (banner sheet: '.implode(', ', $conflictingSheetPeriods).')' : '' }}.
                                </p>
                            </div>
                        @endif
                        @if (! empty($validationReport['issues']))
                            <ul class="mt-3 max-h-40 space-y-1 overflow-y-auto text-xs text-slate-600">
                                @foreach ($validationReport['issues'] as $issue)
                                    <li>
                                        <span class="font-semibold {{ $issue['severity'] === 'FATAL' ? 'text-rose-700' : 'text-amber-700' }}">[{{ $issue['severity'] }}]</span>
                                        Baris {{ $issue['row'] }}: {{ $issue['message'] }}
                                    </li>
                                @endforeach
                            </ul>
                            @if (! empty($validationReport['issues_truncated']))
                                <p class="mt-1 text-2xs text-slate-400">Hanya menampilkan 50 masalah pertama. Total: {{ ($validationReport['fatal'] ?? 0) + ($validationReport['warning'] ?? 0) }}.</p>
                            @endif
                        @endif
                    </div>
                @endif
            </div>

            <div class="inline-flex flex-wrap items-center gap-2 shrink-0">
                @can('report.export')
                    <a href="{{ route('datasets.export', $dataset) }}"
                       class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200/90 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-2xs transition-all hover:border-slate-300 hover:bg-slate-50">
                        <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="7 10 12 15 17 10"/>
                            <line x1="12" y1="15" x2="12" y2="3"/>
                        </svg>
                        Export
                    </a>
                @endcan

                @can('dataset.update')
                    @if ($dataset->status !== \App\Enums\DatasetStatus::ARCHIVED)
                        <form method="POST" action="{{ route('datasets.archive', $dataset) }}"
                              onsubmit="return confirm('Arsipkan dataset ini? Data keluar dari chart tetapi tetap tersimpan.');">
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
                          onsubmit="return confirm('HAPUS PERMANEN dataset ini beserta seluruh baris datanya?');">
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
    </div>

    {{-- Tabel Metrik Data (Read-Only) --}}
    <div class="mt-6 rounded-xl border border-slate-200/80 bg-white shadow-2xs overflow-hidden">
        <div class="border-b border-slate-100 px-6 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <div>
                <h2 class="text-base font-bold text-slate-900">Rincian Metrik Data ({{ number_format($metrics->total(), 0, ',', '.') }} baris)</h2>
                <p class="mt-1 text-xs text-slate-500">
                    Data metrik bersifat baca-saja (Single Source of Truth: "Detektor, Bukan Generator") dan bersumber langsung dari unggahan berkas atau sinkronisasi spreadsheet.
                </p>
            </div>
            <span class="inline-flex items-center rounded-md border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-medium text-slate-600">
                Read-Only
            </span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-100 text-xs">
                <thead class="bg-slate-50/80 text-left text-2xs font-semibold uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-6 py-3">Nama Metrik / Atribut</th>
                        <th class="px-6 py-3">Periode</th>
                        <th class="px-6 py-3 text-right">Nilai</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @forelse ($metrics as $metric)
                        <tr class="hover:bg-slate-50/60 transition">
                            <td class="px-6 py-2.5 font-medium text-slate-800">{{ $metric->metric_name }}</td>
                            <td class="px-6 py-2.5 text-slate-600 font-mono text-xs">{{ $metric->period }}</td>
                            <td class="px-6 py-2.5 text-right font-mono font-semibold text-slate-900">{{ number_format((float) $metric->value, 2, ',', '.') }}</td>
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
