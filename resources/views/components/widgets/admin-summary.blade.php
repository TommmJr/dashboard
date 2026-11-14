@props(['widget' => [], 'data' => null])

@php
    $summary = $data ?? [
        'total_datasets' => 0,
        'failed_datasets' => 0,
        'audit_today' => 0,
        'total_users' => 0,
    ];
    $totalDatasets = (int) $summary['total_datasets'];
    $failedDatasets = (int) $summary['failed_datasets'];
    $auditToday = (int) $summary['audit_today'];
    $totalUsers = (int) $summary['total_users'];

    $fmtTotalDatasets = number_format($totalDatasets, 0, ',', '.');
    $fmtFailedDatasets = number_format($failedDatasets, 0, ',', '.');
    $fmtAuditToday = number_format($auditToday, 0, ',', '.');
    $fmtTotalUsers = number_format($totalUsers, 0, ',', '.');
@endphp

<div class="ibs-card overflow-hidden">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 divide-y sm:divide-y-0 sm:divide-x divide-slate-100">
        {{-- Card 1: Total Dataset --}}
        <div class="p-4 sm:p-5">
            <div class="flex items-center justify-between gap-2">
                <p class="ibs-metric-label uppercase tracking-wider">Total Dataset</p>
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[#e6f4f6] text-[#0b6175]">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <ellipse cx="12" cy="5" rx="9" ry="3"/>
                        <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>
                        <path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"/>
                    </svg>
                </div>
            </div>
            <p class="ibs-metric-value mt-3 text-2xl sm:text-3xl leading-none">
                <span x-data="numberTicker('{{ $fmtTotalDatasets }}')" x-text="display">{{ $fmtTotalDatasets }}</span>
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-500">
                <span class="inline-flex items-center gap-1.5 rounded-md bg-slate-100 px-2 py-0.5 font-semibold text-slate-700">
                    <span class="h-1.5 w-1.5 rounded-full bg-[#0b6175]" aria-hidden="true"></span>
                    Dalam Sistem
                </span>
            </div>
        </div>

        {{-- Card 2: Dataset Gagal --}}
        <div class="p-4 sm:p-5">
            <div class="flex items-center justify-between gap-2">
                <p class="ibs-metric-label uppercase tracking-wider">Dataset Gagal</p>
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[#e6f4f6] text-[#0b6175]">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M12 9v4m0 4h.01M10.3 4.6 3.1 17a2 2 0 0 0 1.7 3h14.4a2 2 0 0 0 1.7-3L13.7 4.6a2 2 0 0 0-3.4 0Z" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </div>
            </div>
            <p class="ibs-metric-value mt-3 text-2xl sm:text-3xl leading-none {{ $failedDatasets > 0 ? 'text-rose-600' : '' }}">
                <span x-data="numberTicker('{{ $fmtFailedDatasets }}')" x-text="display">{{ $fmtFailedDatasets }}</span>
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-500">
                <span class="inline-flex items-center gap-1.5 rounded-md bg-slate-100 px-2 py-0.5 font-semibold {{ $failedDatasets > 0 ? 'text-rose-700' : 'text-slate-700' }}">
                    <span class="h-1.5 w-1.5 rounded-full {{ $failedDatasets > 0 ? 'bg-rose-500' : 'bg-emerald-500' }}" aria-hidden="true"></span>
                    {{ $failedDatasets > 0 ? 'Perlu Tindak Lanjut' : 'Semua Bersih' }}
                </span>
            </div>
        </div>

        {{-- Card 3: Aktivitas Audit Hari Ini --}}
        <div class="p-4 sm:p-5">
            <div class="flex items-center justify-between gap-2">
                <p class="ibs-metric-label uppercase tracking-wider">Aktivitas Audit Hari Ini</p>
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[#e6f4f6] text-[#0b6175]">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M4 12a8 8 0 1 0 2.3-5.7M4 4v5h5" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M12 8v4l2.5 1.5" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </div>
            </div>
            <p class="ibs-metric-value mt-3 text-2xl sm:text-3xl leading-none">
                <span x-data="numberTicker('{{ $fmtAuditToday }}')" x-text="display">{{ $fmtAuditToday }}</span>
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-500">
                <span class="inline-flex items-center gap-1.5 rounded-md bg-slate-100 px-2 py-0.5 font-semibold text-slate-700">
                    <span class="h-1.5 w-1.5 rounded-full bg-[#16a3b8]" aria-hidden="true"></span>
                    Tercatat Hari Ini
                </span>
            </div>
        </div>

        {{-- Card 4: Total Pengguna --}}
        <div class="p-4 sm:p-5">
            <div class="flex items-center justify-between gap-2">
                <p class="ibs-metric-label uppercase tracking-wider">Total Pengguna</p>
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[#e6f4f6] text-[#0b6175]">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </div>
            </div>
            <p class="ibs-metric-value mt-3 text-2xl sm:text-3xl leading-none">
                <span x-data="numberTicker('{{ $fmtTotalUsers }}')" x-text="display">{{ $fmtTotalUsers }}</span>
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-500">
                <span class="inline-flex items-center gap-1.5 rounded-md bg-slate-100 px-2 py-0.5 font-semibold text-slate-700">
                    <span class="h-1.5 w-1.5 rounded-full bg-[#0b6175]" aria-hidden="true"></span>
                    Akun Terdaftar
                </span>
            </div>
        </div>
    </div>
</div>
