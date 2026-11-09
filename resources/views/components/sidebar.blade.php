{{--
    Sidebar navigasi utama (hover-expand).
    Nav item yang muncul otomatis beda per role — bukan karena file
    berbeda, tapi karena tiap link dibungkus @can(permission) dan
    permission tiap role sudah didefinisikan di RolePermissionSeeder.
--}}
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

    <nav class="flex flex-1 flex-col gap-1 px-3 py-6">
        <a href="{{ route('dashboard') }}"
           @if (request()->routeIs('dashboard')) aria-current="page" @endif
           class="flex items-center gap-3 rounded-lg border-l-2 px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('dashboard') ? 'border-highlight bg-white/15 text-white' : 'border-transparent text-white/70 hover:bg-white/10 hover:text-white' }}">
            <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path d="M4.5 16.5a8.5 8.5 0 1 1 15 0M12 12l3-3" stroke-linecap="round" stroke-linejoin="round" />
                <circle cx="12" cy="12" r="1.5" fill="currentColor" stroke="none" />
            </svg>
            <span x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label whitespace-nowrap">Dashboard</span>
        </a>

        @if (\App\Http\Controllers\DashboardController::canAccessKpiDetail(auth()->user()))
            <a href="{{ route('dashboard.kpi-detail') }}"
               id="sidebar-kpi-detail-link"
               @if (request()->routeIs('dashboard.kpi-detail')) aria-current="page" @endif
               class="flex items-center gap-3 rounded-lg border-l-2 px-3 py-2 text-xs font-medium transition {{ request()->routeIs('dashboard.kpi-detail') ? 'border-highlight bg-white/15 text-white' : 'border-transparent text-white/70 hover:bg-white/10 hover:text-white' }}"
               :class="$store.ui.sidebarExpanded ? 'pl-8' : 'px-3'">
                <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M9 17v-6m3 6V7m3 10v-4M5 21h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2Z" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <span x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label whitespace-nowrap">Rincian KPI</span>
            </a>
        @endif

        @can('dataset.view')
            <a href="{{ route('datasets.index') }}"
               @if ((request()->routeIs('datasets.index') || request()->routeIs('datasets.show'))) aria-current="page" @endif
               class="flex items-center gap-3 rounded-lg border-l-2 px-3 py-2.5 text-sm font-medium transition {{ (request()->routeIs('datasets.index') || request()->routeIs('datasets.show')) ? 'border-highlight bg-white/15 text-white' : 'border-transparent text-white/70 hover:bg-white/10 hover:text-white' }}">
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M4 7c0-1.1 3.6-2 8-2s8 .9 8 2-3.6 2-8 2-8-.9-8-2Z" stroke-linecap="round" stroke-linejoin="round" />
                    <path d="M4 7v5c0 1.1 3.6 2 8 2s8-.9 8-2V7M4 12v5c0 1.1 3.6 2 8 2s8-.9 8-2v-5" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <span x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label whitespace-nowrap">Datasets</span>
            </a>
        @endcan

        @can('dataset.import')
            <a href="{{ route('datasets.create') }}"
               @if (request()->routeIs('datasets.create') || request()->routeIs('datasets.kpi-import')) aria-current="page" @endif
               class="flex items-center gap-3 rounded-lg border-l-2 px-3 py-2.5 text-sm font-medium transition {{ (request()->routeIs('datasets.create') || request()->routeIs('datasets.kpi-import')) ? 'border-highlight bg-white/15 text-white' : 'border-transparent text-white/70 hover:bg-white/10 hover:text-white' }}">
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M12 16V4m0 0L8 8m4-4 4 4M5 14v4a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <span x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label whitespace-nowrap">Upload Data</span>
            </a>
        @endcan

        @can('audit.view')
            <a href="{{ route('audit-logs.index') }}"
               @if (request()->routeIs('audit-logs.*')) aria-current="page" @endif
               class="flex items-center gap-3 rounded-lg border-l-2 px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('audit-logs.*') ? 'border-highlight bg-white/15 text-white' : 'border-transparent text-white/70 hover:bg-white/10 hover:text-white' }}">
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M4 12a8 8 0 1 0 2.3-5.7M4 4v5h5" stroke-linecap="round" stroke-linejoin="round" />
                    <path d="M12 8v4l2.5 1.5" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <span x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label whitespace-nowrap">Audit Log</span>
            </a>
        @endcan

        @can('user.view')
            <a href="{{ route('users.index') }}"
               @if (request()->routeIs('users.*')) aria-current="page" @endif
               class="flex items-center gap-3 rounded-lg border-l-2 px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('users.*') ? 'border-highlight bg-white/15 text-white' : 'border-transparent text-white/70 hover:bg-white/10 hover:text-white' }}">
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <span x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label whitespace-nowrap">Manage Users</span>
            </a>

            <a href="{{ route('divisions.index') }}"
               @if (request()->routeIs('divisions.*')) aria-current="page" @endif
               class="flex items-center gap-3 rounded-lg border-l-2 px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('divisions.*') ? 'border-highlight bg-white/15 text-white' : 'border-transparent text-white/70 hover:bg-white/10 hover:text-white' }}">
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5m-4 0h4" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <span x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label whitespace-nowrap">Divisi</span>
            </a>
        @endcan

        @can('dashboard.update')
            <a href="{{ route('dashboard.builder') }}"
               @if (request()->routeIs('dashboard.builder')) aria-current="page" @endif
               class="flex items-center gap-3 rounded-lg border-l-2 px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('dashboard.builder') ? 'border-highlight bg-white/15 text-white' : 'border-transparent text-white/70 hover:bg-white/10 hover:text-white' }}">
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.4-9.4a2 2 0 1 1 2.8 2.8L11.8 15H9v-2.8l8.6-8.6Z" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <span x-show="$store.ui.sidebarExpanded" x-transition.opacity class="sidebar-label whitespace-nowrap">Dashboard Builder</span>
            </a>
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
