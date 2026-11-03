@extends('layouts.app')

@section('title', 'Audit Log')

@section('content')
<div class="mx-auto max-w-7xl">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-primary">Audit Log</h1>
            @if ($dataOnly ?? false)
                <p class="mt-1 text-xs text-slate-500">
                    Menampilkan perubahan data saja (dataset &amp; baris data kinerja).
                </p>
            @endif
        </div>

        {{-- Fitur Reports dinonaktifkan sementara (2026-09-15), tombol export
             dihapus karena route reports.audit-export di-comment di routes/web.php. --}}
        {{-- @can('report.export')
            <form method="GET" action="{{ route('reports.audit-export') }}" class="mt-3 flex flex-wrap items-end gap-2 text-sm">
                <div>
                    <label class="block text-gray-600" for="export_from">Export dari</label>
                    <input id="export_from" type="date" name="from" value="{{ request('from') }}" class="rounded-md border-gray-300 text-sm" required>
                </div>
                <div>
                    <label class="block text-gray-600" for="export_to">sampai</label>
                    <input id="export_to" type="date" name="to" value="{{ request('to') }}" class="rounded-md border-gray-300 text-sm" required>
                </div>
                <button class="rounded bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700">Export Audit (.xlsx)</button>
            </form>
        @endcan --}}

        <form id="audit-filter" method="GET" class="flex flex-wrap items-center gap-2">
            <div>
                <label class="sr-only" for="action">Aksi</label>
                <select id="action" name="action" class="h-9 rounded border border-slate-200 bg-white px-3 text-sm text-primary focus:border-accent focus:ring-2 focus:ring-accent/20">
                    <option value="">Semua aksi</option>
                    @foreach ($actions as $action)
                        <option value="{{ $action }}" @selected(request('action') === $action)>{{ App\Services\AuditLogFormatter::actionLabel($action) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-center gap-1.5">
                <label class="sr-only" for="from">Dari</label>
                <input id="from" type="date" name="from" value="{{ request('from') }}" title="Dari tanggal" class="h-9 rounded border border-slate-200 bg-white px-3 text-sm text-primary focus:border-accent focus:ring-2 focus:ring-accent/20">
                <span class="text-xs text-slate-400" aria-hidden="true">-</span>
                <label class="sr-only" for="to">Sampai</label>
                <input id="to" type="date" name="to" value="{{ request('to') }}" title="Sampai tanggal" class="h-9 rounded border border-slate-200 bg-white px-3 text-sm text-primary focus:border-accent focus:ring-2 focus:ring-accent/20">
            </div>
            <button class="h-9 rounded bg-primary px-4 text-sm font-semibold text-white hover:bg-accent transition">Filter</button>
        </form>
    </div>

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
                    <th class="px-4 py-3">Detail</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100" x-data="{ open: null }">
                @forelse ($logs as $log)
                    <tr>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $log->created_at->format('d M Y H:i:s') }}</td>
                        <td class="px-4 py-2">{{ $log->user?->name ?? '-' }}</td>
                        <td class="px-4 py-2">
                            <span class="rounded-full bg-surface px-2 py-0.5 text-xs font-semibold text-primary" title="{{ $log->action }}">
                                {{ App\Services\AuditLogFormatter::actionLabel($log->action) }}
                            </span>
                        </td>
                        <td class="px-4 py-2">
                            {{ App\Services\AuditLogFormatter::objectLabel($log) }}
                        </td>
                        <td class="px-4 py-2 max-w-md text-xs">
                            <x-audit-values :log="$log" />
                        </td>
                        <td class="px-4 py-2 text-xs text-gray-500">{{ $log->ip ?? '-' }}</td>
                        <td class="px-4 py-2 text-xs">
                            <button type="button"
                                    class="rounded border border-gray-300 px-2 py-0.5 text-gray-700 hover:bg-surface"
                                    @click="open = open === {{ $log->id }} ? null : {{ $log->id }}">
                                <span x-text="open === {{ $log->id }} ? 'Tutup' : 'Detail'">Detail</span>
                            </button>
                        </td>
                    </tr>
                    <tr x-show="open === {{ $log->id }}" x-cloak class="bg-surface/50">
                        <td colspan="7" class="px-4 py-3">
                            <dl class="grid gap-x-6 gap-y-1 text-xs sm:grid-cols-2">
                                <div>
                                    <dt class="font-semibold text-gray-700">ID entri</dt>
                                    <dd class="text-gray-600">#{{ $log->id }}</dd>
                                </div>
                                <div>
                                    <dt class="font-semibold text-gray-700">Tipe objek (internal)</dt>
                                    <dd class="break-all text-gray-600">{{ $log->auditable_type ?? '-' }}#{{ $log->auditable_id ?? '-' }}</dd>
                                </div>
                                <div>
                                    <dt class="font-semibold text-gray-700">IP</dt>
                                    <dd class="text-gray-600">{{ $log->ip ?? '-' }}</dd>
                                </div>
                                <div>
                                    <dt class="font-semibold text-gray-700">Waktu (lengkap)</dt>
                                    <dd class="text-gray-600">{{ $log->created_at->format('d/m/Y H:i:s') }}</dd>
                                </div>
                                <div class="sm:col-span-2">
                                    <dt class="font-semibold text-gray-700">User agent</dt>
                                    <dd class="break-all text-gray-600">{{ $log->user_agent ?? '-' }}</dd>
                                </div>
                            </dl>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6 text-center text-gray-500">Belum ada entri audit.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $logs->links() }}</div>
</div>
@endsection
