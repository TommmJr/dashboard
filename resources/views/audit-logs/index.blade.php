@extends('layouts.app')

@section('title', 'Audit Log')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8">
    <h1 class="text-2xl font-bold text-primary">Audit Log</h1>
    <p class="mt-1 text-sm text-gray-600">Riwayat perubahan data </p>

    <form method="GET" class="mt-4 flex flex-wrap items-end gap-3 text-sm">
        <div>
            <label class="block text-gray-600" for="action">Aksi</label>
            <select id="action" name="action" class="rounded-md border-gray-300 text-sm">
                <option value="">Semua</option>
                @foreach ($actions as $action)
                    <option value="{{ $action }}" @selected(request('action') === $action)>{{ $action }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-gray-600" for="from">Dari</label>
            <input id="from" type="date" name="from" value="{{ request('from') }}" class="rounded-md border-gray-300 text-sm">
        </div>
        <div>
            <label class="block text-gray-600" for="to">Sampai</label>
            <input id="to" type="date" name="to" value="{{ request('to') }}" class="rounded-md border-gray-300 text-sm">
        </div>
        <button class="rounded bg-primary px-3 py-2 text-sm font-semibold text-white hover:bg-accent">Filter</button>
    </form>

    <div class="mt-6 overflow-x-auto rounded-lg bg-white shadow">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3">Waktu</th>
                    <th class="px-4 py-3">User</th>
                    <th class="px-4 py-3">Aksi</th>
                    <th class="px-4 py-3">Objek</th>
                    <th class="px-4 py-3">Perubahan</th>
                    <th class="px-4 py-3">IP</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($logs as $log)
                    <tr>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $log->created_at->format('d M Y H:i:s') }}</td>
                        <td class="px-4 py-2">{{ $log->user?->name ?? '-' }}</td>
                        <td class="px-4 py-2">
                            <span class="rounded-full bg-surface px-2 py-0.5 text-xs font-semibold text-primary">{{ $log->action }}</span>
                        </td>
                        <td class="px-4 py-2">
                            @if ($log->auditable_type)
                                {{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}
                            @else
                                -
                            @endif
                        </td>
                        <td class="px-4 py-2 max-w-md text-xs text-gray-600">
                            @php
                                $sensitiveKeys = ['password', 'remember_token', 'api_token', 'api_key'];
                                $displayValues = collect($log->new_values ?? $log->old_values ?? [])
                                    ->except($sensitiveKeys);
                                $isOld = empty($log->new_values) && ! empty($log->old_values);
                            @endphp
                            @if ($displayValues->isNotEmpty())
                                <pre class="overflow-auto max-h-20 whitespace-pre-wrap {{ $isOld ? 'text-red-600' : '' }}">{{ $displayValues->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            @else
                                -
                            @endif
                        </td>
                        <td class="px-4 py-2 text-xs text-gray-500">{{ $log->ip ?? '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">Belum ada entri audit.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $logs->links() }}</div>
</div>
@endsection
