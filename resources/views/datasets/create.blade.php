@extends('layouts.app')

@section('title', 'Upload Dataset')

@section('content')
<div class="mx-auto max-w-2xl px-4 py-8">
    {{-- Tombol Navigasi Kembali --}}
    <a href="{{ route('datasets.index') }}" 
       class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 shadow-2xs transition-all duration-150 hover:border-slate-300 hover:bg-slate-50 hover:text-slate-900">
        <svg class="h-3.5 w-3.5 stroke-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 12H5M12 19l-7-7 7-7"/>
        </svg>
        Kembali ke daftar
    </a>

    {{-- Pesan Status Flash --}}
    @if ($errors->any())
        <div class="mt-4 rounded-lg border border-rose-200/80 border-l-4 border-l-rose-500 bg-rose-50/40 p-3.5 text-sm text-rose-700 shadow-2xs">
            {{ $errors->first() }}
        </div>
    @endif

    @if (session('success'))
        <div class="mt-4 rounded-lg border border-emerald-200/80 border-l-4 border-l-emerald-500 bg-emerald-50/40 p-3.5 text-sm text-emerald-800 shadow-2xs">
            {{ session('success') }}
        </div>
    @endif

    @if (session('error'))
        <div class="mt-4 rounded-lg border border-rose-200/80 border-l-4 border-l-rose-500 bg-rose-50/40 p-3.5 text-sm text-rose-700 shadow-2xs">
            {{ session('error') }}
        </div>
    @endif

    {{-- KARTU 1: UPLOAD DATASET STANDAR --}}
    <div class="mt-4 rounded-xl border border-slate-200/80 bg-white p-6 sm:p-7 shadow-2xs">
        <div class="flex items-start justify-between border-b border-slate-100 pb-4">
            <div>
                <h1 class="text-lg font-bold tracking-tight text-slate-900">Upload Dataset</h1>
                <p class="mt-0.5 text-xs text-slate-500">Unggah berkas metrik tabel kinerja divisi.</p>
            </div>
            <div class="flex items-center gap-1.5">
                <span class="rounded border border-slate-200 bg-slate-50 px-2 py-0.5 font-mono text-[10px] font-semibold text-slate-600">.XLSX</span>
                <span class="rounded border border-slate-200 bg-slate-50 px-2 py-0.5 font-mono text-[10px] font-semibold text-slate-600">.CSV</span>
            </div>
        </div>

        <form method="POST" action="{{ route('datasets.store') }}" enctype="multipart/form-data" class="mt-5 flex flex-col gap-4">
            @csrf

            @if ($divisions->isNotEmpty())
                <div>
                    <label for="division_id" class="block text-xs font-semibold uppercase tracking-wider text-slate-500">
                        Divisi Pemilik Data
                    </label>
                    <select id="division_id" name="division_id" required
                            class="mt-1.5 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 shadow-2xs focus:border-cyan-600 focus:outline-none focus:ring-1 focus:ring-cyan-600">
                        <option value="">-- Pilih divisi --</option>
                        @foreach ($divisions as $division)
                            <option value="{{ $division->id }}" @selected(old('division_id') == $division->id)>
                                {{ $division->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @else
                <div class="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50/60 px-3.5 py-2.5 text-xs text-slate-600">
                    <span class="h-2 w-2 rounded-full bg-cyan-600"></span>
                    <span>Dataset akan tertaut ke divisi Anda: <strong class="text-slate-800">{{ $user->division?->name ?? 'Pusat' }}</strong></span>
                </div>
            @endif

            <div>
                <x-dropzone name="file" accept=".xlsx,.csv" required />
            </div>

            <div class="flex justify-end pt-2">
                <button type="submit"
                        class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-2xs transition-all duration-150 hover:border-cyan-500 hover:bg-cyan-50/40 hover:text-cyan-800 active:bg-cyan-100/60">
                    <svg class="h-4 w-4 text-cyan-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="17 8 12 3 7 8"/>
                        <line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                    Upload &amp; Impor
                </button>
            </div>
        </form>
    </div>

    {{-- KARTU 2: IMPORT LAPORAN KPI OTOMATIS --}}
    @if ($canImportKpi ?? false)
    <div class="mt-6 rounded-xl border border-slate-200/80 bg-white p-6 sm:p-7 shadow-2xs">
        <div class="flex items-start justify-between border-b border-slate-100 pb-4">
            <div>
                <h2 class="text-lg font-bold tracking-tight text-slate-900">Import Laporan KPI (Otomatis)</h2>
                <p class="mt-0.5 text-xs text-slate-500">Ekstraksi otomatis seluruh sheet unit laporan.</p>
            </div>
            <span class="rounded border border-cyan-200 bg-cyan-50 px-2.5 py-0.5 text-[10px] font-semibold text-cyan-800">
                Otomatis
            </span>
        </div>

        @if (session('kpi_failed') && count(session('kpi_failed')))
            <div class="mt-4 rounded-lg border border-rose-200/80 bg-rose-50/40 p-3.5 text-xs text-rose-800">
                <p class="font-semibold text-rose-900">Unit tidak dapat diproses:</p>
                <ul class="mt-1 list-disc pl-4 space-y-0.5 text-rose-700">
                    @foreach (session('kpi_failed') as $reason)
                        <li>{{ $reason }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('datasets.kpi-import') }}" enctype="multipart/form-data" class="mt-5 flex flex-col gap-4">
            @csrf

            <div>
                <label for="period" class="block text-xs font-semibold uppercase tracking-wider text-slate-500">
                    Periode Data
                </label>
                <input type="date" id="period" name="period" required value="{{ old('period') }}"
                       class="mt-1.5 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 shadow-2xs focus:border-cyan-600 focus:outline-none focus:ring-1 focus:ring-cyan-600 sm:w-60">

                @if ($kpiPeriods->isNotEmpty())
                    <div class="mt-2.5 flex items-start gap-2 rounded-lg border border-amber-200/70 bg-amber-50/40 p-2.5 text-xs text-amber-900">
                        <svg class="h-4 w-4 shrink-0 text-amber-600 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                        <span>Periode terdaftar: <strong>{{ $kpiPeriods->implode(', ') }}</strong>. Unggah ulang akan merevisi dataset.</span>
                    </div>
                @endif
            </div>

            <div>
                <x-dropzone name="file" accept=".xlsx" required />
            </div>

            <label class="flex items-center gap-2.5 text-xs text-slate-600">
                <input type="checkbox" name="confirm_archive" value="1" @checked(old('confirm_archive'))
                       class="h-4 w-4 rounded border-slate-300 text-cyan-600 focus:ring-cyan-500">
                <span>Konfirmasi revisi jika periode sudah terdaftar sebelumnya.</span>
            </label>

            <div class="flex justify-end pt-2">
                <button type="submit"
                        class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-2xs transition-all duration-150 hover:border-slate-400 hover:bg-slate-50 hover:text-slate-900 active:bg-slate-100">
                    <svg class="h-4 w-4 text-slate-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                    Import Otomatis
                </button>
            </div>
        </form>
    </div>
    @endif

    {{-- KARTU 3: IMPORT REKAPITULASI DATA ASET (TAB 1B & TAB 1A) --}}
    @if ($canUploadAsset ?? false)
    <div class="mt-6 rounded-xl border border-slate-200/80 bg-white p-6 sm:p-7 shadow-2xs">
        <div class="flex items-start justify-between border-b border-slate-100 pb-4">
            <div>
                <h2 class="text-lg font-bold tracking-tight text-slate-900">Upload Rekapitulasi Data Aset</h2>
                <p class="mt-0.5 text-xs text-slate-500">Unggah berkas rekapitulasi aset: Tab 1b (UPT) dan Tab 1a (UIT JBT).</p>
            </div>
            <div class="flex items-center gap-1.5">
                <span class="rounded border border-slate-200 bg-slate-50 px-2 py-0.5 font-mono text-[10px] font-semibold text-slate-600">.XLSX</span>
                <span class="rounded border border-slate-200 bg-slate-50 px-2 py-0.5 font-mono text-[10px] font-semibold text-slate-600">.CSV</span>
            </div>
        </div>

        <form method="POST" action="{{ route('datasets.asset-upload') }}" enctype="multipart/form-data" class="mt-5 flex flex-col gap-4">
            @csrf

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="file_1b" class="block text-xs font-semibold uppercase tracking-wider text-slate-500">
                        Berkas Tab 1b (Level UPT)
                    </label>
                    <div class="mt-1.5">
                        <x-dropzone name="file_1b" accept=".xlsx,.csv" required />
                    </div>
                </div>

                <div>
                    <label for="file_1a" class="block text-xs font-semibold uppercase tracking-wider text-slate-500">
                        Berkas Tab 1a (Level UIT JBT)
                    </label>
                    <div class="mt-1.5">
                        <x-dropzone name="file_1a" accept=".xlsx,.csv" required />
                    </div>
                </div>
            </div>

            <div class="flex justify-end pt-2">
                <button type="submit"
                        class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-2xs transition-all duration-150 hover:border-cyan-500 hover:bg-cyan-50/40 hover:text-cyan-800 active:bg-cyan-100/60">
                    <svg class="h-4 w-4 text-cyan-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="17 8 12 3 7 8"/>
                        <line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                    Upload &amp; Impor Aset
                </button>
            </div>
        </form>
    </div>

    {{-- KARTU 4: SINKRONISASI DATA ASET (GOOGLE SHEETS) --}}
    <div class="mt-6 rounded-xl border border-slate-200 bg-white p-6 sm:p-7 shadow-2xs">
        <div class="flex items-start justify-between border-b border-slate-100 pb-4">
            <div>
                <div class="flex items-center gap-2">
                    <h2 class="text-lg font-bold tracking-tight text-slate-900">Sinkronisasi Data Aset (Google Sheets)</h2>
                    <span class="rounded border border-slate-200 bg-slate-50 px-2 py-0.5 text-[10px] font-semibold tracking-wider text-slate-600">
                        VIEW-ONLY SYNC
                    </span>
                </div>
                <p class="mt-1 text-xs text-slate-500">
                    Tarik dan validasi otomatis Tab 1b (UPT) serta Tab 1a (UIT) langsung dari Google Sheets terkonfigurasi.
                </p>
            </div>
            @if (config('asset.target_month'))
                <span class="rounded border border-cyan-200 bg-cyan-50 px-2.5 py-1 text-xs font-semibold text-cyan-800">
                    {{ config('asset.target_month') }}
                </span>
            @endif
        </div>

        <form method="POST" action="{{ route('datasets.asset-sync') }}" x-data="{ syncing: false }" @submit="syncing = true" class="mt-5 flex items-center justify-between">
            @csrf
            <div class="text-xs text-slate-500">
                <span>Sumber: Google Sheets internal PLN UIT JBT terkonfigurasi.</span>
            </div>

            <button type="submit"
                    :disabled="syncing"
                    class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-2xs transition-all duration-150 hover:border-cyan-500 hover:bg-cyan-50/40 hover:text-cyan-800 active:bg-cyan-100/60 disabled:cursor-not-allowed disabled:opacity-60">
                <svg x-show="!syncing" class="h-4 w-4 text-cyan-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/>
                    <path d="M12 12v9"/>
                    <path d="m8 17 4 4 4-4"/>
                </svg>
                <svg x-show="syncing" x-cloak class="h-4 w-4 animate-spin text-cyan-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span x-show="!syncing">Tarik &amp; Sinkronkan Data</span>
                <span x-show="syncing" x-cloak>Menarik &amp; Memvalidasi Data...</span>
            </button>
        </form>
    </div>
    @endif
</div>
@endsection