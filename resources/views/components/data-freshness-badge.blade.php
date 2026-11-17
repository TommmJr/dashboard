@props([
    'freshness' => null,
    'id' => 'data-freshness-indicator',
    'contextLabel' => null,
])

@if (is_array($freshness))
    @php
        $hasDataset = (bool) ($freshness['has_dataset'] ?? false);
        $lastUpdatedLabel = (string) ($freshness['last_updated_label'] ?? 'Belum ada data aktif');
        $lastUpdatedIso = $freshness['last_updated_iso'] ?? null;
        $isStale = (bool) ($freshness['is_stale'] ?? false);
        $staleMessage = $freshness['stale_message'] ?? null;
    @endphp

    <div
        id="{{ $id }}"
        data-freshness-status="{{ ! $hasDataset ? 'empty' : ($isStale ? 'stale' : 'fresh') }}"
        {{ $attributes->merge(['class' => 'flex min-w-0 max-w-full flex-wrap items-center gap-1.5 text-xs']) }}
    >
        @if (is_string($contextLabel) && $contextLabel !== '')
            <span class="inline-flex items-center rounded-md border border-slate-200 bg-white px-2 py-0.5 text-[11px] font-semibold text-slate-700">
                {{ $contextLabel }}
            </span>
        @endif

        <span class="inline-flex min-w-0 max-w-full items-center gap-1.5 rounded-full border border-slate-200/90 bg-slate-50 px-2.5 py-0.5 text-xs font-medium text-slate-600 tabular-nums">
            <svg class="h-3.5 w-3.5 shrink-0 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <circle cx="12" cy="12" r="9" />
                <path d="M12 7.5V12l3 1.75" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            @if ($hasDataset && is_string($lastUpdatedIso) && $lastUpdatedIso !== '')
                <time datetime="{{ $lastUpdatedIso }}" class="break-words sm:whitespace-nowrap">{{ $lastUpdatedLabel }}</time>
            @else
                <span class="break-words sm:whitespace-nowrap">{{ $lastUpdatedLabel }}</span>
            @endif
        </span>

        @if ($isStale && is_string($staleMessage) && $staleMessage !== '')
            <span class="inline-flex min-w-0 max-w-full items-center gap-1.5 rounded-full border border-amber-200 bg-amber-50/90 px-2.5 py-0.5 text-xs font-medium text-amber-800">
                <svg class="h-3.5 w-3.5 shrink-0 text-amber-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" />
                    <path d="M12 8.25v4.5" stroke-linecap="round" />
                    <circle cx="12" cy="15.75" r="0.75" fill="currentColor" stroke="none" />
                </svg>
                <span class="break-words">{{ $staleMessage }}</span>
            </span>
        @endif
    </div>
@endif
