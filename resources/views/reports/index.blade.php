{{--
    Halaman index laporan — pilihan export yang tersedia.
    Gate: report.export + dataset.view (di route).
    Audit export juga butuh audit.view — ditampilkan kondisional via @can.
--}}
@extends('layouts.app')

@section('title', 'Reports')

@section('content')
<div class="mx-auto max-w-5xl px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-900">Reports</h1>
    <p class="mt-1 text-sm text-gray-600">Unduh laporan data dalam format Excel (.xlsx).</p>

    @if (session('success'))
        <div class="mt-4 rounded-md bg-green-50 p-3 text-sm text-green-700">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    <div class="mt-6 grid gap-6 sm:grid-cols-2">

        {{-- Ringkasan semua dataset --}}
        <div class="rounded-lg bg-white p-6 shadow">
            <div class="flex items-start gap-4">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-indigo-50">
                    <svg class="h-5 w-5 text-indigo-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 2 1 3 3 3h10c2 0 3-1 3-3V7M4 7c0-2 1-3 3-3h10c2 0 3 1 3 3M4 7h16M9 11h6"/>
                    </svg>
                </div>
                <div class="flex-1">
                    <h2 class="text-base font-semibold text-gray-900">Ringkasan Dataset</h2>
                    <p class="mt-1 text-sm text-gray-500">Daftar semua dataset beserta status, ukuran, pengunggah, dan tanggal terakhir diperbarui.</p>
                    <a href="{{ route('reports.dataset-summary') }}"
                       class="mt-4 inline-block rounded bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-700">
                        Unduh (.xlsx)
                    </a>
                </div>
            </div>
        </div>

        {{-- Audit log export --}}
        @can('audit.view')
        <div class="rounded-lg bg-white p-6 shadow">
            <div class="flex items-start gap-4">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-amber-50">
                    <svg class="h-5 w-5 text-amber-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <div class="flex-1">
                    <h2 class="text-base font-semibold text-gray-900">Audit Log</h2>
                    <p class="mt-1 text-sm text-gray-500">Riwayat perubahan data dalam rentang tanggal tertentu (maks. 90 hari).</p>
                    <form method="GET" action="{{ route('reports.audit-export') }}" class="mt-4 flex flex-wrap items-end gap-2">
                        <div>
                            <label class="block text-xs text-gray-600" for="audit_from">Dari</label>
                            <input id="audit_from" type="date" name="from" value="{{ request('from') }}"
                                   class="rounded-md border-gray-300 text-sm" required>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-600" for="audit_to">Sampai</label>
                            <input id="audit_to" type="date" name="to" value="{{ request('to') }}"
                                   class="rounded-md border-gray-300 text-sm" required>
                        </div>
                        <button class="rounded bg-amber-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-amber-700">
                            Unduh (.xlsx)
                        </button>
                    </form>
                </div>
            </div>
        </div>
        @endcan

        {{-- Validasi per dataset --}}
        <div class="rounded-lg bg-white p-6 shadow sm:col-span-2">
            <div class="flex items-start gap-4">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-red-50">
                    <svg class="h-5 w-5 text-red-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                    </svg>
                </div>
                <div class="flex-1">
                    <h2 class="text-base font-semibold text-gray-900">Laporan Validasi Dataset</h2>
                    <p class="mt-1 text-sm text-gray-500">Detail baris yang ditolak (FATAL) atau diperingatkan (WARNING) saat import.</p>

                    @if ($datasets->isEmpty())
                        <p class="mt-3 text-sm text-gray-400">Belum ada dataset tersedia.</p>
                    @else
                        <div class="mt-4 flex flex-wrap items-end gap-2">
                            <div>
                                <label class="block text-xs text-gray-600" for="dataset_select">Pilih dataset</label>
                                <select id="dataset_select" class="rounded-md border-gray-300 text-sm">
                                    @foreach ($datasets as $dataset)
                                        <option value="{{ route('reports.validation-export', $dataset) }}">
                                            {{ $dataset->original_name }}
                                            ({{ $dataset->division?->name ?? '-' }},
                                            {{ $dataset->created_at->format('d/m/Y') }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <a id="validation_link" href="{{ route('reports.validation-export', $datasets->first()) }}"
                               class="rounded bg-red-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-red-700">
                                Unduh (.xlsx)
                            </a>
                        </div>
                        <script>
                            (function () {
                                var sel = document.getElementById('dataset_select');
                                var link = document.getElementById('validation_link');
                                sel.addEventListener('change', function () {
                                    link.href = sel.value;
                                });
                            })();
                        </script>
                    @endif
                </div>
            </div>
        </div>

    </div>
</div>
@endsection
