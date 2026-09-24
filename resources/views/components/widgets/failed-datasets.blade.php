@props(['widget' => [], 'data' => null])

{{-- Widget dataset gagal (server-side): jumlah dan detail terbaru. --}}
@php
    $count = (int) ($data['count'] ?? 0);
    $rows = $data['rows'] ?? collect();
@endphp

<div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="flex items-center justify-between gap-4">
        <h3 class="text-lg font-bold text-primary">Dataset Gagal</h3>
        <a href="{{ route('datasets.index') }}" class="text-sm font-semibold text-accent hover:underline">Kelola Dataset</a>
    </div>

    <div class="mt-5 flex items-center gap-3">
        <p class="text-4xl font-bold text-red-600">{{ number_format($count) }}</p>
        @if ($count > 0)
            <span class="rounded-full bg-red-50 px-3 py-1 text-xs font-semibold text-red-700">Perlu Tindak Lanjut</span>
        @else
            <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">Semua Bersih</span>
        @endif
    </div>

    <p class="mt-2 text-sm leading-6 text-slate-600">
        {{ $count > 0
            ? "{$count} dataset gagal diproses dan memerlukan peninjauan segera."
            : 'Tidak ada dataset yang gagal diproses.' }}
    </p>

    @if ($rows->isNotEmpty())
        <div class="mt-6 divide-y divide-slate-200 border-t border-slate-200">
            @foreach ($rows as $dataset)
                <a href="{{ route('datasets.show', $dataset) }}" class="group block py-4 first:pt-5">
                    <div class="flex items-start justify-between gap-3">
                        <p class="min-w-0 truncate text-sm font-semibold text-primary group-hover:text-accent">{{ $dataset->original_name }}</p>
                        <x-dataset-status-badge :status="$dataset->status->value" />
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                        {{ $dataset->division?->name ?? 'Tanpa divisi' }} &middot; {{ $dataset->created_at?->format('d M Y') }}
                    </p>
                    <span class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-accent">
                        Lihat Detail
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" />
                            <circle cx="12" cy="12" r="2.5" />
                        </svg>
                    </span>
                </a>
            @endforeach
        </div>
    @else
        <div class="mt-6 rounded-lg bg-slate-50 p-4 text-sm text-slate-500">
            Belum ada detail dataset gagal.
        </div>
    @endif
</div>
