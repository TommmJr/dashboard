{{--
    Halaman Dashboard (halaman utama setelah login).
    - Dilindungi middleware 'auth', jadi hanya user yang login yang bisa lihat.
    - Konten asli (grafik/widget) akan dibangun pada tahap Dashboard Builder.
    - Tombol logout memakai POST + @csrf (aman dari CSRF / clickjacking).
--}}
@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<nav class="bg-white shadow">
    <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3">
        <span class="font-semibold text-gray-900">Dashboard</span>
        <div class="flex items-center gap-4">
            <span class="text-sm text-gray-600">{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}" onsubmit="return confirm('Yakin ingin logout?');">
                @csrf
                <button type="submit" class="text-sm text-gray-600 hover:text-gray-900">Log out</button>
            </form>
        </div>
    </nav>

<div class="mx-auto max-w-7xl px-4 py-8">
    <div class="flex items-center gap-3">
        <h1 class="text-2xl font-bold text-gray-900">Welcome, {{ auth()->user()->name }}</h1>
        @if (auth()->user()->role)
            <span class="inline-flex rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800">{{ auth()->user()->role->name }}</span>
        @endif
    </div>
    <p class="mt-2 text-sm text-gray-600">You are signed in. Dashboard content will be built in later stages.</p>

    <div class="mt-6 flex flex-wrap gap-3">
        @can('user.view')
            <a href="{{ route('users.index') }}" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">Manage users</a>
        @endcan
        @can('dashboard.update')
            <span class="rounded-lg bg-gray-200 px-4 py-2 text-sm font-medium text-gray-500">Edit dashboard (menyusul, Tahap 6)</span>
        @endcan
        @can('dataset.import')
            <span class="rounded-lg bg-gray-200 px-4 py-2 text-sm font-medium text-gray-500">Upload data (menyusul, Tahap 4)</span>
        @endcan
    </div>

    {{-- Vertical Slice #1: Upload & Chart Demo --}}
    <div class="mt-10 grid grid-cols-1 gap-8 lg:grid-cols-3">
        {{-- Form Upload --}}
        <div class="p-6 bg-white rounded-lg shadow">
            <h3 class="text-lg font-bold mb-4">Upload Data Performance (Lite)</h3>
            <form action="{{ route('performance.upload') }}" method="POST" enctype="multipart/form-data" class="flex flex-col gap-4">
                @csrf
                <input type="file" name="file" class="border p-2 rounded w-full" accept=".csv">
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 font-medium">Upload CSV</button>
            </form>
            @if(session('success'))
                <div class="mt-2 text-green-600 text-sm">{{ session('success') }}</div>
            @endif
        </div>

        {{-- Area Grafik --}}
        <div class="lg:col-span-2 p-6 bg-white rounded-lg shadow">
            <h3 class="text-lg font-bold mb-4">Performance Trends</h3>
            <div class="relative h-64">
                <canvas id="performanceChart"></canvas>
            </div>
        </div>
    </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    async function loadChart() {
        try {
            const response = await fetch("{{ route('performance.data') }}");
            const data = await response.json();

            const ctx = document.getElementById('performanceChart').getContext('2d');
            if (window.myChart) { window.myChart.destroy(); }

            window.myChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: data.labels,
                    datasets: [{
                        label: 'Total Value',
                        data: data.values,
                        backgroundColor: 'rgba(59, 130, 246, 0.5)',
                        borderColor: 'rgb(59, 130, 246)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            });
        } catch (e) {
            console.error('Chart loading error:', e);
        }
    }

    // Load chart on page load
    document.addEventListener('DOMContentLoaded', loadChart);
</script>
@endsection
