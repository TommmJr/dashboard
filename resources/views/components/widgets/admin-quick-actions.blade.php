@props(['widget' => [], 'data' => null])

<div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <h2 class="text-lg font-bold text-primary">Aksi Cepat</h2>

    <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @can('dataset.import')
            <a href="{{ route('datasets.create') }}"
               class="group flex items-center gap-3 rounded-lg border border-slate-200 bg-white p-3 transition hover:border-accent hover:bg-cyan-50/40">
                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-primary transition group-hover:bg-white">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M12 16V4m0 0L8 8m4-4 4 4M5 14v4a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>
                <span class="min-w-0">
                    <span class="block text-sm font-semibold text-primary">Upload Dataset</span>
                    <span class="mt-0.5 block text-xs text-slate-500">Tambah data baru</span>
                </span>
            </a>
        @endcan

        @can('audit.view')
            <a href="{{ route('audit-logs.index') }}"
               class="group flex items-center gap-3 rounded-lg border border-slate-200 bg-white p-3 transition hover:border-accent hover:bg-cyan-50/40">
                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-primary transition group-hover:bg-white">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M4 12a8 8 0 1 0 2.3-5.7M4 4v5h5" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M12 8v4l2.5 1.5" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>
                <span class="min-w-0">
                    <span class="block text-sm font-semibold text-primary">Audit Log</span>
                    <span class="mt-0.5 block text-xs text-slate-500">Riwayat aktivitas sistem</span>
                </span>
            </a>
        @endcan

        @can('user.view')
            <a href="{{ route('users.index') }}"
               class="group flex items-center gap-3 rounded-lg border border-slate-200 bg-white p-3 transition hover:border-accent hover:bg-cyan-50/40">
                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-primary transition group-hover:bg-white">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>
                <span class="min-w-0">
                    <span class="block text-sm font-semibold text-primary">Manage Users</span>
                    <span class="mt-0.5 block text-xs text-slate-500">Kelola akun pengguna</span>
                </span>
            </a>

            <a href="{{ route('divisions.index') }}"
               class="group flex items-center gap-3 rounded-lg border border-slate-200 bg-white p-3 transition hover:border-accent hover:bg-cyan-50/40">
                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-primary transition group-hover:bg-white">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5m-4 0h4" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>
                <span class="min-w-0">
                    <span class="block text-sm font-semibold text-primary">Divisions</span>
                    <span class="mt-0.5 block text-xs text-slate-500">Kelola struktur divisi</span>
                </span>
            </a>
        @endcan
    </div>
</div>
