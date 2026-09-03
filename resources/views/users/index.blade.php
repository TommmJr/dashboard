{{--
    Halaman daftar user (hanya role dengan permission user.view).
    Frontend hanya menyembunyikan; keamanan tetap di middleware 'can:user.view'.
--}}
@extends('layouts.app')

@section('title', 'Users')

@section('content')
<nav class="bg-white shadow">
    <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3">
        <div class="flex items-center gap-4">
            <a href="{{ route('dashboard') }}" class="font-semibold text-gray-900 hover:text-gray-600">Dashboard</a>
            <span class="font-semibold text-gray-900">Users</span>
        </div>
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
    <h1 class="text-2xl font-bold text-gray-900">Users</h1>
    <p class="mt-2 mb-6 text-sm text-gray-600">Daftar semua pengguna aplikasi beserta perannya.</p>

    <div class="overflow-hidden rounded-lg bg-white shadow">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Email</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Role</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($users as $user)
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $user->name }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">{{ $user->email }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            @if ($user->role)
                                <span class="inline-flex rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800">{{ $user->role->name }}</span>
                            @else
                                <span class="text-xs italic text-gray-400">No role</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-6 py-4 text-center text-sm text-gray-500">Belum ada user terdaftar.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
