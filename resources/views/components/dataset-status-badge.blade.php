@props(['status'])

@php
    $dotColors = [
        'UPLOADING'  => 'bg-blue-500',
        'VALIDATING' => 'bg-amber-500',
        'READY'      => 'bg-emerald-500',
        'FAILED'     => 'bg-rose-500',
        'ARCHIVED'   => 'bg-slate-400',
    ];
    $dotClass = $dotColors[$status] ?? 'bg-slate-400';
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-full border border-slate-200/80 bg-white px-2.5 py-0.5 text-xs font-medium text-slate-700 shadow-2xs']) }}>
    <span class="h-1.5 w-1.5 rounded-full {{ $dotClass }}" aria-hidden="true"></span>
    {{ \App\Enums\DatasetStatus::tryFrom($status)?->label() ?? $status }}
</span>
