@extends('layouts.app')

@section('title', 'Edit User')

@section('content')
<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:py-10">

    <div class="mb-6">
        <a href="{{ route('users.index') }}"
           class="inline-flex items-center gap-2 rounded-lg border border-slate-200/90 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 shadow-2xs transition-all duration-150 hover:border-slate-300 hover:bg-slate-50 hover:text-slate-900">
            <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M19 12H5M12 19l-7-7 7-7"/>
            </svg>
            Kembali ke daftar user
        </a>

        <div class="mt-4">
            <h1 class="text-2xl font-bold tracking-tight text-slate-900">
                Edit User: {{ $user->name }}
            </h1>
            <p class="mt-1 text-sm text-slate-500">
                Perbarui profil pengguna, kredensial kata sandi, dan hak akses penempatan.
            </p>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200/80 bg-white shadow-2xs">

        <div class="border-b border-slate-200/80 px-6 py-5">
            <h2 class="text-base font-semibold text-slate-900">
                Informasi Pengguna
            </h2>
            <p class="mt-1 text-xs text-slate-500">
                Perbarui data profil identitas dan hak akses akun ini.
            </p>
        </div>

        <div class="p-6">

            @if ($errors->any())
                <div class="mb-6 rounded-lg border border-rose-200/80 border-l-4 border-l-rose-500 bg-rose-50/50 p-4 text-sm text-rose-700">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST"
                  action="{{ route('users.update', $user) }}"
                  class="space-y-6">

                @csrf
                @method('PATCH')

                {{-- Bagian 1: Identitas Pengguna --}}
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                        Identitas Pengguna
                    </h3>
                    <div class="mt-3 grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <label for="name" class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-600">
                                Nama Lengkap
                            </label>
                            <input
                                id="name"
                                type="text"
                                name="name"
                                value="{{ old('name', $user->name) }}"
                                required
                                maxlength="255"
                                placeholder="Masukkan nama lengkap"
                                class="block w-full rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 shadow-2xs transition focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/15"
                            >
                        </div>

                        <div>
                            <label for="email" class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-600">
                                Email
                            </label>
                            <input
                                id="email"
                                type="email"
                                name="email"
                                value="{{ old('email', $user->email) }}"
                                required
                                maxlength="255"
                                placeholder="nama@email.com"
                                class="block w-full rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 shadow-2xs transition focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/15"
                            >
                        </div>
                    </div>
                </div>

                {{-- Bagian 2: Keamanan & Kata Sandi --}}
                <div class="border-t border-slate-200/80 pt-6">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                        Keamanan &amp; Kata Sandi
                    </h3>
                    <p class="mt-1 text-xs text-slate-400">
                        Kosongkan kata sandi jika tidak ingin mengubah kata sandi yang sudah ada.
                    </p>
                    <div class="mt-3 grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <label for="password" class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-600">
                                Password <span class="font-normal lowercase text-slate-400">(kosongkan jika tidak ingin mengubah)</span>
                            </label>
                            <input
                                id="password"
                                type="password"
                                name="password"
                                minlength="8"
                                placeholder="Minimal 8 karakter baru"
                                class="block w-full rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 shadow-2xs transition focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/15"
                            >
                        </div>

                        <div>
                            <label for="password_confirmation" class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-600">
                                Konfirmasi Password
                            </label>
                            <input
                                id="password_confirmation"
                                type="password"
                                name="password_confirmation"
                                minlength="8"
                                placeholder="Ulangi password baru"
                                class="block w-full rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 shadow-2xs transition focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/15"
                            >
                        </div>
                    </div>
                </div>

                {{-- Bagian 3: Otorisasi & Penempatan --}}
                @php
                    $staffRoleId = $roles->firstWhere('slug', \App\Models\Role::STAFF_DIVISI)?->id;
                    $selectedRoleId = old('role_id', $user->role_id);
                    $isStaffSelected = $staffRoleId !== null && (string) $selectedRoleId === (string) $staffRoleId;
                @endphp

                <div
                    x-data="{
                        selectedRole: '{{ $selectedRoleId }}',
                        staffRoleId: '{{ $staffRoleId }}'
                    }"
                    class="border-t border-slate-200/80 pt-6"
                >
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                        Otorisasi &amp; Penempatan
                    </h3>

                    <div class="mt-3 grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <label for="role_id" class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-600">
                                Role Pengguna
                            </label>

                            <select
                                id="role_id"
                                name="role_id"
                                x-model="selectedRole"
                                required
                                class="block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/15"
                            >
                                <option value="">-- Pilih role --</option>
                                @foreach ($roles as $role)
                                    <option value="{{ $role->id }}" @selected(old('role_id', $user->role_id) == $role->id)>
                                        {{ $role->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="division_id" class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-600">
                                Divisi
                                <span
                                    x-show="String(selectedRole) === String(staffRoleId)"
                                    @if (! $isStaffSelected) x-cloak @endif
                                    class="font-normal text-rose-600 lowercase"
                                >
                                    (wajib untuk Staf Divisi)
                                </span>
                                <span
                                    x-show="String(selectedRole) !== String(staffRoleId)"
                                    @if ($isStaffSelected) x-cloak @endif
                                    class="font-normal text-slate-400 lowercase"
                                >
                                    (opsional)
                                </span>
                            </label>

                            <select
                                id="division_id"
                                name="division_id"
                                :required="String(selectedRole) === String(staffRoleId)"
                                class="block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/15"
                            >
                                <option
                                    value=""
                                    x-text="String(selectedRole) === String(staffRoleId) ? '-- Pilih divisi (wajib) --' : '-- Tanpa divisi --'"
                                >{{ $isStaffSelected ? '-- Pilih divisi (wajib) --' : '-- Tanpa divisi --' }}</option>

                                @foreach ($divisions as $division)
                                    <option value="{{ $division->id }}" @selected(old('division_id', $user->division_id) == $division->id)>
                                        {{ $division->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                {{-- Tombol Aksi --}}
                <div class="flex flex-col-reverse gap-3 border-t border-slate-200/80 pt-6 sm:flex-row sm:justify-end">
                    <a
                        href="{{ route('users.index') }}"
                        class="inline-flex items-center justify-center rounded-lg border border-slate-200/90 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-2xs transition hover:border-slate-300 hover:bg-slate-50 active:bg-slate-100"
                    >
                        Batal
                    </a>

                    <button
                        type="submit"
                        class="inline-flex items-center justify-center rounded-lg bg-teal-600 px-5 py-2 text-xs font-semibold text-white shadow-2xs transition hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2 active:bg-teal-800"
                    >
                        Perbarui Pengguna
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>
@endsection
