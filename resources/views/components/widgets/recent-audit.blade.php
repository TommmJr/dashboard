@props(['widget' => [], 'data' => null])

{{-- Widget audit terbaru (server-side): 5 entri terakhir dari AuditLog. --}}
@php
    $audits = $data ?? collect();
@endphp

<div class="ibs-card p-5">
    <div class="flex items-center justify-between gap-4">
        <div class="flex items-center gap-2.5">
            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[#e6f4f6] text-[#0b6175]">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M4 12a8 8 0 1 0 2.3-5.7M4 4v5h5" stroke-linecap="round" stroke-linejoin="round" />
                    <path d="M12 8v4l2.5 1.5" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </span>
            <h3 class="ibs-section-title">Audit Terbaru</h3>
        </div>
        <a href="{{ route('audit-logs.index') }}"
           class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-[#0b6175] transition hover:border-[#16a3b8] hover:bg-[#e6f4f6]/50">
            Lihat Semua
        </a>
    </div>

    <div class="mt-4 divide-y divide-slate-100">
        @forelse ($audits as $log)
            @php
                $rawName = App\Services\AuditLogFormatter::userName($log);
                $name = $rawName === '-' ? 'Tidak tercatat' : $rawName;
                $initials = mb_strtoupper(mb_substr(trim($name), 0, 1)) ?: 'S';
            @endphp
            <div class="group flex items-start gap-3 rounded-lg px-2 py-3 -mx-2 transition-colors hover:bg-slate-50/70 first:pt-2 last:pb-1">
                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#e6f4f6] text-xs font-bold text-[#0b6175]" aria-hidden="true">
                    {{ $initials }}
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-slate-800 group-hover:text-[#0b6175] transition-colors">{{ $name }}</p>
                    <div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
                        <span class="inline-flex items-center gap-1.5 rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-700">
                            <span class="h-1.5 w-1.5 rounded-full bg-[#0b6175]" aria-hidden="true"></span>
                            {{ App\Services\AuditLogFormatter::actionLabel($log->action) }}
                        </span>
                        <span class="truncate">{{ App\Services\AuditLogFormatter::objectLabel($log) }}</span>
                    </div>
                </div>
                <time class="shrink-0 pt-0.5 text-xs tabular-nums text-slate-500" datetime="{{ $log->created_at?->toIso8601String() }}">
                    {{ $log->created_at?->diffForHumans() ?? 'Tidak tercatat' }}
                </time>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-slate-200 bg-slate-50/60 py-6 text-center text-sm text-slate-500">
                Belum ada entri audit.
            </div>
        @endforelse
    </div>
</div>
