@extends('layouts.app')

@section('title', 'Audit Log')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900">Audit Log</h1>
            @if ($dataOnly ?? false)
                <p class="mt-1 text-xs text-slate-500">
                    Menampilkan perubahan data saja (dataset &amp; baris data kinerja).
                </p>
            @else
                <p class="mt-1 text-xs text-slate-500">
                    Jejak kronologis aktivitas sistem, autentikasi, dan riwayat mutasi data.
                </p>
            @endif
        </div>

        <form id="audit-filter" method="GET" class="flex flex-wrap items-center gap-2 rounded-xl border border-slate-200/80 bg-white p-3.5 shadow-2xs">
            <div>
                <label class="sr-only" for="action">Aksi</label>
                <select id="action" name="action" class="h-9 rounded-lg border border-slate-200 bg-white px-3 text-xs text-slate-700 shadow-2xs focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500">
                    <option value="">Semua aksi</option>
                    @foreach ($actions as $action)
                        <option value="{{ $action }}" @selected(request('action') === $action)>{{ App\Services\AuditLogFormatter::actionLabel($action) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-center gap-1.5">
                <label class="sr-only" for="from">Dari</label>
                <input id="from" type="date" name="from" value="{{ request('from') }}" title="Dari tanggal" class="h-9 rounded-lg border border-slate-200 bg-white px-3 text-xs text-slate-700 shadow-2xs focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500">
                <span class="text-xs text-slate-400" aria-hidden="true">-</span>
                <label class="sr-only" for="to">Sampai</label>
                <input id="to" type="date" name="to" value="{{ request('to') }}" title="Sampai tanggal" class="h-9 rounded-lg border border-slate-200 bg-white px-3 text-xs text-slate-700 shadow-2xs focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500">
            </div>
            <button type="submit" class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-primary px-3.5 text-xs font-semibold text-white shadow-2xs transition-all hover:bg-primary-dark">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
                </svg>
                Filter
            </button>
            @if(request()->hasAny(['action', 'from', 'to']))
                <a href="{{ route('audit-logs.index') }}" class="inline-flex h-9 items-center rounded-lg border border-slate-200 bg-white px-3 text-xs font-medium text-slate-600 shadow-2xs hover:bg-slate-50">
                    Reset
                </a>
            @endif
        </form>
    </div>

    <div class="mt-6 overflow-x-auto rounded-xl border border-slate-200/80 bg-white shadow-2xs">
        <table class="min-w-full divide-y divide-slate-100 text-xs">
            <thead class="bg-slate-50/80 text-left text-2xs font-semibold uppercase tracking-wider text-slate-500">
                <tr>
                    <th class="px-4 py-3">Waktu</th>
                    <th class="px-4 py-3">User</th>
                    <th class="px-4 py-3">Aksi</th>
                    <th class="px-4 py-3">Objek</th>
                    <th class="px-4 py-3">Perubahan</th>
                    <th class="px-4 py-3">IP</th>
                    <th class="px-4 py-3 text-right">Detail</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100" x-data="{ open: null }">
                @forelse ($logs as $log)
                    @php
                        $dotColor = match($log->action) {
                            'create', 'import', 'restore' => 'bg-emerald-500',
                            'update', 'sync', 'login' => 'bg-sky-500',
                            'archive', 'reset', 'logout' => 'bg-amber-500',
                            'delete', 'login_failed' => 'bg-rose-500',
                            default => 'bg-slate-400',
                        };
                        $textColor = match($log->action) {
                            'create', 'import', 'restore' => 'text-emerald-700',
                            'update', 'sync', 'login' => 'text-sky-700',
                            'archive', 'reset', 'logout' => 'text-amber-700',
                            'delete', 'login_failed' => 'text-rose-700',
                            default => 'text-slate-700',
                        };
                    @endphp
                    <tr class="hover:bg-slate-50/60 transition">
                        <td class="px-4 py-3 align-top whitespace-nowrap font-mono text-slate-500">
                            {{ $log->created_at->format('d M Y H:i:s') }}
                        </td>
                        <td class="px-4 py-3 align-top whitespace-normal break-words leading-relaxed font-medium text-slate-900">
                            {{ App\Services\AuditLogFormatter::userName($log) }}
                        </td>
                        <td class="px-4 py-3 align-top whitespace-nowrap">
                            <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200/90 bg-white px-2.5 py-1 text-2xs font-medium {{ $textColor }} shadow-2xs" title="{{ $log->action }}">
                                <span class="h-1.5 w-1.5 rounded-full {{ $dotColor }}" aria-hidden="true"></span>
                                {{ App\Services\AuditLogFormatter::actionLabel($log->action) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 align-top whitespace-normal break-words leading-relaxed font-medium text-slate-800">
                            {{ App\Services\AuditLogFormatter::objectLabel($log) }}
                        </td>
                        <td class="px-4 py-3 align-top whitespace-normal break-words leading-relaxed text-slate-700">
                            <x-audit-values :log="$log" />
                        </td>
                        <td class="px-4 py-3 align-top whitespace-nowrap font-mono text-slate-500">
                            {{ $log->ip ?? '-' }}
                        </td>
                        <td class="px-4 py-3 align-top text-right whitespace-nowrap">
                            <button type="button"
                                    class="inline-flex items-center gap-1 rounded-lg border border-slate-200/90 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 shadow-2xs transition-all hover:border-slate-300 hover:bg-slate-50"
                                    @click="open = open === {{ $log->id }} ? null : {{ $log->id }}">
                                <span x-text="open === {{ $log->id }} ? 'Tutup' : 'Detail'">Detail</span>
                            </button>
                        </td>
                    </tr>
                    <tr x-show="open === {{ $log->id }}" x-cloak class="bg-slate-50/70 border-b border-slate-200/80">
                        <td colspan="7" class="p-4 sm:p-5">
                            <div class="rounded-xl border border-slate-200/90 bg-white p-4 shadow-2xs">
                                <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-3">
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex h-2 w-2 rounded-full {{ $dotColor }}" aria-hidden="true"></span>
                                        <h4 class="text-xs font-bold uppercase tracking-wider text-slate-800">
                                            Detail Metadata Audit #{{ $log->id }}
                                        </h4>
                                    </div>
                                    <span class="text-2xs font-mono text-slate-400">ID Entri: #{{ $log->id }}</span>
                                </div>

                                <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-xs sm:grid-cols-2 lg:grid-cols-4">
                                    <div class="rounded-lg border border-slate-100 bg-slate-50/50 p-2.5">
                                        <dt class="text-2xs font-semibold uppercase tracking-wider text-slate-500">ID Entri</dt>
                                        <dd class="mt-1 font-mono text-xs font-semibold text-slate-800">#{{ $log->id }}</dd>
                                    </div>
                                    <div class="rounded-lg border border-slate-100 bg-slate-50/50 p-2.5">
                                        <dt class="text-2xs font-semibold uppercase tracking-wider text-slate-500">Tipe Objek (Internal)</dt>
                                        <dd class="mt-1 font-mono text-xs text-slate-700 break-all">{{ $log->auditable_type ?? '-' }}#{{ $log->auditable_id ?? '-' }}</dd>
                                    </div>
                                    <div class="rounded-lg border border-slate-100 bg-slate-50/50 p-2.5">
                                        <dt class="text-2xs font-semibold uppercase tracking-wider text-slate-500">IP Address</dt>
                                        <dd class="mt-1 font-mono text-xs text-slate-700">{{ $log->ip ?? '-' }}</dd>
                                    </div>
                                    <div class="rounded-lg border border-slate-100 bg-slate-50/50 p-2.5">
                                        <dt class="text-2xs font-semibold uppercase tracking-wider text-slate-500">Waktu (Lengkap)</dt>
                                        <dd class="mt-1 font-mono text-xs text-slate-700">{{ $log->created_at->format('d/m/Y H:i:s') }}</dd>
                                    </div>
                                    <div class="sm:col-span-2 lg:col-span-4 rounded-lg border border-slate-100 bg-slate-50/50 p-2.5">
                                        <dt class="text-2xs font-semibold uppercase tracking-wider text-slate-500">User Agent</dt>
                                        <dd class="mt-1 font-mono text-xs text-slate-700 break-all">{{ $log->user_agent ?? '-' }}</dd>
                                    </div>
                                </dl>

                                @if (! empty($log->old_values) || ! empty($log->new_values))
                                    <div class="mt-4 pt-3 border-t border-slate-100">
                                        <h5 class="text-2xs font-semibold uppercase tracking-wider text-slate-500 mb-2">Perubahan Nilai</h5>
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 font-mono text-xs">
                                            @if (! empty($log->old_values))
                                                <div class="rounded-lg border border-rose-100 bg-rose-50/30 p-3">
                                                    <span class="text-2xs font-semibold text-rose-700 uppercase tracking-wider block mb-1">Nilai Lama (old_values)</span>
                                                    <pre class="whitespace-pre-wrap break-all text-2xs text-rose-900 leading-relaxed">{{ json_encode($log->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                                </div>
                                            @endif
                                            @if (! empty($log->new_values))
                                                <div class="rounded-lg border border-emerald-100 bg-emerald-50/30 p-3 {{ empty($log->old_values) ? 'md:col-span-2' : '' }}">
                                                    <span class="text-2xs font-semibold text-emerald-700 uppercase tracking-wider block mb-1">Nilai Baru (new_values)</span>
                                                    <pre class="whitespace-pre-wrap break-all text-2xs text-emerald-900 leading-relaxed">{{ json_encode($log->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-6 py-12 text-center text-xs text-slate-400">
                            Belum ada entri audit.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-5">
        {{ $logs->links() }}
    </div>
</div>
@endsection
