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
    <h1 class="text-2xl font-bold text-gray-900">Welcome, {{ auth()->user()->name }}</h1>
    <p class="mt-2 text-sm text-gray-600">You are signed in. Dashboard content will be built in later stages.</p>
</div>
@endsection
