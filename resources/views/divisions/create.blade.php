@extends('layouts.app')

@section('title', 'Tambah Divisi')

@section('content')
<div class="mx-auto max-w-2xl px-4 py-8">
    <a href="{{ route('divisions.index') }}" class="text-sm text-indigo-600 hover:underline">&larr; Kembali ke daftar</a>

    <div class="mt-4 rounded-lg bg-white p-6 shadow">
        <h1 class="text-2xl font-bold text-primary">Tambah Divisi Baru</h1>

        @if ($errors->any())
            <div class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('divisions.store') }}" class="mt-5 flex flex-col gap-4">
            @csrf

            <div>
                <label for="name" class="block text-sm font-medium text-gray-700">Nama Divisi</label>
                <input id="name" type="text" name="name" value="{{ old('name') }}" required maxlength="255"
                       class="mt-1 block w-full rounded-md border-gray-300 text-sm">
            </div>

            <div>
                <label for="description" class="block text-sm font-medium text-gray-700">
                    Deskripsi <span class="text-gray-400">(opsional)</span>
                </label>
                <textarea id="description" name="description" rows="3" maxlength="1000"
                          class="mt-1 block w-full rounded-md border-gray-300 text-sm">{{ old('description') }}</textarea>
            </div>

            <button type="submit"
                    class="rounded bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                Simpan
            </button>
        </form>
    </div>
</div>
@endsection
