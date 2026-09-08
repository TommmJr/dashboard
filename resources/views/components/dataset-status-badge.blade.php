@props(['status'])

@php
    $colors = [
        'UPLOADING'  => 'bg-gray-200 text-gray-700',
        'VALIDATING' => 'bg-yellow-100 text-yellow-800',
        'READY'      => 'bg-green-100 text-green-800',
        'FAILED'     => 'bg-red-100 text-red-800',
        'ARCHIVED'   => 'bg-gray-400 text-white',
    ];
    $class = $colors[$status] ?? 'bg-gray-200 text-gray-700';
@endphp

<span {{ $attributes->merge(['class' => "rounded-full px-2 py-1 text-xs font-semibold {$class}"]) }}>
    {{ $status }}
</span>
