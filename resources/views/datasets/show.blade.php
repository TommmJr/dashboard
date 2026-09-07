@extends('layouts.app')

@section('title', 'Dataset: '.$dataset->original_name)

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
    <a href="{{ route('datasets.index') }}" class="text-sm text-indigo-600 hover:underline">&larr; Kembali ke daftar</a>

    <div class="mt-4 rounded-lg bg-white p-6 shadow">
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-xl font-bold text-gray-900">{{ $dataset->original_name }}</h1>
                <dl class="mt-3 grid grid-cols-2 gap-x-8 gap-y-2 text-sm text-gray-600 sm:grid-cols-3">
                    <div><dt class="font-semibold text-gray-800">Divisi</dt><dd>{{ $dataset->division?->name ?? '-' }}</dd></div>
                    <div><dt class="font-semibold text-gray-800">Pengunggah</dt><dd>{{ $dataset->user?->name ?? '-' }}</dd></div>
                    <div><dt class="font-semibold text-gray-800">Ukuran</dt><dd>{{ number_format($dataset->size / 1024, 1) }} KB</dd></div>
                    <div><dt class="font-semibold text-gray-800">MIME</dt><dd>{{ $dataset->mime_type }}</dd></div>
                    <div><dt class="font-semibold text-gray-800">Diunggah</dt><dd>{{ $dataset->created_at->format('d M Y H:i') }}</dd></div>
                    <div>
                        <dt class="font-semibold text-gray-800">Status</dt>
                        <dd><span class="rounded-full px-2 py-1 text-xs font-semibold {{ $statusBadge[$dataset->status] ?? 'bg-gray-200' }}">{{ $dataset->status }}</span></dd>
                    </div>
                </dl>
                @if ($dataset->status === 'FAILED' && $dataset->error_message)
                    <p class="mt-3 rounded-md bg-red-50 p-3 text-sm text-red-700">{{ $dataset->error_message }}</p>
                @endif
            </div>
            <div class="flex gap-2">
                @can('dataset.update')
                    @if ($dataset->status !== 'ARCHIVED')
                        <form method="POST" action="{{ route('datasets.archive', $dataset) }}"
                              onsubmit="return confirm('Arsipkan dataset ini? Data keluar dari chart tetapi tetap tersimpan.');">
                            @csrf @method('PATCH')
                            <button class="rounded bg-yellow-500 px-3 py-2 text-sm font-semibold text-white hover:bg-yellow-600">Arsipkan</button>
                        </form>
                    @endif
                @endcan
                @can('dataset.delete')
                    <form method="POST" action="{{ route('datasets.destroy', $dataset) }}"
                          onsubmit="return confirm('HAPUS PERMANEN dataset ini beserta seluruh baris datanya?');">
                        @csrf @method('DELETE')
                        <button class="rounded bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-700">Hapus Permanen</button>
                    </form>
                @endcan
            </div>
        </div>
    </div>

    <div class="mt-6 rounded-lg bg-white shadow">
        <h2 class="border-b px-6 py-4 text-lg font-semibold text-gray-900">Preview Data ({{ $metrics->total() }} baris)</h2>
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3">Metric</th>
                    <th class="px-4 py-3">Value</th>
                    <th class="px-4 py-3">Period</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($metrics as $metric)
                    <tr>
                        <td class="px-4 py-2">{{ $metric->metric_name }}</td>
                        <td class="px-4 py-2">{{ number_format((float) $metric->value, 2) }}</td>
                        <td class="px-4 py-2">{{ $metric->period->format('Y-m-d') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-6 text-center text-gray-500">Belum ada baris data terimpor.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-6 py-4">{{ $metrics->links() }}</div>
    </div>
</div>
@endsection
