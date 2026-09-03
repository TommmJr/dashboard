{{--
    Layout utama aplikasi (template yang dipakai oleh semua halaman).
    - <head> memuat @vite untuk CSS + JS (Tailwind CSS & Alpine).
    - @yield('content') akan diganti oleh konten tiap halaman.
--}}
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-gray-100 antialiased">
    <main class="min-h-full">
        @yield('content')
        <div class="fixed bottom-0 left-0 w-full bg-gray-100 py-2 text-center text-sm text-gray-500">
            &copy; {{ date('Y') }} My Application. All rights reserved.
        </div>
    </main>
</body>
</html>
