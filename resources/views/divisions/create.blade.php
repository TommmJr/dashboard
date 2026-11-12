@extends('layouts.app')

@section('title', 'Tambah Divisi')

@section('content')
<div class="mx-auto max-w-2xl px-4 py-8 sm:px-6 lg:py-10">

    <div class="mb-6">
        <a href="{{ route('divisions.index') }}"
           class="inline-flex items-center gap-2 rounded-lg border border-slate-200/90 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 shadow-2xs transition-all duration-150 hover:border-slate-300 hover:bg-slate-50 hover:text-slate-900">
            <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M19 12H5M12 19l-7-7 7-7"/>
            </svg>
            Kembali ke daftar divisi
        </a>

        <div class="mt-4">
            <h1 class="text-2xl font-bold tracking-tight text-slate-900">
                Tambah Divisi Baru
            </h1>
            <p class="mt-1 text-sm text-slate-500">
                Tambahkan unit divisi organisasi baru ke dalam sistem.
            </p>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200/80 bg-white shadow-2xs">

        <div class="border-b border-slate-200/80 px-6 py-5">
            <h2 class="text-base font-semibold text-slate-900">
                Informasi Divisi
            </h2>
            <p class="mt-1 text-xs text-slate-500">
                Lengkapi nama dan keterangan operasional divisi di bawah ini.
            </p>
        </div>

        <div class="p-6">

            @if ($errors->any())
                <div class="mb-6 rounded-lg border border-rose-200/80 border-l-4 border-l-rose-500 bg-rose-50/50 p-4 text-sm text-rose-700">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST"
                  action="{{ route('divisions.store') }}"
                  class="space-y-6">

                @csrf

                {{-- Ketentuan Identifier Slug --}}
                <div class="rounded-lg border border-teal-200/80 bg-teal-50/50 p-3.5 text-xs text-teal-800">
                    <div class="flex items-start gap-2.5">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-teal-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                        <span>
                            <strong>Ketentuan Identifier Slug:</strong> Slug divisi dibuat otomatis dari nama divisi dan bersifat permanen (immutable) setelah disimpan untuk melindungi integritas mapping dataset dan tata letak dashboard.
                        </span>
                    </div>
                </div>

                {{-- Nama Divisi --}}
                <div>
                    <label for="name" class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-600">
                        Nama Divisi
                    </label>
                    <input
                        id="name"
                        type="text"
                        name="name"
                        value="{{ old('name') }}"
                        required
                        maxlength="255"
                        placeholder="Contoh: Managemen Aset"
                        class="block w-full rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 shadow-2xs transition focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/15"
                    >
                </div>

                {{-- Deskripsi --}}
                <div>
                    <label for="description" class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-600">
                        Deskripsi <span class="font-normal lowercase text-slate-400">(opsional)</span>
                    </label>
                    <textarea
                        id="description"
                        name="description"
                        rows="3"
                        maxlength="1000"
                        placeholder="Keterangan lingkup tugas atau catatan divisi..."
                        class="block w-full rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 shadow-2xs transition focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/15"
                    >{{ old('description') }}</textarea>
                </div>

                {{-- Tombol Aksi --}}
                <div class="flex flex-col-reverse gap-3 border-t border-slate-200/80 pt-6 sm:flex-row sm:justify-end">
                    <a
                        href="{{ route('divisions.index') }}"
                        class="inline-flex items-center justify-center rounded-lg border border-slate-200/90 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-2xs transition hover:border-slate-300 hover:bg-slate-50 active:bg-slate-100"
                    >
                        Batal
                    </a>

                    <button
                        type="submit"
                        class="inline-flex items-center justify-center rounded-lg bg-teal-600 px-5 py-2 text-xs font-semibold text-white shadow-2xs transition hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2 active:bg-teal-800"
                    >
                        Simpan Divisi
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>
@endsection
