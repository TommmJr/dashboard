{{--
    Halaman daftar divisi (hanya admin via permission user.view).
    Menampilkan jumlah user dan dataset per divisi untuk konteks sebelum hapus.
--}}
@extends('layouts.app')

@section('title', 'Divisi')

@section('content')
<div class="mx-auto max-w-7xl">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-primary">Divisi</h1>
            <p class="mt-1 text-sm text-gray-600">Daftar divisi yang terdaftar dalam sistem.</p>
        </div>
        @can('user.create')
            <a href="{{ route('divisions.create') }}"
               class="rounded bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-700">
                + Tambah Divisi
            </a>
        @endcan
    </div>

    @if (session('success'))
        <div class="mt-4 rounded-md bg-green-50 p-3 text-sm text-green-700">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    <div class="mt-6 overflow-hidden rounded-lg bg-white shadow">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Nama</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Slug</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Deskripsi</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Users</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Datasets</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($divisions as $division)
                    <tr>
                        <td class="whitespace-nowrap px-6 py-4 font-medium text-gray-900">{{ $division->name }}</td>
                        <td class="whitespace-nowrap px-6 py-4 text-gray-500 font-mono text-xs">{{ $division->slug }}</td>
                        <td class="px-6 py-4 text-gray-600 max-w-xs truncate">{{ $division->description ?? '-' }}</td>
                        <td class="whitespace-nowrap px-6 py-4 text-gray-600">{{ $division->users_count }}</td>
                        <td class="whitespace-nowrap px-6 py-4 text-gray-600">{{ $division->datasets_count }}</td>
                        <td class="whitespace-nowrap px-6 py-4">
                            <div class="flex gap-2">
                                @can('user.update')
                                    <a href="{{ route('divisions.edit', $division) }}"
                                       class="rounded bg-yellow-500 px-2 py-1 text-xs font-semibold text-white hover:bg-yellow-600">
                                        Edit
                                    </a>
                                @endcan
                                @can('user.delete')
                                    <form method="POST" action="{{ route('divisions.destroy', $division) }}"
                                          onsubmit="return confirm('Hapus divisi \"{{ addslashes($division->name) }}\"?');">
                                        @csrf @method('DELETE')
                                        <button class="rounded bg-red-600 px-2 py-1 text-xs font-semibold text-white hover:bg-red-700">
                                            Hapus
                                        </button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">Belum ada divisi terdaftar.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
