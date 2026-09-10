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
    class="fixed inset-y-0 left-0 z-40 flex min-h-screen flex-col overflow-hidden bg-primary text-white transition-all duration-200 ease-in-out"
>
    {{-- Logo / app name --}}
    <div class="flex items-center gap-3 border-b border-white/10 px-4 py-5">
        <img src="{{ asset('images/logo-pln.png') }}" alt="Logo PLN" class="h-8 w-8 shrink-0 object-contain">
        <div x-show="$store.ui.sidebarExpanded" x-transition.opacity class="min-w-0">
            <a href="{{ route('dashboard') }}" class="whitespace-nowrap text-lg font-bold tracking-wide text-white hover:text-white/80">
                PLN UIT JBT
            </a>
            <p class="whitespace-nowrap text-xs text-white/50">Dashboard Kinerja</p>
        </div>
    </div>

    {{-- Nav links --}}
    <nav class="flex-1 px-3 py-4 flex flex-col gap-1">
        {{-- Dashboard — semua role --}}
        <a href="{{ route('dashboard') }}"
           class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition
                  {{ request()->routeIs('dashboard') ? 'bg-accent text-white' : 'text-white/80 hover:bg-white/10 hover:text-white' }}">
            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
            </svg>
            <span x-show="$store.ui.sidebarExpanded" x-transition.opacity class="whitespace-nowrap">Dashboard</span>
        </a>

        @can('dataset.view')
        <a href="{{ route('datasets.index') }}"
           class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition
                  {{ request()->routeIs('datasets.*') ? 'bg-accent text-white' : 'text-white/80 hover:bg-white/10 hover:text-white' }}">
            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 2 1 3 3 3h10c2 0 3-1 3-3V7M4 7c0-2 1-3 3-3h10c2 0 3 1 3 3M4 7h16M9 11h6"/>
            </svg>
            <span x-show="$store.ui.sidebarExpanded" x-transition.opacity class="whitespace-nowrap">Datasets</span>
        </a>
        @endcan

        @can('dataset.import')
        <a href="{{ route('datasets.create') }}"
           class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition
                  {{ request()->routeIs('datasets.create') ? 'bg-accent text-white' : 'text-white/80 hover:bg-white/10 hover:text-white' }}">
            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
            </svg>
            <span x-show="$store.ui.sidebarExpanded" x-transition.opacity class="whitespace-nowrap">Upload Data</span>
        </a>
        @endcan

        @can('audit.view')
        <a href="{{ route('audit-logs.index') }}"
           class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition
                  {{ request()->routeIs('audit-logs.*') ? 'bg-accent text-white' : 'text-white/80 hover:bg-white/10 hover:text-white' }}">
            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <span x-show="$store.ui.sidebarExpanded" x-transition.opacity class="whitespace-nowrap">Audit Log</span>
        </a>
        @endcan

        @can('user.view')
        <a href="{{ route('users.index') }}"
           class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition
                  {{ request()->routeIs('users.*') ? 'bg-accent text-white' : 'text-white/80 hover:bg-white/10 hover:text-white' }}">
            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
            </svg>
            <span x-show="$store.ui.sidebarExpanded" x-transition.opacity class="whitespace-nowrap">Manage Users</span>
        </a>
        @endcan

        {{-- Divider --}}
        <hr class="my-2 border-white/10">

        @can('report.export')
        <span class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-white/40 pointer-events-none select-none">
            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <span x-show="$store.ui.sidebarExpanded" x-transition.opacity class="flex w-full items-center whitespace-nowrap">
                Reports
                <span class="ml-auto rounded-full bg-highlight/20 px-1.5 py-0.5 text-xs font-semibold text-highlight">Segera</span>
            </span>
        </span>
        @endcan

        @can('dashboard.update')
        <span class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-white/40 pointer-events-none select-none">
            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
            </svg>
            <span x-show="$store.ui.sidebarExpanded" x-transition.opacity class="flex w-full items-center whitespace-nowrap">
                Dashboard Builder
                <span class="ml-auto rounded-full bg-highlight/20 px-1.5 py-0.5 text-xs font-semibold text-highlight">Segera</span>
            </span>
        </span>
        @endcan
    </nav>

    {{-- User info + logout --}}
    <div class="border-t border-white/10 px-4 py-4">
        <div class="flex items-center gap-3">
            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-accent text-xs font-bold text-white">
                {{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
            </div>
            <div x-show="$store.ui.sidebarExpanded" x-transition.opacity class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-white">{{ auth()->user()->name }}</p>
                @if (auth()->user()->role)
                    <p class="truncate text-xs text-white/50">{{ auth()->user()->role->name }}</p>
                @endif
            </div>
        </div>
        <form method="POST" action="{{ route('logout') }}" class="mt-3"
              onsubmit="return confirm('Yakin ingin logout?');">
            @csrf
            <button type="submit" x-show="$store.ui.sidebarExpanded" x-transition.opacity
                    class="w-full rounded-lg px-3 py-1.5 text-left text-xs font-medium text-white/60 hover:bg-white/10 hover:text-white transition">
                Log out
            </button>
        </form>
    </div>
</aside>