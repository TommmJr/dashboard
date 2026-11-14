@props(['widget' => [], 'data' => null])

{{-- Widget dataset gagal (server-side): jumlah dan detail terbaru. --}}
@php
    $count = (int) ($data['count'] ?? 0);
    $rows = $data['rows'] ?? collect();
    $countFormatted = number_format($count, 0, ',', '.');
@endphp

<div class="ibs-card p-5">
    <div class="flex items-center justify-between gap-4">
        <div class="flex items-center gap-2.5">
            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[#e6f4f6] text-[#0b6175]">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M12 9v4m0 4h.01M10.3 4.6 3.1 17a2 2 0 0 0 1.7 3h14.4a2 2 0 0 0 1.7-3L13.7 4.6a2 2 0 0 0-3.4 0Z" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </span>
            <h3 class="ibs-section-title">Dataset Gagal</h3>
        </div>
        <a href="{{ route('datasets.index') }}"
           class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-[#0b6175] transition hover:border-[#16a3b8] hover:bg-[#e6f4f6]/50">
            Kelola Dataset
        </a>
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-3">
        <p class="ibs-metric-value text-3xl sm:text-4xl leading-none {{ $count > 0 ? 'text-rose-600' : '' }}">
            <span x-data="numberTicker('{{ $countFormatted }}')" x-text="display">{{ $countFormatted }}</span>
        </p>
        @if ($count > 0)
            <span class="inline-flex items-center gap-1.5 rounded-md bg-slate-100 px-2.5 py-1 text-xs font-semibold text-rose-700">
                <span class="h-1.5 w-1.5 rounded-full bg-rose-500" aria-hidden="true"></span>
                Perlu Tindak Lanjut
            </span>
        @else
            <span class="inline-flex items-center gap-1.5 rounded-md bg-slate-100 px-2.5 py-1 text-xs font-semibold text-emerald-700">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                Semua Bersih
            </span>
        @endif
    </div>

    <p class="mt-2 text-xs text-slate-600 tabular-nums">
        {{ $count > 0
            ? "{$count} dataset gagal diproses dan memerlukan peninjauan segera."
            : 'Tidak ada dataset yang gagal diproses.' }}
    </p>

    @if ($rows->isNotEmpty())
        <div class="mt-4 divide-y divide-slate-100 border-t border-slate-100">
            @foreach ($rows as $dataset)
                <a href="{{ route('datasets.show', $dataset) }}" class="group block py-3.5 first:pt-4 last:pb-1">
                    <div class="flex items-start justify-between gap-3">
                        <p class="min-w-0 truncate text-sm font-semibold text-slate-800 group-hover:text-[#0b6175] transition-colors">{{ $dataset->original_name }}</p>
                        <x-dataset-status-badge :status="$dataset->status->value" />
                    </div>
                    <div class="mt-1.5 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500 tabular-nums">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="inline-flex items-center gap-1.5 rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-700">
                                <span class="h-1.5 w-1.5 rounded-full bg-slate-400" aria-hidden="true"></span>
                                {{ $dataset->division?->name ?? 'Tanpa divisi' }}
                            </span>
                            <span>{{ $dataset->created_at?->format('d M Y') ?? 'Tidak tercatat' }}</span>
                        </div>
                        <span class="inline-flex items-center gap-1 font-semibold text-[#0b6175] group-hover:text-[#16a3b8]">
                            Lihat Detail
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" />
                                <circle cx="12" cy="12" r="2.5" />
                            </svg>
                        </span>
                    </div>
                </a>
            @endforeach
        </div>
    @else
        <div class="mt-4 rounded-xl border border-dashed border-slate-200 bg-slate-50/60 p-4 text-center text-sm text-slate-500">
            Belum ada detail dataset gagal.
        </div>
    @endif
</div>
