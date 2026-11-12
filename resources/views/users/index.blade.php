{{--
    Halaman daftar user (hanya role dengan permission user.view).
    Frontend hanya menyembunyikan; keamanan tetap di middleware 'can:user.view'.
--}}
@extends('layouts.app')

@section('title', 'Users')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8">
    {{-- Header Halaman & Tombol Tambah --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900">Users</h1>
            <p class="mt-1 text-sm text-slate-500">Daftar semua pengguna aplikasi beserta perannya.</p>
        </div>
        @can('user.create')
            <a href="{{ route('users.create') }}"
               class="inline-flex items-center gap-2 rounded-lg border border-slate-200/90 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-2xs transition-all duration-150 hover:border-teal-500 hover:bg-teal-50/40 hover:text-teal-700 active:bg-teal-100/60">
                <svg class="h-4 w-4 text-teal-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 5v14M5 12h14"/>
                </svg>
                Tambah User
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

    {{-- Card Tabel Data --}}
    <div class="mt-6 overflow-hidden rounded-xl border border-slate-200/80 bg-white shadow-2xs">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50/80">
                    <tr>
                        <th scope="col" class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Name</th>
                        <th scope="col" class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Email</th>
                        <th scope="col" class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Role</th>
                        <th scope="col" class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Divisi</th>
                        <th scope="col" class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @forelse ($users as $user)
                        @php
                            $words = preg_split('/\s+/', trim($user->name));
                            $initials = '';
                            if (count($words) >= 2) {
                                $initials = strtoupper(substr($words[0], 0, 1) . substr($words[1], 0, 1));
                            } else {
                                $initials = strtoupper(substr($user->name, 0, 2));
                            }
                        @endphp
                        <tr class="transition-colors hover:bg-slate-50/60">
                            {{-- Nama dengan Initial Avatar --}}
                            <td class="whitespace-nowrap px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-slate-200/80 bg-slate-100 text-xs font-semibold text-slate-700 select-none">
                                        {{ $initials }}
                                    </div>
                                    <div class="font-medium text-slate-900">
                                        {{ $user->name }}
                                    </div>
                                </div>
                            </td>

                            {{-- Email --}}
                            <td class="whitespace-nowrap px-6 py-4 text-slate-600">
                                {{ $user->email }}
                            </td>

                            {{-- Role Badge --}}
                            <td class="whitespace-nowrap px-6 py-4">
                                @if ($user->role)
                                    <span class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-slate-50/80 px-2.5 py-1 text-xs font-medium text-slate-700 shadow-2xs">
                                        <span class="h-1.5 w-1.5 rounded-full bg-teal-600"></span>
                                        {{ $user->role->name }}
                                    </span>
                                @else
                                    <span class="text-xs italic text-slate-400">No role</span>
                                @endif
                            </td>

                            {{-- Divisi --}}
                            <td class="whitespace-nowrap px-6 py-4">
                                @if ($user->division)
                                    <span class="inline-flex items-center gap-1.5 rounded-md border border-slate-200/80 bg-slate-50/60 px-2.5 py-1 text-xs font-medium text-slate-600">
                                        <span class="h-1 w-1 rounded-full bg-slate-400"></span>
                                        {{ $user->division->name }}
                                    </span>
                                @else
                                    <span class="text-xs text-slate-400">-</span>
                                @endif
                            </td>

                            {{-- Aksi --}}
                            <td class="whitespace-nowrap px-6 py-4">
                                <div class="inline-flex items-center gap-1.5">
                                    {{-- Tombol Edit --}}
                                    @can('user.update')
                                        <a href="{{ route('users.edit', $user) }}"
                                           class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200/90 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 shadow-2xs transition-all duration-150 hover:border-slate-300 hover:bg-slate-50 active:bg-slate-100">
                                            <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                            </svg>
                                            Edit
                                        </a>
                                    @endcan

                                    {{-- Tombol Hapus & Badge State --}}
                                    @can('user.delete')
                                        @if ($user->is(auth()->user()))
                                            <span class="inline-flex items-center rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-xs font-medium text-slate-500">
                                                Akun Anda
                                            </span>
                                        @endif

                                        @if ($user->hasRole(\App\Models\Role::ADMIN) && ($adminCount ?? 1) <= 1)
                                            <span class="inline-flex items-center gap-1.5 rounded-lg border border-amber-200/80 bg-amber-50/60 px-2.5 py-1.5 text-xs font-medium text-amber-800">
                                                <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                                                Admin Terakhir
                                            </span>
                                        @elseif (! $user->is(auth()->user()))
                                            <form method="POST" action="{{ route('users.destroy', $user) }}"
                                                  onsubmit="return confirm('Hapus permanen user &quot;{{ addslashes($user->name) }}&quot;? Riwayat dataset yang pernah diunggah akan tetap tersimpan.');">
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
                                        @endif
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-10 text-center text-sm text-slate-500">
                                Belum ada user terdaftar.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Navigasi Paginasi --}}
    <div class="mt-5">
        {{ $users->links() }}
    </div>
</div>
@endsection
