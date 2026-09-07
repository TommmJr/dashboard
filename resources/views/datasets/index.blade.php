@extends('layouts.app')

@section('title', 'Datasets')

@php
    $statusBadge = [
        'UPLOADING' => 'bg-gray-200 text-gray-700',
        'VALIDATING' => 'bg-yellow-100 text-yellow-800',
        'READY' => 'bg-green-100 text-green-800',
        'FAILED' => 'bg-red-100 text-red-800',
        'ARCHIVED' => 'bg-gray-400 text-white',
    ];
@endphp

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-900">Datasets</h1>
        <form method="GET" class="flex items-center gap-2 text-sm">
            <label for="status" class="text-gray-600">Status</label>
            <select id="status" name="status" onchange="this.form.submit()"
                    class="rounded-md border-gray-300 text-sm">
                <option value="">Aktif (non-arsip)</option>
                @foreach (['UPLOADING', 'VALIDATING', 'READY', 'FAILED', 'ARCHIVED'] as $s)
                    <option value="{{ $s }}" @selected(request('status') === $s)>{{ $s }}</option>
                @endforeach
            </select>
        </form>
    </div>

    @if (session('success'))
        <div class="mt-4 rounded-md bg-green-50 p-3 text-sm text-green-700">{{ session('success') }}</div>
    @endif

    <div class="mt-6 overflow-x-auto rounded-lg bg-white shadow">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3">Nama File</th>
                    <th class="px-4 py-3">Divisi</th>
                    <th class="px-4 py-3">Pengunggah</th>
                    <th class="px-4 py-3">Ukuran</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Tanggal</th>
                    <th class="px-4 py-3">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($datasets as $dataset)
                    <tr>
                        <td class="px-4 py-3">
                            <a href="{{ route('datasets.show', $dataset) }}" class="font-medium text-indigo-600 hover:underline">
                                {{ $dataset->original_name }}
                            </a>
                        </td>
                        <td class="px-4 py-3">{{ $dataset->division?->name ?? '-' }}</td>
                        <td class="px-4 py-3">{{ $dataset->user?->name ?? '-' }}</td>
                        <td class="px-4 py-3">{{ number_format($dataset->size / 1024, 1) }} KB</td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-1 text-xs font-semibold {{ $statusBadge[$dataset->status] ?? 'bg-gray-200' }}">
                                {{ $dataset->status }}
                            </span>
                        </td>
                        <td class="px-4 py-3">{{ $dataset->created_at->format('d M Y H:i') }}</td>
                        <td class="px-4 py-3">
                            <div class="flex gap-2">
                                @can('dataset.update')
                                    @if ($dataset->status !== 'ARCHIVED')
                                        <form method="POST" action="{{ route('datasets.archive', $dataset) }}"
                                              onsubmit="return confirm('Arsipkan dataset ini? Data keluar dari chart tetapi tetap tersimpan.');">
                                            @csrf @method('PATCH')
                                            <button class="rounded bg-yellow-500 px-2 py-1 text-xs font-semibold text-white hover:bg-yellow-600">
                                                Arsipkan
                                            </button>
                                        </form>
                                    @endif
                                @endcan
                                @can('dataset.delete')
                                    <form method="POST" action="{{ route('datasets.destroy', $dataset) }}"
                                          onsubmit="return confirm('HAPUS PERMANEN dataset ini beserta seluruh baris datanya? Tindakan ini tidak bisa dibatalkan.');">
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
                    <tr><td colspan="7" class="px-4 py-6 text-center text-gray-500">Belum ada dataset.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $datasets->links() }}</div>
</div>
@endsection
