@props(['widget' => [], 'data' => null])

{{-- Widget audit terbaru (server-side): 5 entri terakhir dari AuditLog. --}}
@php
    $audits = $data ?? collect();
@endphp

<div class="mt-8 rounded-lg bg-white p-6 shadow">
    <div class="flex items-center justify-between">
        <h3 class="text-lg font-bold text-primary">{{ $widget['label'] ?? 'Audit Terbaru' }}</h3>
        <a href="{{ route('audit-logs.index') }}" class="text-sm text-accent hover:underline">Lihat semua</a>
    </div>

    <div class="mt-4 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3">Waktu</th>
                    <th class="px-4 py-3">User</th>
                    <th class="px-4 py-3">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($audits as $log)
                    <tr>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $log->created_at?->diffForHumans() ?? '-' }}</td>
                        <td class="px-4 py-2">{{ $log->user?->name ?? '-' }}</td>
                        <td class="px-4 py-2">
                            <span class="rounded-full bg-surface px-2 py-0.5 text-xs font-semibold text-primary">{{ $log->action }}</span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-6 text-center text-gray-500">Belum ada entri audit.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
