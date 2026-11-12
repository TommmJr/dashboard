{{--
    Halaman daftar divisi (hanya admin via permission user.view).
    Menampilkan jumlah user dan dataset per divisi untuk konteks sebelum hapus.
--}}
@extends('layouts.app')

@section('title', 'Divisi')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8">
    {{-- Header Halaman & Tombol Tambah --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900">Divisi</h1>
            <p class="mt-1 text-sm text-slate-500">Daftar divisi yang terdaftar dalam sistem beserta statistik keterkaitannya.</p>
        </div>
        @can('user.create')
            <a href="{{ route('divisions.create') }}"
               class="inline-flex items-center gap-2 rounded-lg border border-slate-200/90 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-2xs transition-all duration-150 hover:border-teal-500 hover:bg-teal-50/40 hover:text-teal-700 active:bg-teal-100/60">
                <svg class="h-4 w-4 text-teal-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 5v14M5 12h14"/>
                </svg>
                Tambah Divisi
            </a>
        @endcan
    </div>

    {{-- Notifikasi Pesan Flash --}}
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

    {{-- Card Tabel Divisi --}}
    <div class="mt-6 overflow-hidden rounded-xl border border-slate-200/80 bg-white shadow-2xs">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50/80">
                    <tr>
                        <th scope="col" class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Nama</th>
                        <th scope="col" class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Slug</th>
                        <th scope="col" class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Deskripsi</th>
                        <th scope="col" class="px-6 py-3.5 text-center text-xs font-semibold uppercase tracking-wider text-slate-500">Users</th>
                        <th scope="col" class="px-6 py-3.5 text-center text-xs font-semibold uppercase tracking-wider text-slate-500">Datasets</th>
                        <th scope="col" class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @forelse ($divisions as $division)
                        <tr class="transition-colors hover:bg-slate-50/60">
                            {{-- Nama Divisi --}}
                            <td class="whitespace-nowrap px-6 py-4 font-medium text-slate-900">
                                {{ $division->name }}
                            </td>

                            {{-- Slug Divisi (Monospaced Badge dengan Lock Indicator) --}}
                            <td class="whitespace-nowrap px-6 py-4">
                                <span class="inline-flex items-center gap-1.5 rounded-md border border-slate-200/90 bg-slate-50 px-2.5 py-1 font-mono text-xs text-slate-700 shadow-2xs" title="Slug bersifat permanen (immutable)">
                                    <svg class="h-3 w-3 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                    </svg>
                                    {{ $division->slug }}
                                </span>
                            </td>

                            {{-- Deskripsi --}}
                            <td class="max-w-xs truncate px-6 py-4 text-slate-600">
                                {{ $division->description ?? '-' }}
                            </td>

                            {{-- Statistik Users --}}
                            <td class="whitespace-nowrap px-6 py-4 text-center">
                                <span class="inline-flex items-center justify-center rounded-full bg-slate-100 px-2.5 py-0.5 font-mono text-xs font-medium text-slate-700">
                                    {{ $division->users_count }}
                                </span>
                            </td>

                            {{-- Statistik Datasets --}}
                            <td class="whitespace-nowrap px-6 py-4 text-center">
                                <span class="inline-flex items-center justify-center rounded-full bg-slate-100 px-2.5 py-0.5 font-mono text-xs font-medium text-slate-700">
                                    {{ $division->datasets_count }}
                                </span>
                            </td>

                            {{-- Tombol Aksi --}}
                            <td class="whitespace-nowrap px-6 py-4">
                                <div class="inline-flex items-center gap-1.5">
                                    @can('user.update')
                                        <a href="{{ route('divisions.edit', $division) }}"
                                           class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200/90 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 shadow-2xs transition-all duration-150 hover:border-slate-300 hover:bg-slate-50 active:bg-slate-100">
                                            <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                            </svg>
                                            Edit
                                        </a>
                                    @endcan

                                    @can('user.delete')
                                        <form method="POST" action="{{ route('divisions.destroy', $division) }}"
                                              onsubmit="return confirm('Hapus divisi &quot;{{ addslashes($division->name) }}&quot;?');">
                                            @csrf @method('DELETE')
                                            <button type="submit"
                                                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200/90 bg-white px-2.5 py-1.5 text-xs font-medium text-rose-600 shadow-2xs transition-all duration-150 hover:border-rose-200 hover:bg-rose-50/60 active:bg-rose-100/60">
                                                <svg class="h-3.5 w-3.5 text-rose-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
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
                            <td colspan="6" class="px-6 py-10 text-center text-sm text-slate-500">
                                Belum ada divisi terdaftar.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
