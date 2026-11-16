@props(['status'])

@php
    $styles = [
        'UPLOADING'  => [
            'pill' => 'border-sky-200/80 bg-sky-50/80 text-sky-800',
            'dot'  => 'bg-sky-500 animate-pulse',
        ],
        'VALIDATING' => [
            'pill' => 'border-amber-200/80 bg-amber-50/80 text-amber-800',
            'dot'  => 'bg-amber-500 animate-pulse',
        ],
        'READY'      => [
            'pill' => 'border-slate-200/80 bg-white text-slate-700',
            'dot'  => 'bg-emerald-500',
        ],
        'FAILED'     => [
            'pill' => 'border-slate-200/80 bg-white text-slate-700',
            'dot'  => 'bg-rose-500',
        ],
        'ARCHIVED'   => [
            'pill' => 'border-slate-200/80 bg-white text-slate-700',
            'dot'  => 'bg-slate-400',
        ],
    ];
    $badgeStyle = $styles[$status] ?? [
        'pill' => 'border-slate-200/80 bg-white text-slate-700',
        'dot'  => 'bg-slate-400',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium shadow-2xs '.$badgeStyle['pill']]) }}>
    <span class="h-1.5 w-1.5 rounded-full {{ $badgeStyle['dot'] }}" aria-hidden="true"></span>
    {{ \App\Enums\DatasetStatus::tryFrom($status)?->label() ?? $status }}
</span>
