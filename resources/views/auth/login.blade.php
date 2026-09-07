{{--
    Halaman Login.
    - Form dikirim via POST ke route('login.store').
    - @csrf membuat token CSRF otomatis di dalam form.
    - Alpine (x-data="loading") dipakai untuk tombol "Signing in..."
      saat form sedang di-submit, supaya user tidak klik dua kali.
--}}
@extends('layouts.app')

@section('title', 'Login')

@section('content')
<div class="flex min-h-full flex-col justify-center px-6 py-12 lg:px-8" x-data="{ loading: false }">
    <div class="sm:mx-auto sm:w-full sm:max-w-md">
        <h1 class="text-center text-2xl font-bold tracking-tight text-gray-900">Dashboard</h1>
        <p class="mt-2 text-center text-sm text-gray-600">Sign in to your account</p>
    </div>

    <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
        <div class="bg-white px-6 py-8 shadow sm:rounded-lg">
            <form method="POST" action="{{ route('login.store') }}" @submit="loading = true" class="space-y-6">
                @csrf

                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('email')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                    <input id="password" name="password" type="password" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('password')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-center">
                    <input id="remember" name="remember" type="checkbox"
                           class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                    <label for="remember" class="ml-2 block text-sm text-gray-700">Remember me</label>
                </div>

                <button type="submit" :disabled="loading"
                        class="flex w-full justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50">
                    <span x-show="!loading">Sign in</span>
                    <span x-show="loading" x-cloak>Signing in...</span>
                </button>
            </form>
        </div>
    </div>
</div>

@section('main graph')
<div class="flex justify-center mt-8">
    <input id = "search" name = "search" type = "text" placeholder = "Search..." class="w-1/2 px-4 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500">
    
</div>



<div class="fixed bottom-0 left-0 w-full bg-gray-100 py-2 text-center text-sm text-gray-500">
    &copy; {{ date('Y') }} Dashboard. (c) All rights reserved.
</div>

@endsection
