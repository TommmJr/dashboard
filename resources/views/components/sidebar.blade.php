{{--
    Sidebar navigasi utama (hover-expand).
    Nav item yang muncul otomatis beda per role — bukan karena file
    berbeda, tapi karena tiap link dibungkus @can(permission) dan
    permission tiap role sudah didefinisikan di RolePermissionSeeder.
--}}
@php
    $authUser = auth()->user();
    $isAdmin = $authUser ? $authUser->hasRole(\App\Models\Role::ADMIN) : false;
    $isAssetStaff = $authUser && (
        ($authUser->hasRole(\App\Models\Role::STAFF_DIVISI) && in_array($authUser->division?->slug, ['managemen-aset', 'data-aset'], true))
    );

    if ($isAdmin) {
        $primaryGroupTitle = 'MONITORING';
        $dashboardSub = 'Ringkasan operasional';
        $dataGroupTitle = 'MANAJEMEN DATA';
        $datasetsSub = 'Kelola berkas divisi';
        $uploadSub = 'Impor & sinkronisasi';
    } elseif ($isAssetStaff) {
        $primaryGroupTitle = 'UTAMA';
        $dashboardSub = 'Rekapitulasi UIT & UPT';
        $dataGroupTitle = 'DATA DIVISI';
        $datasetsSub = 'Riwayat data aset';
        $uploadSub = 'Unggah & sinkronisasi';
    } else {
        $primaryGroupTitle = 'UTAMA';
        $dashboardSub = 'Ringkasan NKO & tren';
        $dataGroupTitle = 'DATA DIVISI';
        $datasetsSub = 'Arsip & riwayat berkas';
        $uploadSub = 'Impor workbook KPI';
    }
@endphp
<aside
    x-data
    @mouseenter="clearTimeout($store.ui.sidebarTimer); $store.ui.sidebarExpanded = true"
    @mouseleave="$store.ui.sidebarTimer = setTimeout(() => $store.ui.sidebarExpanded = false, 150)"
    :class="$store.ui.sidebarExpanded ? 'w-64' : 'w-16'"
    id="main-sidebar"
    class="app-sidebar fixed inset-y-0 left-0 z-50 flex min-h-screen flex-col overflow-hidden bg-primary text-white transition-all duration-200 ease-in-out"
>
    <div class="flex h-[78px] shrink-0 items-center gap-3 border-b border-white/10 px-4">
        <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-white/20 bg-white/10">
            <img src="{{ asset('images/logo-pln.png') }}" alt="Logo PLN" class="h-full w-full object-contain">
        </span>
        <p x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label whitespace-nowrap text-base font-bold text-white">
            IBS Dashboard
        </p>
    </div>

    <nav class="flex flex-1 flex-col gap-1 px-3 py-4 overflow-y-auto">
        {{-- Group 1: UTAMA / MONITORING --}}
        <div>
            <div x-show="$store.ui.sidebarExpanded" x-transition.opacity class="px-3 pb-1 text-[10px] font-semibold tracking-wider uppercase text-teal-200/60">
                {{ $primaryGroupTitle }}
            </div>

            <a href="{{ route('dashboard') }}"
               @if (request()->routeIs('dashboard')) aria-current="page" @endif
               class="flex items-center gap-3 rounded-lg border-l-2 px-3 py-2 text-sm font-medium transition {{ request()->routeIs('dashboard') ? 'border-highlight bg-white/15 text-white' : 'border-transparent text-white/70 hover:bg-white/10 hover:text-white' }}">
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M4.5 16.5a8.5 8.5 0 1 1 15 0M12 12l3-3" stroke-linecap="round" stroke-linejoin="round" />
                    <circle cx="12" cy="12" r="1.5" fill="currentColor" stroke="none" />
                </svg>
                <div x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label flex flex-col min-w-0">
                    <span class="whitespace-nowrap leading-tight">Dashboard</span>
                    <span class="text-[11px] text-teal-100/65 leading-tight font-normal">{{ $dashboardSub }}</span>
                </div>
            </a>

            @if (!$isAdmin && \App\Http\Controllers\DashboardController::canAccessKpiDetail($authUser))
                <a href="{{ route('dashboard.kpi-detail') }}"
                   id="sidebar-kpi-detail-link"
                   @if (request()->routeIs('dashboard.kpi-detail')) aria-current="page" @endif
                   class="flex items-center gap-3 rounded-lg border-l-2 px-3 py-2 text-sm font-medium transition {{ request()->routeIs('dashboard.kpi-detail') ? 'border-highlight bg-white/15 text-white' : 'border-transparent text-white/70 hover:bg-white/10 hover:text-white' }}">
                    <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M9 17v-6m3 6V7m3 10v-4M5 21h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2Z" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <div x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label flex flex-col min-w-0">
                        <span class="whitespace-nowrap leading-tight">Rincian KPI</span>
                        <span class="text-[11px] text-teal-100/65 leading-tight font-normal">Detail metrik & capaian</span>
                    </div>
                </a>
            @endif

            @if (!$isAdmin && \App\Http\Controllers\KpiAnalyticsController::canAccessKpiAnalytics($authUser))
                <a href="{{ route('dashboard.kpi-analytics') }}"
                   id="sidebar-kpi-analytics-link"
                   @if (request()->routeIs('dashboard.kpi-analytics') || request()->routeIs('dashboard.kpi-matrix')) aria-current="page" @endif
                   class="flex items-center gap-3 rounded-lg border-l-2 px-3 py-2 text-sm font-medium transition {{ (request()->routeIs('dashboard.kpi-analytics') || request()->routeIs('dashboard.kpi-matrix')) ? 'border-highlight bg-white/15 text-white' : 'border-transparent text-white/70 hover:bg-white/10 hover:text-white' }}">
                    <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M3 3v18h18" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="m19 9-5 5-4-4-3 3" stroke-linecap="round" stroke-linejoin="round" />
                        <circle cx="19" cy="9" r="1.5" fill="currentColor" stroke="none" />
                    </svg>
                    <div x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label flex flex-col min-w-0">
                        <span class="whitespace-nowrap leading-tight">Analitik & Bottleneck KPI</span>
                        <span class="text-[11px] text-teal-100/65 leading-tight font-normal">Evaluasi gap indikator</span>
                    </div>
                </a>
            @endif
        </div>

        {{-- Group 2: EKSPLORASI ASET (Khusus Staf Divisi Aset) --}}
        @if (\App\Http\Controllers\AssetDetailController::canAccessAssetDetail($authUser))
            <div class="border-t border-white/10 pt-3 mt-3">
                <div x-show="$store.ui.sidebarExpanded" x-transition.opacity class="px-3 pb-1 text-[10px] font-semibold tracking-wider uppercase text-teal-200/60">
                    Eksplorasi Aset
                </div>

                <a href="{{ route('dashboard.asset-detail', ['section' => 'trafo']) }}"
                   id="sidebar-asset-detail-link"
                   @if (request()->routeIs('dashboard.asset-detail') && (!request()->route('section') || request()->route('section') === 'trafo' || request()->query('section') === 'trafo')) aria-current="page" @endif
                   class="flex items-center gap-3 rounded-lg border-l-2 px-3 py-2 text-sm font-medium transition {{ (request()->routeIs('dashboard.asset-detail') && (!request()->route('section') || request()->route('section') === 'trafo' || request()->query('section') === 'trafo')) ? 'border-highlight bg-white/15 text-white' : 'border-transparent text-white/70 hover:bg-white/10 hover:text-white' }}">
                    <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <rect x="3" y="6" width="18" height="12" rx="2" />
                        <path d="M7 12h2m6 0h2M12 9v6M9 3v3m6-3v3m-6 12v3m6-3v3" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <div x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label flex flex-col min-w-0">
                        <span class="whitespace-nowrap leading-tight">Bedah Trafo & Tegangan</span>
                        <span class="text-[11px] text-teal-100/65 leading-tight font-normal">MVA & unit per kV</span>
                    </div>
                </a>

                <a href="{{ route('dashboard.asset-detail', ['section' => 'mva']) }}"
                   id="sidebar-asset-mva-link"
                   @if (request()->routeIs('dashboard.asset-detail') && (request()->route('section') === 'mva' || request()->query('section') === 'mva')) aria-current="page" @endif
                   class="flex items-center gap-3 rounded-lg border-l-2 px-3 py-2 text-sm font-medium transition {{ (request()->routeIs('dashboard.asset-detail') && (request()->route('section') === 'mva' || request()->query('section') === 'mva')) ? 'border-highlight bg-white/15 text-white' : 'border-transparent text-white/70 hover:bg-white/10 hover:text-white' }}">
                    <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <div x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label flex flex-col min-w-0">
                        <span class="whitespace-nowrap leading-tight">Distribusi Daya (MVA)</span>
                        <span class="text-[11px] text-teal-100/65 leading-tight font-normal">Pangsa kapasitas UPT</span>
                    </div>
                </a>

                <a href="{{ route('dashboard.asset-detail', ['section' => 'transmisi']) }}"
                   id="sidebar-asset-transmisi-link"
                   @if (request()->routeIs('dashboard.asset-detail') && (request()->route('section') === 'transmisi' || request()->query('section') === 'transmisi')) aria-current="page" @endif
                   class="flex items-center gap-3 rounded-lg border-l-2 px-3 py-2 text-sm font-medium transition {{ (request()->routeIs('dashboard.asset-detail') && (request()->route('section') === 'transmisi' || request()->query('section') === 'transmisi')) ? 'border-highlight bg-white/15 text-white' : 'border-transparent text-white/70 hover:bg-white/10 hover:text-white' }}">
                    <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M22 12h-4l-3 9L9 3l-3 9H2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <div x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label flex flex-col min-w-0">
                        <span class="whitespace-nowrap leading-tight">Jaringan Transmisi (kms)</span>
                        <span class="text-[11px] text-teal-100/65 leading-tight font-normal">Saluran SU & SK</span>
                    </div>
                </a>

                <a href="{{ route('dashboard.asset-detail', ['section' => 'tower']) }}"
                   id="sidebar-asset-tower-link"
                   @if (request()->routeIs('dashboard.asset-detail') && (request()->route('section') === 'tower' || request()->query('section') === 'tower')) aria-current="page" @endif
                   class="flex items-center gap-3 rounded-lg border-l-2 px-3 py-2 text-sm font-medium transition {{ (request()->routeIs('dashboard.asset-detail') && (request()->route('section') === 'tower' || request()->query('section') === 'tower')) ? 'border-highlight bg-white/15 text-white' : 'border-transparent text-white/70 hover:bg-white/10 hover:text-white' }}">
                    <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M12 2v20M6 8l12 0M4 14l16 0M8 22l4-20 4 20" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <div x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label flex flex-col min-w-0">
                        <span class="whitespace-nowrap leading-tight">Sebaran Tower (Unit)</span>
                        <span class="text-[11px] text-teal-100/65 leading-tight font-normal">Konstruksi per UPT</span>
                    </div>
                </a>
            </div>
        @endif

        {{-- Group 3: DATA DIVISI / MANAJEMEN DATA --}}
        @if ($authUser && ($authUser->can('dataset.view') || $authUser->can('dataset.import')))
            <div class="border-t border-white/10 pt-3 mt-3">
                <div x-show="$store.ui.sidebarExpanded" x-transition.opacity class="px-3 pb-1 text-[10px] font-semibold tracking-wider uppercase text-teal-200/60">
                    {{ $dataGroupTitle }}
                </div>

                @can('dataset.view')
                    <a href="{{ route('datasets.index') }}"
                       @if ((request()->routeIs('datasets.index') || request()->routeIs('datasets.show'))) aria-current="page" @endif
                       class="flex items-center gap-3 rounded-lg border-l-2 px-3 py-2 text-sm font-medium transition {{ (request()->routeIs('datasets.index') || request()->routeIs('datasets.show')) ? 'border-highlight bg-white/15 text-white' : 'border-transparent text-white/70 hover:bg-white/10 hover:text-white' }}">
                        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M4 7c0-1.1 3.6-2 8-2s8 .9 8 2-3.6 2-8 2-8-.9-8-2Z" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M4 7v5c0 1.1 3.6 2 8 2s8-.9 8-2V7M4 12v5c0 1.1 3.6 2 8 2s8-.9 8-2v-5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <div x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label flex flex-col min-w-0">
                            <span class="whitespace-nowrap leading-tight">Datasets</span>
                            <span class="text-[11px] text-teal-100/65 leading-tight font-normal">{{ $datasetsSub }}</span>
                        </div>
                    </a>
                @endcan

                @can('dataset.import')
                    <a href="{{ route('datasets.create') }}"
                       @if (request()->routeIs('datasets.create') || request()->routeIs('datasets.kpi-import')) aria-current="page" @endif
                       class="flex items-center gap-3 rounded-lg border-l-2 px-3 py-2 text-sm font-medium transition {{ (request()->routeIs('datasets.create') || request()->routeIs('datasets.kpi-import')) ? 'border-highlight bg-white/15 text-white' : 'border-transparent text-white/70 hover:bg-white/10 hover:text-white' }}">
                        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M12 16V4m0 0L8 8m4-4 4 4M5 14v4a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <div x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label flex flex-col min-w-0">
                            <span class="whitespace-nowrap leading-tight">Upload Data</span>
                            <span class="text-[11px] text-teal-100/65 leading-tight font-normal">{{ $uploadSub }}</span>
                        </div>
                    </a>
                @endcan
            </div>
        @endif

        {{-- Group 4: TATA KELOLA & AKSES (Admin, Management, Editor bila ada hak) --}}
        @if ($authUser && ($authUser->can('audit.view') || $authUser->can('user.view')))
            <div class="border-t border-white/10 pt-3 mt-3">
                <div x-show="$store.ui.sidebarExpanded" x-transition.opacity class="px-3 pb-1 text-[10px] font-semibold tracking-wider uppercase text-teal-200/60">
                    TATA KELOLA & AKSES
                </div>

                @can('audit.view')
                    <a href="{{ route('audit-logs.index') }}"
                       @if (request()->routeIs('audit-logs.*')) aria-current="page" @endif
                       class="flex items-center gap-3 rounded-lg border-l-2 px-3 py-2 text-sm font-medium transition {{ request()->routeIs('audit-logs.*') ? 'border-highlight bg-white/15 text-white' : 'border-transparent text-white/70 hover:bg-white/10 hover:text-white' }}">
                        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M4 12a8 8 0 1 0 2.3-5.7M4 4v5h5" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M12 8v4l2.5 1.5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <div x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label flex flex-col min-w-0">
                            <span class="whitespace-nowrap leading-tight">Audit Log</span>
                            <span class="text-[11px] text-teal-100/65 leading-tight font-normal">Jejak aktivitas sistem</span>
                        </div>
                    </a>
                @endcan

                @can('user.view')
                    <a href="{{ route('users.index') }}"
                       @if (request()->routeIs('users.*')) aria-current="page" @endif
                       class="flex items-center gap-3 rounded-lg border-l-2 px-3 py-2 text-sm font-medium transition {{ request()->routeIs('users.*') ? 'border-highlight bg-white/15 text-white' : 'border-transparent text-white/70 hover:bg-white/10 hover:text-white' }}">
                        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <div x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label flex flex-col min-w-0">
                            <span class="whitespace-nowrap leading-tight">Manage Users</span>
                            <span class="text-[11px] text-teal-100/65 leading-tight font-normal">Akun & hak akses</span>
                        </div>
                    </a>

                    <a href="{{ route('divisions.index') }}"
                       @if (request()->routeIs('divisions.*')) aria-current="page" @endif
                       class="flex items-center gap-3 rounded-lg border-l-2 px-3 py-2 text-sm font-medium transition {{ request()->routeIs('divisions.*') ? 'border-highlight bg-white/15 text-white' : 'border-transparent text-white/70 hover:bg-white/10 hover:text-white' }}">
                        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5m-4 0h4" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <div x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label flex flex-col min-w-0">
                            <span class="whitespace-nowrap leading-tight">Divisi</span>
                            <span class="text-[11px] text-teal-100/65 leading-tight font-normal">Unit organisasi</span>
                        </div>
                    </a>
                @endcan
            </div>
        @endif

        {{-- Group 5: KONFIGURASI (Admin, Editor Dashboard) --}}
        @can('dashboard.update')
            <div class="border-t border-white/10 pt-3 mt-3">
                <div x-show="$store.ui.sidebarExpanded" x-transition.opacity class="px-3 pb-1 text-[10px] font-semibold tracking-wider uppercase text-teal-200/60">
                    KONFIGURASI
                </div>

                <a href="{{ route('dashboard.builder') }}"
                   @if (request()->routeIs('dashboard.builder')) aria-current="page" @endif
                   class="flex items-center gap-3 rounded-lg border-l-2 px-3 py-2 text-sm font-medium transition {{ request()->routeIs('dashboard.builder') ? 'border-highlight bg-white/15 text-white' : 'border-transparent text-white/70 hover:bg-white/10 hover:text-white' }}">
                    <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.4-9.4a2 2 0 1 1 2.8 2.8L11.8 15H9v-2.8l8.6-8.6Z" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <div x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label flex flex-col min-w-0">
                        <span class="whitespace-nowrap leading-tight">Dashboard Builder</span>
                        <span class="text-[11px] text-teal-100/65 leading-tight font-normal">Atur tata letak & widget</span>
                    </div>
                </a>
            </div>
        @endcan
    </nav>

    <div id="sidebar-user" class="border-t border-white/10 p-3">
        <div class="flex items-center" :class="$store.ui.sidebarExpanded ? 'justify-between gap-2' : 'flex-col gap-2'">
            <div class="flex items-center gap-3 min-w-0">
                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/20 text-sm font-bold text-white" aria-hidden="true">
                    {{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
                </span>
                <div x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label min-w-0">
                    <p class="truncate text-sm font-semibold text-white">{{ auth()->user()->name }}</p>
                    @if (auth()->user()->role)
                        <p class="truncate text-xs text-white/70">{{ auth()->user()->role->name }}</p>
                    @endif
                </div>
            </div>
            <form method="POST" action="{{ route('logout') }}" onsubmit="return confirm('Yakin ingin logout?');" class="shrink-0">
                @csrf
                <button type="submit"
                        aria-label="Keluar"
                        title="Keluar"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-white/80 transition hover:bg-white/10 hover:text-white">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M10 17l5-5-5-5M15 12H3m10-9h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </button>
            </form>
        </div>
    </div>
</aside>
