@props(['widget' => [], 'data' => null])

{{-- Widget dataset gagal (server-side): jumlah Dataset status FAILED. --}}
@php
    $count = (int) ($data ?? 0);
@endphp

<div class="mt-8 rounded-lg bg-white p-6 shadow">
    <h3 class="text-lg font-bold text-primary">{{ $widget['label'] ?? 'Dataset Gagal' }}</h3>

    <div class="mt-4 rounded-lg bg-surface p-5">
        <p class="text-sm text-gray-600">Jumlah dataset berstatus FAILED</p>
        <p class="mt-1 text-2xl font-bold {{ $count > 0 ? 'text-red-600' : 'text-primary' }}">{{ $count }}</p>
        <a href="{{ route('datasets.index', ['status' => App\Enums\DatasetStatus::FAILED->value]) }}"
           class="mt-2 inline-block text-sm text-accent hover:underline">
            Lihat daftar dataset
        </a>
    </div>
</div>
