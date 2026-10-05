@extends('layouts.app')

@section('title', 'Upload Dataset')

@section('content')
<div class="mx-auto max-w-2xl px-4 py-8">
    <a href="{{ route('datasets.index') }}" class="text-sm text-indigo-600 hover:underline">&larr; Kembali ke daftar</a>

    <div class="mt-4 rounded-lg bg-white p-6 shadow">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-bold text-gray-900">Upload Dataset Baru</h1>
        </div>
        <p class="mt-2 text-sm text-gray-600">
            Gunakan file Excel/CSV dengan kolom <code class="rounded bg-gray-100 px-1">metric_name</code>,
            <code class="rounded bg-gray-100 px-1">value</code> (numerik),
            <code class="rounded bg-gray-100 px-1">period</code> (format DD/MM/YYYY atau YYYY-MM-DD).
        </p>

        @if ($errors->any())
            <div class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
                {{ $errors->first() }}
            </div>
        @endif

        @if (session('success'))
            <div class="mt-4 rounded-md bg-green-50 p-3 text-sm text-green-700">{{ session('success') }}</div>
        @endif

        @if (session('error'))
            <div class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</div>
        @endif

        <form method="POST" action="{{ route('datasets.store') }}" enctype="multipart/form-data" class="mt-5 flex flex-col gap-4">
            @csrf
            @if ($divisions->isNotEmpty())
                <div>
                    <label for="division_id" class="block text-sm font-medium text-gray-700">Divisi Pemilik Data</label>
                    <select id="division_id" name="division_id" required
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                        <option value="">-- Pilih divisi --</option>
                        @foreach ($divisions as $division)
                            <option value="{{ $division->id }}" @selected(old('division_id') == $division->id)>
                                {{ $division->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @else
                <p class="text-sm text-gray-600">Dataset akan tertaut ke divisi Anda: <span class="font-semibold">{{ $user->division?->name }}</span></p>
            @endif
            <x-dropzone name="file" accept=".xlsx,.csv" required />
            <button type="submit"
                    class="rounded bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                Upload &amp; Impor
            </button>
        </form>
    </div>

    <div class="mt-4 rounded-lg bg-white p-6 shadow">
            <h2 class="text-lg font-bold text-gray-900">Import Laporan KPI dari Mentor (Otomatis)</h2>
            <p class="mt-2 text-sm text-gray-600">
                Upload file Excel laporan KPI apa adanya. Semua sheet unit menjadi dimensi pembanding
                dalam satu dataset milik divisi Perencanaan Pengusahaan. Isi <strong>periode data</strong> karena
                tulisan periode di dalam file sering tidak akurat.
            </p>

            @if (session('kpi_failed') && count(session('kpi_failed')))
                <div class="mt-3 rounded-md bg-red-50 p-3 text-sm text-red-700">
                    <p class="font-semibold">Unit gagal diproses:</p>
                    <ul class="mt-1 list-disc pl-5">
                        @foreach (session('kpi_failed') as $reason)
                            <li>{{ $reason }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('datasets.kpi-import') }}" enctype="multipart/form-data" class="mt-4 flex flex-col gap-4">
                @csrf
                <div>
                    <label for="period" class="block text-sm font-medium text-gray-700">Periode data</label>
                    <input type="date" id="period" name="period" required value="{{ old('period') }}"
                           class="mt-1 block rounded-md border-gray-300 text-sm">
                    @if ($kpiPeriods->isNotEmpty())
                        <div class="mt-2 rounded-md bg-amber-50 p-3 text-sm text-amber-800">
                            Periode yang sudah pernah diimpor: <span class="font-semibold">{{ $kpiPeriods->implode(', ') }}</span>.
                            Memilih periode yang sudah ada berarti merevisi data tersebut: dataset lamanya diarsipkan
                            otomatis (tetap tersimpan, tidak dihapus).
                        </div>
                    @endif
                </div>
                <x-dropzone name="file" accept=".xlsx" required />
                <label class="flex items-start gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="confirm_archive" value="1" @checked(old('confirm_archive'))
                           class="mt-1 rounded border-gray-300">
                    <span>Saya sudah memastikan periodenya benar, dan memahami bahwa import ini merevisi periode tersebut.</span>
                </label>
                <button type="submit"
                        class="self-start rounded bg-gray-700 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                    Import Otomatis
                </button>
            </form>
    </div>
</div>
@endsection
