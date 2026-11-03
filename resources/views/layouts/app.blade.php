<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-surface antialiased text-slate-700">
@auth
    <div class="h-full" x-data
         x-effect="document.body.classList.toggle('overflow-hidden', $store.ui.sidebarExpanded && window.innerWidth < 1024)"
         @resize.window="$store.ui.sidebarExpanded = window.innerWidth >= 1024">
        <x-sidebar />
        <div
            x-show="$store.ui.sidebarExpanded"
            x-cloak
            @click="$store.ui.sidebarExpanded = false"
            class="fixed inset-0 z-40 bg-slate-900/40 lg:hidden"
            aria-hidden="true"
        ></div>

        <div
            class="app-content flex min-h-full flex-col overflow-auto transition-all duration-200 ease-in-out"
            :style="`margin-left: ${$store.ui.sidebarExpanded ? '16rem' : '4rem'}`"
        >
            <header class="sticky top-0 z-30 flex min-h-[78px] items-center justify-between gap-4 border-b border-slate-200 bg-white px-5 sm:px-8">
                <div class="flex items-center gap-3">
                    <button type="button"
                            class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-slate-300 bg-white text-primary lg:hidden"
                            @click="$store.ui.sidebarExpanded = !$store.ui.sidebarExpanded"
                            :aria-expanded="$store.ui.sidebarExpanded"
                            aria-controls="main-sidebar"
                            aria-label="Buka menu">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M4 6h16M4 12h16M4 18h16" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                    <p class="text-sm font-bold text-primary">@yield('title', 'Dashboard')</p>
                </div>
            </header>

            <main class="flex-1 px-5 py-6 sm:px-8 sm:py-8">
                @yield('content')
            </main>
        </div>
    </div>
@else
    <main class="min-h-full">
        @yield('content')
    </main>
@endauth
</body>
</html>
