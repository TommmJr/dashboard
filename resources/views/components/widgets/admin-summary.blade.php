@props(['widget' => [], 'data' => null])

@php
    $summary = $data ?? [
        'total_datasets' => 0,
        'failed_datasets' => 0,
        'audit_today' => 0,
        'total_users' => 0,
    ];
    $fmtTotalDatasets = number_format((int) $summary['total_datasets'], 0, ',', '.');
    $fmtFailedDatasets = number_format((int) $summary['failed_datasets'], 0, ',', '.');
    $fmtAuditToday = number_format((int) $summary['audit_today'], 0, ',', '.');
    $fmtTotalUsers = number_format((int) $summary['total_users'], 0, ',', '.');
@endphp

<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <div class="card-interactive rounded-xl border border-slate-200/80 bg-white p-5 shadow-[0_1px_3px_0_rgba(0,0,0,0.04)]">
        <div class="flex items-start justify-between gap-4">
            <p class="text-sm font-medium text-slate-600">Total Dataset</p>
            <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-teal-50 text-accent">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M4 7c0-1.1 3.6-2 8-2s8 .9 8 2-3.6 2-8 2-8-.9-8-2Z" stroke-linecap="round" stroke-linejoin="round" />
                    <path d="M4 7v5c0 1.1 3.6 2 8 2s8-.9 8-2V7M4 12v5c0 1.1 3.6 2 8 2s8-.9 8-2v-5" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </span>
        </div>
        <p class="mt-4 text-3xl font-extrabold tracking-tight text-primary">
            <span x-data="numberTicker('{{ $fmtTotalDatasets }}')" x-text="display">{{ $fmtTotalDatasets }}</span>
        </p>
        <p class="mt-1 text-xs text-slate-500">total dalam sistem</p>
    </div>

    <div class="card-interactive rounded-xl border border-slate-200/80 bg-white p-5 shadow-[0_1px_3px_0_rgba(0,0,0,0.04)]">
        <div class="flex items-start justify-between gap-4">
            <p class="text-sm font-medium text-slate-600">Dataset Gagal</p>
            <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-red-50 text-red-600">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M12 9v4m0 4h.01M10.3 4.6 3.1 17a2 2 0 0 0 1.7 3h14.4a2 2 0 0 0 1.7-3L13.7 4.6a2 2 0 0 0-3.4 0Z" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </span>
        </div>
        <p class="mt-4 text-3xl font-extrabold tracking-tight text-red-600">
            <span x-data="numberTicker('{{ $fmtFailedDatasets }}')" x-text="display">{{ $fmtFailedDatasets }}</span>
        </p>
        <p class="mt-1 text-xs text-slate-500">perlu tindak lanjut</p>
    </div>

    <div class="card-interactive rounded-xl border border-slate-200/80 bg-white p-5 shadow-[0_1px_3px_0_rgba(0,0,0,0.04)]">
        <div class="flex items-start justify-between gap-4">
            <p class="text-sm font-medium text-slate-600">Aktivitas Audit Hari Ini</p>
            <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-teal-50 text-accent">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M4 12a8 8 0 1 0 2.3-5.7M4 4v5h5" stroke-linecap="round" stroke-linejoin="round" />
                    <path d="M12 8v4l2.5 1.5" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </span>
        </div>
        <p class="mt-4 text-3xl font-extrabold tracking-tight text-primary">
            <span x-data="numberTicker('{{ $fmtAuditToday }}')" x-text="display">{{ $fmtAuditToday }}</span>
        </p>
        <p class="mt-1 text-xs text-slate-500">tercatat hari ini</p>
    </div>

    <div class="card-interactive rounded-xl border border-slate-200/80 bg-white p-5 shadow-[0_1px_3px_0_rgba(0,0,0,0.04)]">
        <div class="flex items-start justify-between gap-4">
            <p class="text-sm font-medium text-slate-600">Total Pengguna</p>
            <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-teal-50 text-accent">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </span>
        </div>
        <p class="mt-4 text-3xl font-extrabold tracking-tight text-primary">
            <span x-data="numberTicker('{{ $fmtTotalUsers }}')" x-text="display">{{ $fmtTotalUsers }}</span>
        </p>
        <p class="mt-1 text-xs text-slate-500">total akun terdaftar</p>
    </div>
</div>
