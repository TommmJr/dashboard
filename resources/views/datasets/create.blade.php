@extends('layouts.app')

@section('title', 'Upload Dataset')

@section('content')
<div class="mx-auto max-w-2xl px-4 py-8">
    <a href="{{ route('datasets.index') }}" class="text-sm text-indigo-600 hover:underline">&larr; Kembali ke daftar</a>

    <div class="mt-4 rounded-lg bg-white p-6 shadow">
        <h1 class="text-xl font-bold text-gray-900">Upload Dataset Baru</h1>
        <p class="mt-2 text-sm text-gray-600">
            Gunakan template generik. Kolom wajib: <code class="rounded bg-gray-100 px-1">metric_name</code>,
            <code class="rounded bg-gray-100 px-1">value</code> (numerik),
            <code class="rounded bg-gray-100 px-1">period</code> (format YYYY-MM-DD).
        </p>

        <a href="{{ route('datasets.template') }}"
           class="mt-3 inline-block rounded bg-gray-700 px-3 py-2 text-sm font-semibold text-white hover:bg-gray-800">
            Unduh Template (.xlsx)
        </a>

        @if ($errors->any())
            <div class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
                {{ $errors->first('file') }}
            </div>
        @endif

        <form method="POST" action="{{ route('datasets.store') }}" enctype="multipart/form-data" class="mt-5 flex flex-col gap-4">
            @csrf
            <input type="file" name="file" required accept=".xlsx,.csv"
                   class="block w-full rounded border border-gray-300 p-2 text-sm">
            <button type="submit"
                    class="rounded bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                Upload &amp; Impor
            </button>
        </form>
    </div>
</div>
@endsection
