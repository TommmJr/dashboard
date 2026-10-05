@props(['widget' => [], 'data' => null])

{{-- Widget status dataset (server-side).
     Data dihitung DashboardController::datasetStatus(). --}}
@php
    $d = is_array($data) ? $data : [];
    $latestPeriod = $d['latest_period'] ?? null;
    $ready = (int) ($d['ready'] ?? 0);
    $archived = (int) ($d['archived'] ?? 0);
    $failed = (int) ($d['failed'] ?? 0);
    $latestRows = (int) ($d['latest_rows'] ?? 0);
    $fresh = (bool) ($d['fresh'] ?? false);
@endphp

<div class="rounded-lg bg-white border border-slate-200 shadow-sm p-6">
    <h3 class="text-lg font-bold text-primary">{{ $widget['label'] ?? 'Status Dataset' }}</h3>

    @if ($latestPeriod === null)
        <p class="mt-4 rounded-lg bg-slate-50 p-5 text-sm text-gray-600">Belum ada data KPI aktif.</p>
    @else
        <div class="mt-4 grid grid-cols-2 gap-4">
            <div>
                <p class="text-sm text-slate-500">Dataset READY</p>
                <p class="text-2xl font-bold text-accent">{{ $ready }}</p>
            </div>
            <div>
                <p class="text-sm text-slate-500">Dataset ARCHIVED</p>
                <p class="text-2xl font-bold text-slate-600">{{ $archived }}</p>
            </div>
            <div>
                <p class="text-sm text-slate-500">Dataset GAGAL</p>
                <p class="text-2xl font-bold text-red-600">{{ $failed }}</p>
            </div>
            <div>
                <p class="text-sm text-slate-500">Baris Aktif</p>
                <p class="text-2xl font-bold text-primary">{{ $latestRows }}</p>
            </div>
        </div>

        <p class="mt-4 text-sm text-slate-600">
            Periode data terbaru: {{ $latestPeriod ?? '—' }}
            <span class="ml-2 inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $fresh ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                {{ $fresh ? 'Segar' : 'Sudah Lama' }}
            </span>
        </p>
    @endif
</div>
