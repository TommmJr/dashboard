{{--
    Halaman Dashboard (halaman utama setelah login).
    - Dilindungi middleware 'auth', jadi hanya user yang login yang bisa lihat.
    - Konten asli (grafik/widget) akan dibangun pada tahap Dashboard Builder.
    - Tombol logout memakai POST + @csrf (aman dari CSRF / clickjacking).
--}}
@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<nav class="bg-white shadow">
    <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3">
        <span class="font-semibold text-gray-900">Dashboard</span>
        <div class="flex items-center gap-4">
            <span class="text-sm text-gray-600">{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-sm text-gray-600 hover:text-gray-900">Log out</button>
            </form>
        </div>
    </div>
</nav>

<div class="mx-auto max-w-7xl px-4 py-8">
    <div class="flex items-center gap-3">
        <h1 class="text-2xl font-bold text-gray-900">Welcome, {{ auth()->user()->name }}</h1>
        @if (auth()->user()->role)
            <span class="inline-flex rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800">{{ auth()->user()->role->name }}</span>
        @endif
    </div>
    <p class="mt-2 text-sm text-gray-600">You are signed in. Dashboard content will be built in later stages.</p>

    {{-- Menu berdasarkan permission. Keamanan tetap divalidasi di backend (middleware/gate);
         @can di sini hanya menyembunyikan menu yang tidak relevan bagi user. --}}
    <div class="mt-6 flex flex-wrap gap-3">
        @can('user.view')
            <a href="{{ route('users.index') }}" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">Manage users</a>
        @endcan
        @can('dashboard.update')
            <span class="rounded-lg bg-gray-200 px-4 py-2 text-sm font-medium text-gray-500">Edit dashboard (menyusul, Tahap 6)</span>
        @endcan
        @can('dataset.import')
            <span class="rounded-lg bg-gray-200 px-4 py-2 text-sm font-medium text-gray-500">Upload data (menyusul, Tahap 4)</span>
        @endcan
    </div>
</div>
@endsection
