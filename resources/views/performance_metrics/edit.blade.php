@extends('layouts.app')

@section('title', 'Edit Metric')

@section('content')
<div class="mx-auto max-w-2xl px-4 py-8">
    <a href="{{ route('datasets.show', $metric->dataset_id) }}" class="text-sm text-indigo-600 hover:underline">&larr; Kembali ke dataset</a>

    <div class="mt-4 rounded-lg bg-white p-6 shadow">
        <h1 class="text-2xl font-bold text-primary">Edit Metric</h1>

        {{-- Info read-only --}}
        <dl class="mt-3 grid grid-cols-2 gap-x-8 gap-y-2 text-sm text-gray-600">
            <div>
                <dt class="font-semibold text-gray-800">Dataset</dt>
                <dd>
                    <a href="{{ route('datasets.show', $metric->dataset_id) }}" class="text-indigo-600 hover:underline">
                        {{ $metric->dataset?->original_name ?? $metric->dataset_id }}
                    </a>
                </dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-800">Divisi</dt>
                <dd>{{ $metric->division?->name ?? '-' }}</dd>
            </div>
        </dl>

        @if ($errors->any())
            <div class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('performance_metrics.update', $metric) }}" class="mt-5 flex flex-col gap-4">
            @csrf
            @method('PATCH')

            <div>
                <label for="metric_name" class="block text-sm font-medium text-gray-700">Nama Metric</label>
                <input id="metric_name" type="text" name="metric_name"
                       value="{{ old('metric_name', $metric->metric_name) }}"
                       required maxlength="255"
                       class="mt-1 block w-full rounded-md border-gray-300 text-sm">
            </div>

            <div>
                <label for="value" class="block text-sm font-medium text-gray-700">Value</label>
                <input id="value" type="number" name="value" step="any"
                       value="{{ old('value', $metric->value) }}"
                       required
                       class="mt-1 block w-full rounded-md border-gray-300 text-sm">
            </div>

            <div>
                <label for="period" class="block text-sm font-medium text-gray-700">Period <span class="text-gray-400">(YYYY-MM-DD)</span></label>
                <input id="period" type="date" name="period"
                       value="{{ old('period', is_string($metric->period) ? substr($metric->period, 0, 10) : $metric->period?->format('Y-m-d')) }}"
                       required
                       class="mt-1 block w-full rounded-md border-gray-300 text-sm">
            </div>

            <div class="flex items-center gap-3">
                <button type="submit"
                        class="rounded bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                    Simpan Perubahan
                </button>
                <a href="{{ route('datasets.show', $metric->dataset_id) }}"
                   class="text-sm text-gray-600 hover:underline">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection
