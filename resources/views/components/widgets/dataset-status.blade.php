@props(['widget' => [], 'data' => null])

@php
    $d = is_array($data) ? $data : [];
    $latestPeriod = $d['latest_period'] ?? null;
    $ready = (int) ($d['ready'] ?? 0);
    $archived = (int) ($d['archived'] ?? 0);
    $failed = (int) ($d['failed'] ?? 0);
    $latestRows = (int) ($d['latest_rows'] ?? 0);
    $fresh = (bool) ($d['fresh'] ?? false);
@endphp

<div class="flex h-full flex-col justify-between rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
    <div>
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-bold text-primary">{{ $widget['label'] ?? 'Status Dataset' }}</h3>
            @if ($latestPeriod !== null)
                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $fresh ? 'border border-emerald-200 bg-emerald-50 text-emerald-700' : 'border border-amber-200 bg-amber-50 text-amber-700' }}">
                    <span class="h-1.5 w-1.5 rounded-full {{ $fresh ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
                    {{ $fresh ? 'Segar' : 'Sudah Lama' }}
                </span>
            @endif
        </div>

        @if ($latestPeriod === null)
            <p class="mt-4 rounded-lg bg-slate-50 p-5 text-sm text-gray-600">Belum ada data KPI aktif.</p>
        @else
            <div class="mt-4 grid grid-cols-2 gap-2.5">
                <div class="rounded-lg border border-emerald-100 bg-emerald-50/50 p-3">
                    <p class="text-xs font-semibold text-emerald-800">Dataset READY (Divisi)</p>
                    <p class="mt-1 text-2xl font-extrabold text-emerald-700">{{ $ready }}</p>
                </div>
                <div class="rounded-lg border border-slate-100 bg-slate-50 p-3">
                    <p class="text-xs font-semibold text-slate-600">Dataset ARCHIVED</p>
                    <p class="mt-1 text-2xl font-extrabold text-slate-700">{{ $archived }}</p>
                </div>
                <div class="rounded-lg border border-rose-100 bg-rose-50/50 p-3">
                    <p class="text-xs font-semibold text-rose-800">Dataset GAGAL</p>
                    <p class="mt-1 text-2xl font-extrabold text-rose-700">{{ $failed }}</p>
                </div>
                <div class="rounded-lg border border-blue-100 bg-blue-50/50 p-3">
                    <p class="text-xs font-semibold text-blue-800">Baris Data Aktif (TJBT)</p>
                    <p class="mt-1 text-2xl font-extrabold text-blue-700">{{ $latestRows }}</p>
                </div>
            </div>
        @endif
    </div>

    @if ($latestPeriod !== null)
        <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-3 text-xs text-slate-500">
            <span>Periode data terbaru: {{ $latestPeriod }}</span>
            <a href="{{ route('datasets.index') }}" class="flex items-center gap-1 font-semibold text-accent transition hover:text-primary">
                Kelola Dataset &rarr;
            </a>
        </div>
    @endif
</div>
