@props(['widget' => [], 'data' => null])

{{-- Widget audit terbaru (server-side): 5 entri terakhir dari AuditLog. --}}
@php
    $audits = $data ?? collect();
@endphp

<div class="card-interactive rounded-xl border border-slate-200/80 bg-white p-6 shadow-[0_1px_3px_0_rgba(0,0,0,0.04)]">
    <div class="flex items-center justify-between gap-4">
        <div class="flex items-center gap-2.5">
            <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-teal-50 text-accent">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M4 12a8 8 0 1 0 2.3-5.7M4 4v5h5" stroke-linecap="round" stroke-linejoin="round" />
                    <path d="M12 8v4l2.5 1.5" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </span>
            <h3 class="text-lg font-bold text-primary">Audit Terbaru</h3>
        </div>
        <a href="{{ route('audit-logs.index') }}" class="text-xs font-semibold text-accent transition hover:text-primary">Lihat Semua</a>
    </div>

    <div class="mt-5 divide-y divide-slate-100">
        @forelse ($audits as $log)
            @php
                $name = App\Services\AuditLogFormatter::userName($log);
                $initials = mb_strtoupper(mb_substr(trim($name), 0, 1));
            @endphp
            <div class="group flex items-start gap-3 py-3.5 first:pt-0 last:pb-0 transition-colors duration-150 hover:bg-slate-50/70 rounded-lg px-2 -mx-2">
                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-bold text-primary ring-2 ring-white" aria-hidden="true">
                    {{ $initials }}
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-primary group-hover:text-accent transition-colors">{{ $name }}</p>
                    <p class="mt-0.5 truncate text-xs text-slate-500">
                        {{ App\Services\AuditLogFormatter::actionLabel($log->action) }}
                        &middot; {{ App\Services\AuditLogFormatter::objectLabel($log) }}
                    </p>
                </div>
                <time class="shrink-0 pt-0.5 text-xs text-slate-400" datetime="{{ $log->created_at?->toIso8601String() }}">
                    {{ $log->created_at?->diffForHumans() ?? '-' }}
                </time>
            </div>
        @empty
            <p class="py-6 text-center text-sm text-slate-500">Belum ada entri audit.</p>
        @endforelse
    </div>

    <a href="{{ route('audit-logs.index') }}" class="mt-5 inline-flex items-center justify-center gap-1.5 text-xs font-semibold text-accent transition hover:text-primary">
        Lihat Semua Audit Log
        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path d="M5 12h14m-6-6 6 6-6 6" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    </a>
</div>
