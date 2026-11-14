{{--
    Sidebar navigasi utama (hover-expand).
    Nav item yang muncul otomatis beda per role, bukan karena file
    berbeda, tapi karena tiap link dibungkus @can(permission) dan
    permission tiap role sudah didefinisikan di RolePermissionSeeder.
--}}
@php
    $authUser = auth()->user();
    $isAdmin = $authUser ? $authUser->hasRole(\App\Models\Role::ADMIN) : false;

    $primaryGroupTitle = $isAdmin ? 'MONITORING' : 'UTAMA';
    $dataGroupTitle = $isAdmin ? 'MANAJEMEN DATA' : 'DATA DIVISI';

    $linkBase = 'group flex min-w-0 items-center gap-3 rounded-lg px-3 py-2 text-[13px] whitespace-nowrap overflow-hidden transition';
    $linkActive = 'bg-[#e6f4f6] text-[#0b6175] font-semibold border-l-[3px] border-[#0b6175]';
    $linkInactive = 'border-l-[3px] border-transparent font-medium text-slate-600 hover:bg-slate-50 hover:text-slate-900';
    $iconBase = 'h-5 w-5 shrink-0 stroke-[1.8] transition-colors';
    $iconActive = 'text-[#0b6175]';
    $iconInactive = 'text-slate-400 group-hover:text-slate-700';
    $groupLabelClass = 'px-3 pt-4 pb-1 text-[10px] font-semibold uppercase tracking-wider text-slate-400 whitespace-nowrap overflow-hidden text-ellipsis';

    $isDashboard = request()->routeIs('dashboard');
    $isKpiDetail = request()->routeIs('dashboard.kpi-detail');
    $isKpiAnalytics = request()->routeIs('dashboard.kpi-analytics') || request()->routeIs('dashboard.kpi-matrix');
    $isAssetDetail = request()->routeIs('dashboard.asset-detail');
    $assetSection = request()->route('section') ?? request()->query('section');
    $isAssetTrafo = $isAssetDetail && (! $assetSection || $assetSection === 'trafo');
    $isAssetMva = $isAssetDetail && $assetSection === 'mva';
    $isAssetTransmisi = $isAssetDetail && $assetSection === 'transmisi';
    $isAssetTower = $isAssetDetail && $assetSection === 'tower';
    $isDatasetsIndex = request()->routeIs('datasets.index') || request()->routeIs('datasets.show');
    $isDatasetsCreate = request()->routeIs('datasets.create') || request()->routeIs('datasets.kpi-import');
    $isAuditLogs = request()->routeIs('audit-logs.*');
    $isUsers = request()->routeIs('users.*');
    $isDivisions = request()->routeIs('divisions.*');
    $isBuilder = request()->routeIs('dashboard.builder');
@endphp
<aside
    x-data
    @mouseenter="clearTimeout($store.ui.sidebarTimer); $store.ui.sidebarExpanded = true"
    @mouseleave="$store.ui.sidebarTimer = setTimeout(() => $store.ui.sidebarExpanded = false, 150)"
    :class="$store.ui.sidebarExpanded ? 'w-64' : 'w-16'"
    id="main-sidebar"
    class="app-sidebar fixed inset-y-0 left-0 z-50 flex min-h-screen flex-col overflow-x-hidden [&::-webkit-scrollbar]:hidden [-ms-overflow-style:none] [scrollbar-width:none] border-r border-slate-200/80 bg-white text-slate-700 transition-all duration-200 ease-in-out"
>
    <div class="flex h-16 shrink-0 items-center border-b border-slate-100 px-4"
         :class="$store.ui.sidebarExpanded ? 'justify-between gap-2' : 'justify-center'">
        <div class="flex min-w-0 items-center gap-2.5">
            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-[#e6f4f6] text-[#0b6175]" aria-hidden="true">
                <svg class="h-5 w-5 stroke-[1.8]" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                    <path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </span>
            <span x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label whitespace-nowrap overflow-hidden text-ellipsis text-sm font-bold tracking-tight text-slate-900">
                IBS UIT JBT
            </span>
        </div>
        <button type="button"
                x-show="$store.ui.sidebarExpanded"
                x-transition.opacity
                @click="$store.ui.sidebarExpanded = !$store.ui.sidebarExpanded"
                aria-label="Toggle sidebar"
                title="Perkecil / Perluas sidebar"
                class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]">
            <svg class="h-4 w-4 stroke-[1.8]" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                <path d="M15 18l-6-6 6-6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>
    </div>

    <nav class="flex flex-1 flex-col gap-1 px-3 py-3 overflow-y-auto overflow-x-hidden [&::-webkit-scrollbar]:hidden [-ms-overflow-style:none] [scrollbar-width:none]">
        {{-- Group 1: UTAMA / MONITORING --}}
        <div>
            <div x-show="$store.ui.sidebarExpanded" x-transition.opacity class="px-3 pt-1 pb-1 text-[10px] font-semibold uppercase tracking-wider text-slate-400 whitespace-nowrap overflow-hidden text-ellipsis">
                {{ $primaryGroupTitle }}
            </div>

            <a href="{{ route('dashboard') }}"
               @if ($isDashboard) aria-current="page" @endif
               class="{{ $linkBase }} {{ $isDashboard ? $linkActive : $linkInactive }}">
                <svg class="{{ $iconBase }} {{ $isDashboard ? $iconActive : $iconInactive }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                    <path d="M4.5 16.5a8.5 8.5 0 1 1 15 0M12 12l3-3" stroke-linecap="round" stroke-linejoin="round" />
                    <circle cx="12" cy="12" r="1.5" fill="currentColor" stroke="none" />
                </svg>
                <span x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label whitespace-nowrap overflow-hidden text-ellipsis">Dashboard</span>
            </a>

            @if (!$isAdmin && \App\Http\Controllers\DashboardController::canAccessKpiDetail($authUser))
                <a href="{{ route('dashboard.kpi-detail') }}"
                   id="sidebar-kpi-detail-link"
                   @if ($isKpiDetail) aria-current="page" @endif
                   class="{{ $linkBase }} {{ $isKpiDetail ? $linkActive : $linkInactive }}">
                    <svg class="{{ $iconBase }} {{ $isKpiDetail ? $iconActive : $iconInactive }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <path d="M9 17v-6m3 6V7m3 10v-4M5 21h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2Z" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label whitespace-nowrap overflow-hidden text-ellipsis">Rincian KPI</span>
                </a>
            @endif

            @if (!$isAdmin && \App\Http\Controllers\KpiAnalyticsController::canAccessKpiAnalytics($authUser))
                <a href="{{ route('dashboard.kpi-analytics') }}"
                   id="sidebar-kpi-analytics-link"
                   @if ($isKpiAnalytics) aria-current="page" @endif
                   class="{{ $linkBase }} {{ $isKpiAnalytics ? $linkActive : $linkInactive }}">
                    <svg class="{{ $iconBase }} {{ $isKpiAnalytics ? $iconActive : $iconInactive }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <path d="M3 3v18h18" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="m19 9-5 5-4-4-3 3" stroke-linecap="round" stroke-linejoin="round" />
                        <circle cx="19" cy="9" r="1.5" fill="currentColor" stroke="none" />
                    </svg>
                    <span x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label whitespace-nowrap overflow-hidden text-ellipsis">Analitik & Bottleneck KPI</span>
                </a>
            @endif
        </div>

        {{-- Group 2: EKSPLORASI ASET (Khusus Staf Divisi Aset) --}}
        @if (\App\Http\Controllers\AssetDetailController::canAccessAssetDetail($authUser))
            <div class="mt-2 border-t border-slate-100">
                <div x-show="$store.ui.sidebarExpanded" x-transition.opacity class="{{ $groupLabelClass }}">
                    EKSPLORASI ASET UIT JBT
                </div>
                <div x-show="$store.ui.sidebarExpanded" x-transition.opacity class="px-3 pb-1.5 text-[10px] text-slate-400 whitespace-nowrap overflow-hidden text-ellipsis">
                    Kepemilikan Induk & Sebaran 8 UPT
                </div>

                <a href="{{ route('dashboard.asset-detail', ['section' => 'trafo']) }}"
                   id="sidebar-asset-detail-link"
                   @if ($isAssetTrafo) aria-current="page" @endif
                   class="{{ $linkBase }} {{ $isAssetTrafo ? $linkActive : $linkInactive }}">
                    <svg class="{{ $iconBase }} {{ $isAssetTrafo ? $iconActive : $iconInactive }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <rect x="3" y="6" width="18" height="12" rx="2" />
                        <path d="M7 12h2m6 0h2M12 9v6M9 3v3m6-3v3m-6 12v3m6-3v3" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label whitespace-nowrap overflow-hidden text-ellipsis">Bedah Trafo & Tegangan</span>
                </a>

                <a href="{{ route('dashboard.asset-detail', ['section' => 'mva']) }}"
                   id="sidebar-asset-mva-link"
                   @if ($isAssetMva) aria-current="page" @endif
                   class="{{ $linkBase }} {{ $isAssetMva ? $linkActive : $linkInactive }}">
                    <svg class="{{ $iconBase }} {{ $isAssetMva ? $iconActive : $iconInactive }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label whitespace-nowrap overflow-hidden text-ellipsis">Distribusi Daya (MVA)</span>
                </a>

                <a href="{{ route('dashboard.asset-detail', ['section' => 'transmisi']) }}"
                   id="sidebar-asset-transmisi-link"
                   @if ($isAssetTransmisi) aria-current="page" @endif
                   class="{{ $linkBase }} {{ $isAssetTransmisi ? $linkActive : $linkInactive }}">
                    <svg class="{{ $iconBase }} {{ $isAssetTransmisi ? $iconActive : $iconInactive }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <path d="M22 12h-4l-3 9L9 3l-3 9H2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label whitespace-nowrap overflow-hidden text-ellipsis">Jaringan Transmisi (kms)</span>
                </a>

                <a href="{{ route('dashboard.asset-detail', ['section' => 'tower']) }}"
                   id="sidebar-asset-tower-link"
                   @if ($isAssetTower) aria-current="page" @endif
                   class="{{ $linkBase }} {{ $isAssetTower ? $linkActive : $linkInactive }}">
                    <svg class="{{ $iconBase }} {{ $isAssetTower ? $iconActive : $iconInactive }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <path d="M12 2v20M6 8l12 0M4 14l16 0M8 22l4-20 4 20" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label whitespace-nowrap overflow-hidden text-ellipsis">Sebaran Tower (Unit)</span>
                </a>
            </div>
        @endif

        {{-- Group 3: DATA DIVISI / MANAJEMEN DATA --}}
        @if ($authUser && ($authUser->can('dataset.view') || $authUser->can('dataset.import')))
            <div class="mt-2 border-t border-slate-100">
                <div x-show="$store.ui.sidebarExpanded" x-transition.opacity class="{{ $groupLabelClass }}">
                    {{ $dataGroupTitle }}
                </div>

                @can('dataset.view')
                    <a href="{{ route('datasets.index') }}"
                       @if ($isDatasetsIndex) aria-current="page" @endif
                       class="{{ $linkBase }} {{ $isDatasetsIndex ? $linkActive : $linkInactive }}">
                        <svg class="{{ $iconBase }} {{ $isDatasetsIndex ? $iconActive : $iconInactive }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                            <path d="M4 7c0-1.1 3.6-2 8-2s8 .9 8 2-3.6 2-8 2-8-.9-8-2Z" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M4 7v5c0 1.1 3.6 2 8 2s8-.9 8-2V7M4 12v5c0 1.1 3.6 2 8 2s8-.9 8-2v-5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <span x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label whitespace-nowrap overflow-hidden text-ellipsis">Datasets</span>
                    </a>
                @endcan

                @can('dataset.import')
                    <a href="{{ route('datasets.create') }}"
                       @if ($isDatasetsCreate) aria-current="page" @endif
                       class="{{ $linkBase }} {{ $isDatasetsCreate ? $linkActive : $linkInactive }}">
                        <svg class="{{ $iconBase }} {{ $isDatasetsCreate ? $iconActive : $iconInactive }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                            <path d="M12 16V4m0 0L8 8m4-4 4 4M5 14v4a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <span x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label whitespace-nowrap overflow-hidden text-ellipsis">Upload Data</span>
                    </a>
                @endcan
            </div>
        @endif

        {{-- Group 4: TATA KELOLA & AKSES (Admin, Management, Editor bila ada hak) --}}
        @if ($authUser && ($authUser->can('audit.view') || $authUser->can('user.view')))
            <div class="mt-2 border-t border-slate-100">
                <div x-show="$store.ui.sidebarExpanded" x-transition.opacity class="{{ $groupLabelClass }}">
                    TATA KELOLA & AKSES
                </div>

                @can('audit.view')
                    <a href="{{ route('audit-logs.index') }}"
                       @if ($isAuditLogs) aria-current="page" @endif
                       class="{{ $linkBase }} {{ $isAuditLogs ? $linkActive : $linkInactive }}">
                        <svg class="{{ $iconBase }} {{ $isAuditLogs ? $iconActive : $iconInactive }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                            <path d="M4 12a8 8 0 1 0 2.3-5.7M4 4v5h5" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M12 8v4l2.5 1.5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <span x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label whitespace-nowrap overflow-hidden text-ellipsis">Audit Log</span>
                    </a>
                @endcan

                @can('user.view')
                    <a href="{{ route('users.index') }}"
                       @if ($isUsers) aria-current="page" @endif
                       class="{{ $linkBase }} {{ $isUsers ? $linkActive : $linkInactive }}">
                        <svg class="{{ $iconBase }} {{ $isUsers ? $iconActive : $iconInactive }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <span x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label whitespace-nowrap overflow-hidden text-ellipsis">Manage Users</span>
                    </a>

                    <a href="{{ route('divisions.index') }}"
                       @if ($isDivisions) aria-current="page" @endif
                       class="{{ $linkBase }} {{ $isDivisions ? $linkActive : $linkInactive }}">
                        <svg class="{{ $iconBase }} {{ $isDivisions ? $iconActive : $iconInactive }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                            <path d="M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5m-4 0h4" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <span x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label whitespace-nowrap overflow-hidden text-ellipsis">Divisi</span>
                    </a>
                @endcan
            </div>
        @endif

        {{-- Group 5: KONFIGURASI (Admin, Editor Dashboard) --}}
        @can('dashboard.update')
            <div class="mt-2 border-t border-slate-100">
                <div x-show="$store.ui.sidebarExpanded" x-transition.opacity class="{{ $groupLabelClass }}">
                    KONFIGURASI
                </div>

                <a href="{{ route('dashboard.builder') }}"
                   @if ($isBuilder) aria-current="page" @endif
                   class="{{ $linkBase }} {{ $isBuilder ? $linkActive : $linkInactive }}">
                    <svg class="{{ $iconBase }} {{ $isBuilder ? $iconActive : $iconInactive }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <path d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.4-9.4a2 2 0 1 1 2.8 2.8L11.8 15H9v-2.8l8.6-8.6Z" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label whitespace-nowrap overflow-hidden text-ellipsis">Dashboard Builder</span>
                </a>
            </div>
        @endcan
    </nav>

    <div id="sidebar-user" class="border-t border-slate-100 bg-slate-50/50 p-3">
        <div class="flex items-center" :class="$store.ui.sidebarExpanded ? 'justify-between gap-2' : 'flex-col gap-2'">
            <div class="flex min-w-0 items-center gap-3">
                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-[#e6f4f6] text-xs font-bold text-[#0b6175]" aria-hidden="true">
                    {{ mb_strtoupper(mb_substr($authUser->name, 0, 1)) }}
                </span>
                <div x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label min-w-0">
                    <p class="truncate whitespace-nowrap overflow-hidden text-ellipsis text-xs font-semibold text-slate-800">{{ $authUser->name }}</p>
                    @if ($authUser->role || $authUser->division)
                        <p class="truncate whitespace-nowrap overflow-hidden text-ellipsis text-[11px] text-slate-500">
                            {{ collect([$authUser->role?->name, $authUser->division?->name])->filter()->implode(' / ') }}
                        </p>
                    @endif
                </div>
            </div>
            <form method="POST" action="{{ route('logout') }}" onsubmit="return confirm('Yakin ingin logout?');" class="shrink-0">
                @csrf
                <button type="submit"
                        aria-label="Keluar"
                        title="Keluar"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 transition hover:bg-rose-50 hover:text-rose-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]">
                    <svg class="h-5 w-5 stroke-[1.8]" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <path d="M10 17l5-5-5-5M15 12H3m10-9h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </button>
            </form>
        </div>
    </div>
</aside>
