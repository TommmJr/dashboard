@extends('layouts.app')

@section('title', 'Tambah User')

@section('content')
<div class="mx-auto max-w-2xl px-4 py-8">
    <a href="{{ route('users.index') }}" class="text-sm text-indigo-600 hover:underline">&larr; Kembali ke daftar</a>

    <div class="mt-4 rounded-lg bg-white p-6 shadow">
        <h1 class="text-xl font-bold text-gray-900">Tambah User Baru</h1>

        @if ($errors->any())
            <div class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('users.store') }}" class="mt-5 flex flex-col gap-4">
            @csrf

            <div>
                <label for="name" class="block text-sm font-medium text-gray-700">Nama</label>
                <input id="name" type="text" name="name" value="{{ old('name') }}" required maxlength="255"
                       class="mt-1 block w-full rounded-md border-gray-300 text-sm">
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required maxlength="255"
                       class="mt-1 block w-full rounded-md border-gray-300 text-sm">
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                <input id="password" type="password" name="password" required minlength="8"
                       class="mt-1 block w-full rounded-md border-gray-300 text-sm">
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-gray-700">Konfirmasi Password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required minlength="8"
                       class="mt-1 block w-full rounded-md border-gray-300 text-sm">
            </div>

            <div>
                <label for="role_id" class="block text-sm font-medium text-gray-700">Role</label>
                <select id="role_id" name="role_id" required
                        class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                    <option value="">-- Pilih role --</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role->id }}" @selected(old('role_id') == $role->id)>{{ $role->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="division_id" class="block text-sm font-medium text-gray-700">Divisi <span class="text-gray-400">(opsional)</span></label>
                <select id="division_id" name="division_id"
                        class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                    <option value="">-- Tanpa divisi --</option>
                    @foreach ($divisions as $division)
                        <option value="{{ $division->id }}" @selected(old('division_id') == $division->id)>{{ $division->name }}</option>
                    @endforeach
                </select>
            </div>

            <button type="submit"
                    class="rounded bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                Simpan
            </button>
        </form>
    </div>
</div>
@endsection
