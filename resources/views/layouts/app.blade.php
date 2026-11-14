<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-[#f4f8fa] text-slate-800 antialiased">
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
            <header class="sticky top-0 z-30 flex h-16 items-center justify-between border-b border-slate-200/80 bg-white/95 px-5 backdrop-blur-xs sm:px-8">
                <div class="flex min-w-0 items-center gap-3">
                    <button type="button"
                            class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 transition hover:bg-slate-50 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8] lg:hidden"
                            @click="$store.ui.sidebarExpanded = !$store.ui.sidebarExpanded"
                            :aria-expanded="$store.ui.sidebarExpanded"
                            aria-controls="main-sidebar"
                            aria-label="Buka menu">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M4 6h16M4 12h16M4 18h16" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="truncate text-base font-semibold tracking-tight text-slate-900">
                            @yield('page_heading', 'Dashboard')
                        </span>
                        @hasSection('breadcrumbs')
                            @yield('breadcrumbs')
                        @else
                            <nav aria-label="Breadcrumb" id="header-breadcrumb" class="hidden sm:flex items-center gap-1.5 border-l border-slate-200 pl-3 text-xs">
                                <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'font-semibold text-[#0b6175]' : 'text-slate-500 hover:text-[#16a3b8]' }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif>Beranda</a>
                                @if (! request()->routeIs('dashboard'))
                                    <span class="text-slate-300" aria-hidden="true">/</span>
                                    <span class="truncate font-semibold text-[#0b6175]" aria-current="page">@yield('title')</span>
                                @endif
                            </nav>
                        @endif
                        @yield('header_meta')
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    @yield('header_actions')
                    @php
                        $contextLabel = auth()->user()->division?->name ?? auth()->user()->role?->name;
                    @endphp
                    @if ($contextLabel)
                        <span id="topbar-context-badge" class="hidden sm:inline-flex items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-medium text-slate-600">
                            <span class="h-1.5 w-1.5 rounded-full bg-[#16a3b8]" aria-hidden="true"></span>
                            <span>{{ $contextLabel }}</span>
                        </span>
                    @endif
                </div>
            </header>

            <main class="flex-1 px-5 py-6 sm:px-8 sm:py-8">
                @php
                    $flashBanners = [
                        'success' => [
                            'role' => 'status',
                            'card' => 'border-emerald-200/90 bg-emerald-50/70 text-emerald-900',
                            'icon' => 'text-emerald-600',
                            'button' => 'text-emerald-700 hover:bg-emerald-100/80 hover:text-emerald-900 focus-visible:ring-emerald-500',
                            'path' => 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
                        ],
                        'error' => [
                            'role' => 'alert',
                            'card' => 'border-rose-200/90 bg-rose-50/70 text-rose-900',
                            'icon' => 'text-rose-600',
                            'button' => 'text-rose-700 hover:bg-rose-100/80 hover:text-rose-900 focus-visible:ring-rose-500',
                            'path' => 'M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z',
                        ],
                        'warning' => [
                            'role' => 'status',
                            'card' => 'border-amber-200/90 bg-amber-50/70 text-amber-900',
                            'icon' => 'text-amber-600',
                            'button' => 'text-amber-700 hover:bg-amber-100/80 hover:text-amber-900 focus-visible:ring-amber-500',
                            'path' => 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z',
                        ],
                        'status' => [
                            'role' => 'status',
                            'card' => 'border-cyan-200/90 bg-cyan-50/70 text-cyan-900',
                            'icon' => 'text-cyan-700',
                            'button' => 'text-cyan-700 hover:bg-cyan-100/80 hover:text-cyan-900 focus-visible:ring-[#16a3b8]',
                            'path' => 'm11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z',
                        ],
                    ];
                @endphp
                @foreach ($flashBanners as $flashKey => $flashStyle)
                    @if (session($flashKey))
                        <div
                            x-data="{ dismissed: false }"
                            x-show="!dismissed"
                            role="{{ $flashStyle['role'] }}"
                            class="ibs-card mb-4 flex items-start justify-between gap-3 px-4 py-3 text-xs font-medium {{ $flashStyle['card'] }}"
                        >
                            <div class="flex min-w-0 items-start gap-2.5">
                                <svg class="mt-0.5 h-4 w-4 shrink-0 {{ $flashStyle['icon'] }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="{{ $flashStyle['path'] }}" />
                                </svg>
                                <span class="leading-relaxed">{{ session($flashKey) }}</span>
                            </div>
                            <button
                                type="button"
                                @click="dismissed = true"
                                aria-label="Tutup notifikasi"
                                class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-lg transition-colors focus-visible:outline-none focus-visible:ring-2 {{ $flashStyle['button'] }}"
                            >
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M6 18 18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    @endif
                @endforeach

                @yield('content')
            </main>
        </div>
    </div>
@else
    <main class="min-h-full">
        @yield('content')
    </main>
@endauth
@stack('scripts')
</body>
</html>
