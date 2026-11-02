@extends('layouts.app')

@section('title', 'Dashboard Builder')

@section('content')
<div class="mx-auto max-w-7xl">
    <div class="mb-6 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <p class="text-xs font-medium text-slate-500">Beranda / Dashboard Builder</p>
            <h1 class="mt-2 text-2xl font-bold text-primary">Dashboard Builder</h1>
            <p class="mt-1 text-sm text-slate-500">Kelola susunan, lebar kolom, urutan, dan konfigurasi widget dashboard per role atau divisi.</p>
        </div>
        <div>
            <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-2 rounded border border-slate-200 bg-white px-3.5 py-2 text-sm font-semibold text-primary transition hover:border-accent hover:text-accent">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M19 12H5m7 7-7-7 7-7" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                Kembali ke Dashboard
            </a>
        </div>
    </div>

    <x-widgets.layout-editor-placeholder :widget="['label' => 'Editor Tata Letak Dashboard']" :data="$builderData" />
</div>
@endsection
