@props(['widget' => [], 'data' => null])

{{-- Widget audit terbaru (server-side): 5 entri terakhir dari AuditLog. --}}
@php
    $audits = $data ?? collect();
@endphp

<div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="flex items-center justify-between gap-4">
        <h3 class="text-lg font-bold text-primary">Audit Terbaru</h3>
        <a href="{{ route('audit-logs.index') }}" class="text-sm font-semibold text-accent hover:underline">Lihat Semua</a>
    </div>

    <div class="mt-5 divide-y divide-slate-200">
        @forelse ($audits as $log)
            @php
                $name = $log->user?->name ?? 'Pengguna telah dihapus';
                $initials = mb_strtoupper(mb_substr(trim($name), 0, 1));
            @endphp
            <div class="flex items-start gap-3 py-4 first:pt-0 last:pb-0">
                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-bold text-primary" aria-hidden="true">
                    {{ $initials }}
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-primary">{{ $name }}</p>
                    <p class="mt-0.5 truncate text-xs text-slate-500">
                        {{ App\Services\AuditLogFormatter::actionLabel($log->action) }}
                        &middot; {{ App\Services\AuditLogFormatter::objectLabel($log) }}
                    </p>
                </div>
                <time class="shrink-0 pt-0.5 text-xs text-slate-500" datetime="{{ $log->created_at?->toIso8601String() }}">
                    {{ $log->created_at?->diffForHumans() ?? '-' }}
                </time>
            </div>
        @empty
            <p class="py-6 text-center text-sm text-slate-500">Belum ada entri audit.</p>
        @endforelse
    </div>

    <a href="{{ route('audit-logs.index') }}" class="mt-5 inline-flex items-center justify-center gap-1 text-sm font-semibold text-accent hover:underline">
        Lihat Semua Audit Log
        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M5 12h14m-6-6 6 6-6 6" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    </a>
</div>
