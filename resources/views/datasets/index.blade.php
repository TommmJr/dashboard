@extends('layouts.app')

@section('title', 'Datasets')

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

    {{-- Header & Toolbar --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <h1 class="text-2xl font-bold tracking-tight text-slate-900">Datasets</h1>
            @if ($canUploadAny ?? false)
                <a href="{{ route('datasets.create') }}"
                   class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white shadow-2xs transition-all hover:bg-primary/90">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 5v14M5 12h14"/>
                    </svg>
                    Upload Dataset
                </a>
            @endif
        </div>

        <form method="GET" class="ibs-card flex items-center gap-2.5 px-3 py-1.5">
            <label for="status" class="text-xs font-medium text-slate-500">Status</label>
            <select id="status" name="status" data-auto-submit
                    class="h-8 rounded-lg border border-slate-200 bg-white px-2.5 text-xs font-medium text-slate-700 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                <option value="">Aktif (Selain Arsip)</option>
                @foreach (\App\Enums\DatasetStatus::cases() as $case)
                    <option value="{{ $case->value }}" @selected(request('status') === $case->value)>{{ $case->label() }}</option>
                @endforeach
            </select>
        </form>
    </div>

    {{-- Banner Pemrosesan Berjalan --}}
    @if (($activeProcessingCount ?? 0) > 0)
        <div id="dataset-processing-banner"
             role="status"
             x-data="{
                 timerId: null,
                 startPolling() {
                     this.timerId = setInterval(async () => {
                         try {
                             const res = await fetch(window.location.href, { headers: { 'Accept': 'text/html' } });
                             if (!res.ok) return;
                             const html = await res.text();
                             if (!html.includes('id=&quot;dataset-processing-banner&quot;')) {
                                 clearInterval(this.timerId);
                                 window.location.reload();
                             }
                         } catch (e) {}
                     }, 5000);
                     window.addEventListener('pagehide', () => clearInterval(this.timerId), { once: true });
                 }
             }"
             x-init="startPolling()"
             class="ibs-card mt-5 flex flex-wrap items-center justify-between gap-3 border-primary/25 bg-primary-soft/60 px-4 py-3">
            <div class="flex min-w-0 items-center gap-3">
                <span class="relative flex h-2.5 w-2.5 shrink-0" aria-hidden="true">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-accent opacity-70"></span>
                    <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-primary"></span>
                </span>
                <div class="min-w-0">
                    <p class="text-xs font-semibold text-primary tabular-nums">
                        Pemrosesan data sedang berjalan ({{ $activeProcessingCount }} dataset)
                    </p>
                    <p class="text-[11px] text-slate-600">
                        Halaman diperbarui otomatis setelah validasi selesai.
                    </p>
                </div>
            </div>
            <a href="{{ route('datasets.index', request()->query()) }}"
               class="inline-flex items-center gap-1.5 rounded-lg border border-primary/25 bg-white px-3 py-1.5 text-xs font-medium text-primary shadow-2xs transition-colors hover:bg-primary-soft">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M21 12a9 9 0 1 1-2.64-6.36"/>
                    <path d="M21 3v6h-6"/>
                </svg>
                Muat Ulang
            </a>
        </div>
    @endif

    {{-- Tabel Dataset --}}
    <div class="ibs-card animate-entrance stagger-1 mt-6 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-100 text-xs">
                <thead class="bg-slate-50/80 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Nama File</th>
                        <th class="px-4 py-3">Divisi</th>
                        <th class="px-4 py-3">Pengunggah</th>
                        <th class="px-4 py-3">Ukuran</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Tanggal</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($datasets as $dataset)
                        <tr class="transition-colors hover:bg-slate-50/70">
                            <td class="px-4 py-3 font-medium text-slate-900">
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="{{ route('datasets.show', $dataset) }}" class="font-medium text-slate-900 transition-colors hover:text-primary">
                                        {{ $dataset->original_name }}
                                    </a>
                                    @if ($dataset->is_asset_dataset ?? false)
                                        <span class="inline-flex items-center gap-1 rounded-md border border-primary/20 bg-primary-soft px-1.5 py-0.5 text-[10px] font-semibold text-primary">
                                            <span class="h-1.5 w-1.5 rounded-full bg-primary"></span>
                                            Inspeksi Aset
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                @if ($dataset->division?->name)
                                    {{ $dataset->division->name }}
                                @else
                                    <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500">Tidak tercatat</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                @if ($dataset->user?->name)
                                    {{ $dataset->user->name }}
                                @else
                                    <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500">Tidak tercatat</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-sans tabular-nums text-slate-600">{{ number_format($dataset->size / 1024, 1) }} KB</td>
                            <td class="px-4 py-3">
                                <x-dataset-status-badge :status="$dataset->status->value" />
                            </td>
                            <td class="px-4 py-3 font-sans tabular-nums text-slate-500">{{ $dataset->created_at->format('d M Y H:i') }}</td>
                            <td class="px-4 py-3 text-right">
                                <div class="inline-flex items-center gap-1.5">
                                    <a href="{{ route('datasets.show', $dataset) }}"
                                       title="{{ ($dataset->is_asset_dataset ?? false) ? 'Buka inspeksi dan rekonsiliasi data aset UIT vs 8 UPT' : 'Lihat detail dataset' }}"
                                       class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200/90 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 shadow-2xs transition-all hover:border-slate-300 hover:bg-slate-50">
                                        <svg class="h-3.5 w-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>
                                            <circle cx="12" cy="12" r="3"/>
                                        </svg>
                                        Detail
                                    </a>
                                    @can('dataset.update')
                                        @if (! in_array($dataset->status, [\App\Enums\DatasetStatus::ARCHIVED, \App\Enums\DatasetStatus::UPLOADING, \App\Enums\DatasetStatus::VALIDATING], true))
                                            <form method="POST" action="{{ route('datasets.archive', $dataset) }}"
                                                  data-confirm="Arsipkan dataset ini? Data keluar dari chart tetapi tetap tersimpan.">
                                                @csrf @method('PATCH')
                                                <button type="submit"
                                                        @click.prevent="openConfirm('Arsipkan Dataset', 'Arsipkan dataset ini? Data keluar dari chart tetapi tetap tersimpan.', '{{ route('datasets.archive', $dataset) }}', 'PATCH', 'Arsipkan', 'bg-amber-600 hover:bg-amber-700 text-white')"
                                                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200/90 bg-white px-2.5 py-1.5 text-xs font-medium text-amber-700 shadow-2xs transition-all hover:border-amber-200 hover:bg-amber-50/60">
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
                                              data-confirm="HAPUS PERMANEN dataset ini beserta seluruh baris datanya? Tindakan ini tidak bisa dibatalkan.">
                                            @csrf @method('DELETE')
                                            <button type="submit"
                                                    @click.prevent="openConfirm('Hapus Permanen Dataset', 'HAPUS PERMANEN dataset ini beserta seluruh baris datanya? Tindakan ini tidak bisa dibatalkan.', '{{ route('datasets.destroy', $dataset) }}', 'DELETE', 'Hapus Permanen', 'bg-rose-600 hover:bg-rose-700 text-white')"
                                                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200/90 bg-white px-2.5 py-1.5 text-xs font-medium text-rose-600 shadow-2xs transition-all hover:border-rose-200 hover:bg-rose-50/60">
                                                <svg class="h-3.5 w-3.5 text-rose-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M3 6h18"/>
                                                    <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/>
                                                    <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>
                                                    <line x1="10" y1="11" x2="10" y2="17"/>
                                                    <line x1="14" y1="11" x2="14" y2="17"/>
                                                </svg>
                                                Hapus
                                            </button>
                                        </form>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-xs text-slate-400">
                                Belum ada dataset.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-5">{{ $datasets->links() }}</div>
</div>
@endsection
