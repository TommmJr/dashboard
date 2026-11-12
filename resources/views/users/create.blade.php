
@extends('layouts.app')

@section('title', 'Tambah User')

@section('content')
<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:py-10">

    <div class="mb-6">
    <a href="{{ route('users.index') }}"
       class="group inline-flex items-center gap-2 text-sm font-medium text-gray-500 transition-colors hover:text-indigo-600">
        <svg class="h-4 w-4 transition-transform group-hover:-translate-x-1"
             xmlns="http://www.w3.org/2000/svg"
             fill="none"
             viewBox="0 0 24 24"
             stroke="currentColor">
            <path stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="1.8"
                  d="M15 19l-7-7 7-7" />
        </svg>
        Kembali ke daftar user
    </a>
</div>

        <div class="mt-4">
            <h1 class="text-2xl font-bold tracking-tight text-primary">
                Tambah User Baru
            </h1>
            <p class="mt-1 text-sm text-gray-500">
                Tambahkan akun pengguna dan tentukan hak aksesnya.
            </p>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">

        <div class="border-b border-gray-200 px-6 py-5">
            <h2 class="text-base font-semibold text-gray-900">
                Informasi Pengguna
            </h2>
            <p class="mt-1 text-sm text-gray-500">
                Lengkapi informasi akun di bawah ini.
            </p>
        </div>

        <div class="p-6">

            @if ($errors->any())
                <div class="mb-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST"
                  action="{{ route('users.store') }}"
                  class="space-y-6">

                @csrf

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">

                    <div class="sm:col-span-2">
                        <label for="name" class="mb-2 block text-sm font-medium text-gray-700">
                            Nama Lengkap
                        </label>
                        <input
                            id="name"
                            type="text"
                            name="name"
                            value="{{ old('name') }}"
                            required
                            maxlength="255"
                            placeholder="Masukkan nama lengkap"
                            class="block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm transition focus:border-indigo-600 focus:outline-none focus:ring-2 focus:ring-indigo-600/10"
                        >
                    </div>

                    <div class="sm:col-span-2">
                        <label for="email" class="mb-2 block text-sm font-medium text-gray-700">
                            Email
                        </label>
                        <input
                            id="email"
                            type="email"
                            name="email"
                            value="{{ old('email') }}"
                            required
                            maxlength="255"
                            placeholder="nama@email.com"
                            class="block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm transition focus:border-indigo-600 focus:outline-none focus:ring-2 focus:ring-indigo-600/10"
                        >
                    </div>

                    <div>
                        <label for="password" class="mb-2 block text-sm font-medium text-gray-700">
                            Password
                        </label>
                        <input
                            id="password"
                            type="password"
                            name="password"
                            required
                            minlength="8"
                            placeholder="Minimal 8 karakter"
                            class="block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm transition focus:border-indigo-600 focus:outline-none focus:ring-2 focus:ring-indigo-600/10"
                        >
                    </div>

                    <div>
                        <label for="password_confirmation" class="mb-2 block text-sm font-medium text-gray-700">
                            Konfirmasi Password
                        </label>
                        <input
                            id="password_confirmation"
                            type="password"
                            name="password_confirmation"
                            required
                            minlength="8"
                            placeholder="Ulangi password"
                            class="block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm transition focus:border-indigo-600 focus:outline-none focus:ring-2 focus:ring-indigo-600/10"
                        >
                    </div>

                </div>

                <div class="border-t border-gray-200 pt-6">
                    <h2 class="text-base font-semibold text-gray-900">
                        Pengaturan Akses
                    </h2>
                    <p class="mt-1 text-sm text-gray-500">
                        Tentukan role dan divisi untuk pengguna.
                    </p>
                </div>

                @php
                    $staffRoleId = $roles->firstWhere('slug', \App\Models\Role::STAFF_DIVISI)?->id;
                    $selectedRoleId = old('role_id', '');
                    $isStaffSelected = $staffRoleId !== null && (string) $selectedRoleId === (string) $staffRoleId;
                @endphp

                <div
                    x-data="{
                        selectedRole: '{{ $selectedRoleId }}',
                        staffRoleId: '{{ $staffRoleId }}'
                    }"
                    class="space-y-5"
                >

                    <div>
                        <label for="role_id" class="mb-2 block text-sm font-medium text-gray-700">
                            Role Pengguna
                        </label>

                        <select
                            id="role_id"
                            name="role_id"
                            x-model="selectedRole"
                            required
                            class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm transition focus:border-indigo-600 focus:outline-none focus:ring-2 focus:ring-indigo-600/10"
                        >
                            <option value="">-- Pilih role --</option>
                            @foreach ($roles as $role)
                                <option value="{{ $role->id }}" @selected(old('role_id') == $role->id)>
                                    {{ $role->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="division_id" class="mb-2 block text-sm font-medium text-gray-700">
                            Divisi

                            <span
                                x-show="String(selectedRole) === String(staffRoleId)"
                                @if (! $isStaffSelected) x-cloak @endif
                                class="font-normal text-red-600"
                            >
                                (wajib untuk Staf Divisi)
                            </span>

                            <span
                                x-show="String(selectedRole) !== String(staffRoleId)"
                                @if ($isStaffSelected) x-cloak @endif
                                class="font-normal text-gray-400"
                            >
                                (opsional)
                            </span>
                        </label>

                        <select
                            id="division_id"
                            name="division_id"
                            :required="String(selectedRole) === String(staffRoleId)"
                            class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm transition focus:border-indigo-600 focus:outline-none focus:ring-2 focus:ring-indigo-600/10"
                        >
                            <option
                                value=""
                                x-text="String(selectedRole) === String(staffRoleId) ? '-- Pilih divisi (wajib) --' : '-- Tanpa divisi --'"
                            >{{ $isStaffSelected ? '-- Pilih divisi (wajib) --' : '-- Tanpa divisi --' }}</option>

                            @foreach ($divisions as $division)
                                <option value="{{ $division->id }}" @selected(old('division_id') == $division->id)>
                                    {{ $division->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                </div>

                <div class="flex flex-col-reverse gap-3 border-t border-gray-200 pt-6 sm:flex-row sm:justify-end">

                    <a
                        href="{{ route('users.index') }}"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-5 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
                    >
                        Batal
                    </a>

                    <button
                        type="submit"
                        class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2"
                    >
                        Simpan User
                    </button>

                </div>

            </form>
        </div>
    </div>
</div>
@endsection
