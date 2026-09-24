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
            <header class="sticky top-0 z-30 flex min-h-[78px] items-center justify-between gap-4 border-b border-slate-200 bg-white/95 px-5 backdrop-blur sm:px-8">
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

                <div class="flex items-center gap-3">
                    <div class="hidden text-right sm:block">
                        <p class="text-sm font-semibold text-primary">{{ auth()->user()->name }}</p>
                        @if (auth()->user()->role)
                            <p class="mt-0.5 text-xs text-slate-500">{{ auth()->user()->role->name }}</p>
                        @endif
                    </div>
                    <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-primary text-sm font-bold text-white" aria-hidden="true">
                        {{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
                    </span>
                    <form method="POST" action="{{ route('logout') }}" onsubmit="return confirm('Yakin ingin logout?');">
                        @csrf
                        <button type="submit" class="inline-flex h-10 items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-primary transition hover:border-accent hover:text-accent">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M10 17l5-5-5-5M15 12H3m10-9h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            <span class="hidden sm:inline">Keluar</span>
                            <span class="sr-only sm:hidden">Keluar</span>
                        </button>
                    </form>
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
