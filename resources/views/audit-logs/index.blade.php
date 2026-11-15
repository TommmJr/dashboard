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
            @endif
        </div>

        <form id="audit-filter" method="GET" class="ibs-card flex flex-wrap items-center gap-2 p-2.5">
            <div>
                <label class="sr-only" for="action">Aksi</label>
                <select id="action" name="action" class="h-8 rounded-lg border border-slate-200 bg-white px-3 text-xs font-medium text-slate-700 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    <option value="">Semua aksi</option>
                    @foreach ($actions as $action)
                        <option value="{{ $action }}" @selected(request('action') === $action)>{{ App\Services\AuditLogFormatter::actionLabel($action) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-center gap-1.5">
                <label class="sr-only" for="from">Dari</label>
                <input id="from" type="date" name="from" value="{{ request('from') }}" title="Dari tanggal" class="h-8 rounded-lg border border-slate-200 bg-white px-2.5 text-xs font-sans tabular-nums text-slate-700 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                <span class="text-xs font-medium text-slate-400" aria-hidden="true">s.d.</span>
                <label class="sr-only" for="to">Sampai</label>
                <input id="to" type="date" name="to" value="{{ request('to') }}" title="Sampai tanggal" class="h-8 rounded-lg border border-slate-200 bg-white px-2.5 text-xs font-sans tabular-nums text-slate-700 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
            </div>
            <button type="submit" class="inline-flex h-8 items-center gap-1.5 rounded-lg bg-primary px-3 text-xs font-semibold text-white shadow-2xs transition-all hover:bg-primary/90">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
                </svg>
                Filter
            </button>
            @if(request()->hasAny(['action', 'from', 'to']))
                <a href="{{ route('audit-logs.index') }}" class="inline-flex h-8 items-center rounded-lg border border-slate-200 bg-white px-2.5 text-xs font-medium text-slate-600 shadow-2xs hover:bg-slate-50">
                    Reset
                </a>
            @endif
        </form>
    </div>

    <div class="ibs-card animate-entrance stagger-1 mt-6 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-100 text-xs">
                <thead class="bg-slate-50/80 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">
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
                            $auditUserName = App\Services\AuditLogFormatter::userName($log);
                        @endphp
                        <tr class="transition-colors hover:bg-slate-50/70">
                            <td class="px-4 py-3 align-top whitespace-nowrap font-sans tabular-nums text-slate-500">
                                {{ $log->created_at->format('d M Y H:i:s') }}
                            </td>
                            <td class="px-4 py-3 align-top whitespace-normal break-words leading-relaxed font-medium text-slate-900">
                                {{ $auditUserName === '-' ? 'Tidak tercatat' : $auditUserName }}
                            </td>
                            <td class="px-4 py-3 align-top whitespace-nowrap">
                                <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200/90 bg-white px-2.5 py-1 text-[11px] font-medium {{ $textColor }}" title="{{ $log->action }}">
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
                            <td class="px-4 py-3 align-top whitespace-nowrap font-sans tabular-nums text-slate-500">
                                {{ $log->ip ?: 'Tidak tercatat' }}
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
                                <div class="ibs-card p-4">
                                    <div class="mb-3 flex items-center justify-between border-b border-slate-100 pb-3">
                                        <div class="flex items-center gap-2">
                                            <span class="inline-flex h-1.5 w-1.5 rounded-full {{ $dotColor }}" aria-hidden="true"></span>
                                            <h4 class="text-xs font-bold uppercase tracking-wider text-slate-800">
                                                Detail Metadata Audit #{{ $log->id }}
                                            </h4>
                                        </div>
                                        <span class="font-sans tabular-nums text-[11px] font-medium text-slate-400">ID Entri: #{{ $log->id }}</span>
                                    </div>

                                    <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-xs sm:grid-cols-2 lg:grid-cols-4">
                                        <div class="rounded-lg border border-slate-100 bg-slate-50/50 p-2.5">
                                            <dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">ID Entri</dt>
                                            <dd class="mt-1 font-sans tabular-nums text-xs font-semibold text-slate-800">#{{ $log->id }}</dd>
                                        </div>
                                        <div class="rounded-lg border border-slate-100 bg-slate-50/50 p-2.5">
                                            <dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Kategori Data</dt>
                                            <dd class="mt-1 font-sans tabular-nums text-xs text-slate-700 break-all">
                                                {{ App\Services\AuditLogFormatter::objectLabel($log) }}
                                            </dd>
                                        </div>
                                        <div class="rounded-lg border border-slate-100 bg-slate-50/50 p-2.5">
                                            <dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">IP Address</dt>
                                            <dd class="mt-1 font-sans tabular-nums text-xs text-slate-700">{{ $log->ip ?: 'Tidak tercatat' }}</dd>
                                        </div>
                                        <div class="rounded-lg border border-slate-100 bg-slate-50/50 p-2.5">
                                            <dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Waktu (Lengkap)</dt>
                                            <dd class="mt-1 font-sans tabular-nums text-xs text-slate-700">{{ $log->created_at->format('d/m/Y H:i:s') }}</dd>
                                        </div>
                                        <div class="sm:col-span-2 lg:col-span-4 rounded-lg border border-slate-100 bg-slate-50/50 p-2.5">
                                            <dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">User Agent</dt>
                                            <dd class="mt-1 font-sans text-xs text-slate-700 break-all">{{ $log->user_agent ?: 'Tidak tercatat' }}</dd>
                                        </div>
                                    </dl>

                                    @if (! empty($log->old_values) || ! empty($log->new_values))
                                        <div class="mt-4 border-t border-slate-100 pt-3">
                                            <h5 class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Perubahan Nilai</h5>
                                            <div class="grid grid-cols-1 gap-3 font-sans text-xs md:grid-cols-2">
                                                @if (! empty($log->old_values))
                                                    <div class="rounded-lg border border-rose-100 bg-rose-50/30 p-3">
                                                        <span class="mb-1 block text-[11px] font-semibold uppercase tracking-wider text-rose-700">Nilai Lama (old_values)</span>
                                                        <pre class="whitespace-pre-wrap break-all font-sans tabular-nums text-[11px] leading-relaxed text-rose-900">{{ json_encode($log->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                                    </div>
                                                @endif
                                                @if (! empty($log->new_values))
                                                    <div class="rounded-lg border border-emerald-100 bg-emerald-50/30 p-3 {{ empty($log->old_values) ? 'md:col-span-2' : '' }}">
                                                        <span class="mb-1 block text-[11px] font-semibold uppercase tracking-wider text-emerald-700">Nilai Baru (new_values)</span>
                                                        <pre class="whitespace-pre-wrap break-all font-sans tabular-nums text-[11px] leading-relaxed text-emerald-900">{{ json_encode($log->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
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
                            <td colspan="7" class="px-6 py-10 text-center text-xs text-slate-400">
                                <p>Belum ada entri audit.</p>
                                @if (request()->hasAny(['action', 'from', 'to']))
                                    <a href="{{ route('audit-logs.index') }}" class="mt-2 inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-700 transition hover:bg-slate-50">
                                        Reset Filter
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-5">
        {{ $logs->links() }}
    </div>
</div>
@endsection
