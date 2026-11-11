@extends('layouts.app')

@section('title', 'Edit User')

@section('content')
<div class="mx-auto max-w-2xl px-4 py-8">
    {{-- Tombol Kembali: Soft Navigation Pill dengan ikon tipis --}}
    <a href="{{ route('users.index') }}" 
       class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 shadow-2xs transition-all duration-150 hover:border-slate-300 hover:bg-slate-50 hover:text-slate-900">
        <svg class="h-3.5 w-3.5 stroke-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 12H5M12 19l-7-7 7-7"/>
        </svg>
        Kembali ke daftar
    </a>

    <div class="mt-4 rounded-xl border border-slate-200/90 bg-white p-6 sm:p-8 shadow-xs">
        <h1 class="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">Edit User: {{ $user->name }}</h1>

        @if ($errors->any())
            <div class="mt-4 rounded-lg border-l-4 border-l-rose-500 border-rose-200/80 bg-rose-50/40 p-3.5 text-sm text-rose-700">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('users.update', $user) }}" class="mt-6 flex flex-col gap-4">
            @csrf
            @method('PATCH')

            <div>
                <label for="name" class="block text-xs font-semibold uppercase tracking-wider text-slate-500">Nama</label>
                <input id="name" type="text" name="name" value="{{ old('name', $user->name) }}" required maxlength="255"
                       class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-800 shadow-2xs focus:border-cyan-600 focus:outline-none focus:ring-1 focus:ring-cyan-600">
            </div>

            <div>
                <label for="email" class="block text-xs font-semibold uppercase tracking-wider text-slate-500">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email', $user->email) }}" required maxlength="255"
                       class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-800 shadow-2xs focus:border-cyan-600 focus:outline-none focus:ring-1 focus:ring-cyan-600">
            </div>

            <div>
                <label for="password" class="block text-xs font-semibold uppercase tracking-wider text-slate-500">
                    Password <span class="text-slate-400 font-normal lowercase">(kosongkan jika tidak ingin mengubah)</span>
                </label>
                <input id="password" type="password" name="password" minlength="8"
                       class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-800 shadow-2xs focus:border-cyan-600 focus:outline-none focus:ring-1 focus:ring-cyan-600">
            </div>

            <div>
                <label for="password_confirmation" class="block text-xs font-semibold uppercase tracking-wider text-slate-500">Konfirmasi Password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" minlength="8"
                       class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-800 shadow-2xs focus:border-cyan-600 focus:outline-none focus:ring-1 focus:ring-cyan-600">
            </div>

            @php
                $staffRoleId = $roles->firstWhere('slug', \App\Models\Role::STAFF_DIVISI)?->id;
                $selectedRoleId = old('role_id', $user->role_id);
                $isStaffSelected = $staffRoleId !== null && (string) $selectedRoleId === (string) $staffRoleId;
            @endphp
            <div x-data="{ selectedRole: '{{ $selectedRoleId }}', staffRoleId: '{{ $staffRoleId }}' }" class="flex flex-col gap-4">
                <div>
                    <label for="role_id" class="block text-xs font-semibold uppercase tracking-wider text-slate-500">Role</label>
                    <select id="role_id" name="role_id" x-model="selectedRole" required
                            class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 shadow-2xs focus:border-cyan-600 focus:outline-none focus:ring-1 focus:ring-cyan-600">
                        <option value="">-- Pilih role --</option>
                        @foreach ($roles as $role)
                            <option value="{{ $role->id }}" @selected(old('role_id', $user->role_id) == $role->id)>{{ $role->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="division_id" class="block text-xs font-semibold uppercase tracking-wider text-slate-500">
                        Divisi
                        <span x-show="String(selectedRole) === String(staffRoleId)" @if (! $isStaffSelected) x-cloak @endif class="text-rose-600 font-normal lowercase">(wajib untuk Staf Divisi)</span>
                        <span x-show="String(selectedRole) !== String(staffRoleId)" @if ($isStaffSelected) x-cloak @endif class="text-slate-400 font-normal lowercase">(opsional)</span>
                    </label>
                    <select id="division_id" name="division_id" :required="String(selectedRole) === String(staffRoleId)"
                            class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 shadow-2xs focus:border-cyan-600 focus:outline-none focus:ring-1 focus:ring-cyan-600">
                        <option value="" x-text="String(selectedRole) === String(staffRoleId) ? '-- Pilih divisi (wajib) --' : '-- Tanpa divisi --'">{{ $isStaffSelected ? '-- Pilih divisi (wajib) --' : '-- Tanpa divisi --' }}</option>
                        @foreach ($divisions as $division)
                            <option value="{{ $division->id }}" @selected(old('division_id', $user->division_id) == $division->id)>{{ $division->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- Tombol Aksi Form: Elegan & Berbobot --}}
            <div class="mt-3 flex items-center justify-end gap-3 border-t border-slate-100 pt-5">
                <a href="{{ route('users.index') }}" 
                   class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-600 shadow-2xs transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-900">
                    Batal
                </a>
                <button type="submit"
                        class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-800 shadow-2xs transition-all duration-150 hover:border-cyan-500 hover:bg-cyan-50/40 hover:text-cyan-800 active:bg-cyan-100/60">
                    <svg class="h-3.5 w-3.5 text-cyan-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 6L9 17l-5-5"/>
                    </svg>
                    Perbarui Pengguna
                </button>
            </div>
        </form>
    </div>
</div>
@endsection