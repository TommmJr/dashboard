{{--
    Halaman index laporan — pilihan export yang tersedia.
    Gate: report.export + dataset.view (di route).
    Audit export juga butuh audit.view — ditampilkan kondisional via @can.
--}}
@extends('layouts.app')

@section('title', 'Reports')

@section('content')
<div class="mx-auto max-w-5xl px-4 py-8">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">Reports</h1>
        <span class="inline-flex items-center gap-1.5 rounded-full border border-[#0b6175]/15 bg-[#e6f4f6] px-2.5 py-1 text-[11px] font-semibold text-[#0b6175]">
            Format Excel (.xlsx)
        </span>
    </div>

    <div class="mt-6 grid gap-5 sm:grid-cols-2">

        {{-- Ringkasan semua dataset --}}
        <div class="ibs-card animate-entrance stagger-1 p-5">
            <div class="flex items-start gap-4">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#e6f4f6] text-[#0b6175]">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 2 1 3 3 3h10c2 0 3-1 3-3V7M4 7c0-2 1-3 3-3h10c2 0 3 1 3 3M4 7h16M9 11h6"/>
                    </svg>
                </div>
                <div class="flex-1">
                    <h2 class="ibs-section-title">Ringkasan Dataset</h2>
                    <p class="mt-1 text-xs text-slate-500">Status, ukuran, pengunggah, dan pembaruan dataset.</p>
                    <a href="{{ route('reports.dataset-summary') }}"
                       class="mt-4 inline-flex items-center gap-1.5 rounded-lg border border-slate-200/90 bg-white px-3 py-1.5 text-xs font-semibold text-[#0b6175] shadow-2xs transition-all hover:border-[#0b6175]/40 hover:bg-[#e6f4f6]/60">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="7 10 12 15 17 10"/>
                            <line x1="12" y1="15" x2="12" y2="3"/>
                        </svg>
                        Unduh (.xlsx)
                    </a>
                </div>
            </div>
        </div>

        {{-- Audit log export --}}
        @can('audit.view')
        <div class="ibs-card animate-entrance stagger-2 p-5">
            <div class="flex items-start gap-4">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#e6f4f6] text-[#0b6175]">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <div class="flex-1">
                    <h2 class="ibs-section-title">Audit Log</h2>
                    <p class="mt-1 text-xs text-slate-500">Jejak audit rentang tanggal (maksimal 90 hari).</p>
                    <form method="GET" action="{{ route('reports.audit-export') }}" class="mt-4 flex flex-wrap items-end gap-2">
                        <div>
                            <label class="mb-1 block text-[11px] font-medium text-slate-500" for="audit_from">Dari</label>
                            <input id="audit_from" type="date" name="from" value="{{ request('from') }}"
                                   class="h-8 rounded-lg border border-slate-200 bg-white px-2.5 font-sans tabular-nums text-xs text-slate-700 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary" required>
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-medium text-slate-500" for="audit_to">Sampai</label>
                            <input id="audit_to" type="date" name="to" value="{{ request('to') }}"
                                   class="h-8 rounded-lg border border-slate-200 bg-white px-2.5 font-sans tabular-nums text-xs text-slate-700 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary" required>
                        </div>
                        <button type="submit" class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-slate-200/90 bg-white px-3 text-xs font-semibold text-[#0b6175] shadow-2xs transition-all hover:border-[#0b6175]/40 hover:bg-[#e6f4f6]/60">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="7 10 12 15 17 10"/>
                                <line x1="12" y1="15" x2="12" y2="3"/>
                            </svg>
                            Unduh (.xlsx)
                        </button>
                    </form>
                </div>
            </div>
        </div>
        @endcan

        {{-- Validasi per dataset --}}
        <div class="ibs-card animate-entrance stagger-3 p-5 sm:col-span-2">
            <div class="flex items-start gap-4">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#e6f4f6] text-[#0b6175]">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                    </svg>
                </div>
                <div class="flex-1">
                    <h2 class="ibs-section-title">Laporan Validasi Dataset</h2>
                    <p class="mt-1 text-xs text-slate-500">Baris kesalahan dan peringatan hasil validasi impor.</p>

                    @if ($datasets->isEmpty())
                        <p class="mt-3 text-xs text-slate-400">Belum ada dataset tersedia.</p>
                    @else
                        <div class="mt-4 flex flex-wrap items-end gap-2">
                            <div>
                                <label class="mb-1 block text-[11px] font-medium text-slate-500" for="dataset_select">Pilih dataset</label>
                                <select id="dataset_select" class="h-8 rounded-lg border border-slate-200 bg-white px-3 font-sans tabular-nums text-xs text-slate-700 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                                    @foreach ($datasets as $dataset)
                                        <option value="{{ route('reports.validation-export', $dataset) }}">
                                            {{ $dataset->original_name }}
                                            ({{ $dataset->division?->name ?? 'Tanpa divisi' }},
                                            {{ $dataset->created_at->format('d/m/Y') }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <a id="validation_link" href="{{ route('reports.validation-export', $datasets->first()) }}"
                               class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-slate-200/90 bg-white px-3 text-xs font-semibold text-[#0b6175] shadow-2xs transition-all hover:border-[#0b6175]/40 hover:bg-[#e6f4f6]/60">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                    <polyline points="7 10 12 15 17 10"/>
                                    <line x1="12" y1="15" x2="12" y2="3"/>
                                </svg>
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
