<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-surface antialiased">
    @auth
    <div class="h-full" x-data>
        <x-sidebar />

        {{-- Konten di-push (margin-left) mengikuti lebar sidebar, bukan
             ditumpuk sidebar (overlay). Transition durasinya disamakan
             dengan transition sidebar biar geraknya barengan. --}}
        <div
            class="flex h-full flex-col overflow-auto transition-all duration-200 ease-in-out"
            :style="`margin-left: ${$store.ui.sidebarExpanded ? '16rem' : '4rem'}`"
        >
            <main class="flex-1 px-6 py-8">
                @yield('content')
            </main>
            <footer class="border-t border-muted py-3 text-center text-xs text-gray-400">
                &copy; {{ date('Y') }} PLN UIT JBT. All rights reserved.
            </footer>
        </div>
    </div>
    @else
    {{-- Guest pages (login) — layout polos tanpa sidebar --}}
    <main class="min-h-full">
        @yield('content')
    </main>
    @endauth
</body>
</html>