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
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-2xl font-bold tracking-tight text-slate-900">Datasets</h1>
                @can('dataset.import')
                    <a href="{{ route('datasets.create') }}"
                       class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white shadow-2xs transition-all hover:bg-primary-dark">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 5v14M5 12h14"/>
                        </svg>
                        + Upload Dataset
                    </a>
                @endcan
            </div>
            <p class="mt-1 text-xs text-slate-500">
                Kelola data mentah berkas KPI kinerja dan sinkronisasi lembar kerja unit.
            </p>
        </div>

        <form method="GET" class="flex items-center gap-2 rounded-xl border border-slate-200/80 bg-white p-2 shadow-2xs">
            <label for="status" class="text-xs font-medium text-slate-600">Status</label>
            <select id="status" name="status" onchange="this.form.submit()"
                    class="h-8.5 rounded-lg border border-slate-200 bg-white px-2.5 text-xs text-slate-700 shadow-2xs focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500">
                <option value="">Aktif (non-arsip)</option>
                @foreach (\App\Enums\DatasetStatus::cases() as $case)
                    <option value="{{ $case->value }}" @selected(request('status') === $case->value)>{{ $case->label() }}</option>
                @endforeach
            </select>
        </form>
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

    {{-- Tabel Dataset --}}
    <div class="mt-6 overflow-x-auto rounded-xl border border-slate-200/80 bg-white shadow-2xs">
        <table class="min-w-full divide-y divide-slate-100 text-xs">
            <thead class="bg-slate-50/80 text-left text-2xs font-semibold uppercase tracking-wider text-slate-500">
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
                    <tr class="hover:bg-slate-50/60 transition">
                        <td class="px-4 py-3 font-medium text-slate-900">
                            <a href="{{ route('datasets.show', $dataset) }}" class="font-medium text-slate-900 hover:text-cyan-600 transition-colors">
                                {{ $dataset->original_name }}
                            </a>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $dataset->division?->name ?? '-' }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $dataset->user?->name ?? 'Pengguna telah dihapus' }}</td>
                        <td class="px-4 py-3 font-mono text-slate-600">{{ number_format($dataset->size / 1024, 1) }} KB</td>
                        <td class="px-4 py-3">
                            <x-dataset-status-badge :status="$dataset->status->value" />
                        </td>
                        <td class="px-4 py-3 font-mono text-slate-500">{{ $dataset->created_at->format('d M Y H:i') }}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="inline-flex items-center gap-1.5">
                                <a href="{{ route('datasets.show', $dataset) }}"
                                   class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200/90 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 shadow-2xs transition-all hover:border-slate-300 hover:bg-slate-50">
                                    <svg class="h-3.5 w-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>
                                        <circle cx="12" cy="12" r="3"/>
                                    </svg>
                                    Detail
                                </a>
                                @can('dataset.update')
                                    @if ($dataset->status !== \App\Enums\DatasetStatus::ARCHIVED)
                                        <form method="POST" action="{{ route('datasets.archive', $dataset) }}"
                                              onsubmit="return confirm('Arsipkan dataset ini? Data keluar dari chart tetapi tetap tersimpan.');">
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
                                          onsubmit="return confirm('HAPUS PERMANEN dataset ini beserta seluruh baris datanya? Tindakan ini tidak bisa dibatalkan.');">
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

    <div class="mt-5">{{ $datasets->links() }}</div>
</div>
@endsection
