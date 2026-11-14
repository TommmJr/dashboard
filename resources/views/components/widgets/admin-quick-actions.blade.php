@props(['widget' => [], 'data' => null])

<div class="ibs-card p-5">
    <div class="flex items-center justify-between gap-3">
        <h2 class="ibs-section-title">Aksi Cepat</h2>
    </div>

    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        @can('dataset.import')
            <a href="{{ route('datasets.create') }}"
               class="group flex items-center gap-3 rounded-xl border border-slate-200/80 bg-white px-3.5 py-3 transition hover:border-[#16a3b8] hover:bg-slate-50/70 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#16a3b8]">
                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[#e6f4f6] text-[#0b6175] transition group-hover:bg-[#0b6175] group-hover:text-white">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M12 16V4m0 0L8 8m4-4 4 4M5 14v4a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>
                <span class="min-w-0">
                    <span class="block truncate text-sm font-semibold text-slate-800 group-hover:text-[#0b6175]">Upload Dataset</span>
                    <span class="mt-0.5 block truncate text-xs text-slate-500">Tambah data baru</span>
                </span>
            </a>
        @endcan

        @can('audit.view')
            <a href="{{ route('audit-logs.index') }}"
               class="group flex items-center gap-3 rounded-xl border border-slate-200/80 bg-white px-3.5 py-3 transition hover:border-[#16a3b8] hover:bg-slate-50/70 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#16a3b8]">
                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[#e6f4f6] text-[#0b6175] transition group-hover:bg-[#0b6175] group-hover:text-white">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M4 12a8 8 0 1 0 2.3-5.7M4 4v5h5" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M12 8v4l2.5 1.5" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>
                <span class="min-w-0">
                    <span class="block truncate text-sm font-semibold text-slate-800 group-hover:text-[#0b6175]">Audit Log</span>
                    <span class="mt-0.5 block truncate text-xs text-slate-500">Riwayat aktivitas</span>
                </span>
            </a>
        @endcan

        @can('user.view')
            <a href="{{ route('users.index') }}"
               class="group flex items-center gap-3 rounded-xl border border-slate-200/80 bg-white px-3.5 py-3 transition hover:border-[#16a3b8] hover:bg-slate-50/70 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#16a3b8]">
                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[#e6f4f6] text-[#0b6175] transition group-hover:bg-[#0b6175] group-hover:text-white">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>
                <span class="min-w-0">
                    <span class="block truncate text-sm font-semibold text-slate-800 group-hover:text-[#0b6175]">Manage Users</span>
                    <span class="mt-0.5 block truncate text-xs text-slate-500">Kelola pengguna</span>
                </span>
            </a>

            <a href="{{ route('divisions.index') }}"
               class="group flex items-center gap-3 rounded-xl border border-slate-200/80 bg-white px-3.5 py-3 transition hover:border-[#16a3b8] hover:bg-slate-50/70 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#16a3b8]">
                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[#e6f4f6] text-[#0b6175] transition group-hover:bg-[#0b6175] group-hover:text-white">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5m-4 0h4" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>
                <span class="min-w-0">
                    <span class="block truncate text-sm font-semibold text-slate-800 group-hover:text-[#0b6175]">Divisions</span>
                    <span class="mt-0.5 block truncate text-xs text-slate-500">Struktur divisi</span>
                </span>
            </a>

            @can('dashboard.update')
                <a href="{{ route('dashboard.builder') }}"
                   class="group flex items-center gap-3 rounded-xl border border-slate-200/80 bg-white px-3.5 py-3 transition hover:border-[#16a3b8] hover:bg-slate-50/70 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#16a3b8]">
                    <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[#e6f4f6] text-[#0b6175] transition group-hover:bg-[#0b6175] group-hover:text-white">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.4-9.4a2 2 0 1 1 2.8 2.8L11.8 15H9v-2.8l8.6-8.6Z" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </span>
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-semibold text-slate-800 group-hover:text-[#0b6175]">Dashboard Builder</span>
                        <span class="mt-0.5 block truncate text-xs text-slate-500">Tata letak widget</span>
                    </span>
                </a>
            @endcan
        @endcan
    </div>
</div>
