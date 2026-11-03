@props([
    'keyExpr' => 'item.key',
    'sizeClass' => 'h-10 w-full',
])

<template x-if="paletteVisual({{ $keyExpr }}).type === 'bar'">
    <svg class="{{ $sizeClass }}" viewBox="0 0 64 36" fill="none" stroke="currentColor" aria-hidden="true">
        <line x1="6" y1="30" x2="58" y2="30" stroke="currentColor" stroke-width="1" stroke-opacity="0.25" />
        <rect x="10" y="16" width="8" height="14" rx="1" fill="currentColor" fill-opacity="0.45" />
        <rect x="22" y="10" width="8" height="20" rx="1" fill="currentColor" fill-opacity="0.7" />
        <rect x="34" y="18" width="8" height="12" rx="1" fill="currentColor" fill-opacity="0.45" />
        <rect x="46" y="6" width="8" height="24" rx="1" fill="currentColor" />
    </svg>
</template>
<template x-if="paletteVisual({{ $keyExpr }}).type === 'line'">
    <svg class="{{ $sizeClass }}" viewBox="0 0 64 36" fill="none" stroke="currentColor" aria-hidden="true">
        <line x1="6" y1="30" x2="58" y2="30" stroke="currentColor" stroke-width="1" stroke-opacity="0.25" stroke-dasharray="2 2" />
        <polyline points="8,26 22,14 38,20 56,8" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
        <circle cx="56" cy="8" r="2.5" fill="currentColor" />
    </svg>
</template>
<template x-if="paletteVisual({{ $keyExpr }}).type === 'donut'">
    <svg class="{{ $sizeClass }}" viewBox="0 0 64 36" fill="none" stroke="currentColor" aria-hidden="true">
        <circle cx="32" cy="18" r="11" fill="none" stroke="currentColor" stroke-opacity="0.2" stroke-width="5" />
        <circle cx="32" cy="18" r="11" fill="none" stroke="currentColor" stroke-width="5" stroke-dasharray="45 70" stroke-dashoffset="15" stroke-linecap="round" />
    </svg>
</template>
<template x-if="paletteVisual({{ $keyExpr }}).type === 'scorecard'">
    <svg class="{{ $sizeClass }}" viewBox="0 0 64 36" fill="none" stroke="currentColor" aria-hidden="true">
        <rect x="5" y="8" width="16" height="20" rx="2" fill="currentColor" fill-opacity="0.15" stroke="currentColor" stroke-width="1.2" />
        <rect x="24" y="8" width="16" height="20" rx="2" fill="currentColor" fill-opacity="0.3" stroke="currentColor" stroke-width="1.2" />
        <rect x="43" y="8" width="16" height="20" rx="2" fill="currentColor" fill-opacity="0.15" stroke="currentColor" stroke-width="1.2" />
    </svg>
</template>
<template x-if="paletteVisual({{ $keyExpr }}).type === 'table'">
    <svg class="{{ $sizeClass }}" viewBox="0 0 64 36" fill="none" stroke="currentColor" aria-hidden="true">
        <rect x="6" y="6" width="52" height="24" rx="2" fill="none" stroke="currentColor" stroke-width="1.2" stroke-opacity="0.4" />
        <line x1="6" y1="14" x2="58" y2="14" stroke="currentColor" stroke-width="1" stroke-opacity="0.5" />
        <line x1="6" y1="22" x2="58" y2="22" stroke="currentColor" stroke-width="1" stroke-opacity="0.3" />
        <line x1="24" y1="6" x2="24" y2="30" stroke="currentColor" stroke-width="1" stroke-opacity="0.3" />
    </svg>
</template>
<template x-if="paletteVisual({{ $keyExpr }}).type === 'ranking'">
    <svg class="{{ $sizeClass }}" viewBox="0 0 64 36" fill="none" stroke="currentColor" aria-hidden="true">
        <line x1="8" y1="6" x2="8" y2="30" stroke="currentColor" stroke-width="1" stroke-opacity="0.25" />
        <rect x="12" y="8" width="44" height="4" rx="1" fill="currentColor" />
        <rect x="12" y="16" width="32" height="4" rx="1" fill="currentColor" fill-opacity="0.6" />
        <rect x="12" y="24" width="20" height="4" rx="1" fill="currentColor" fill-opacity="0.35" />
    </svg>
</template>
<template x-if="paletteVisual({{ $keyExpr }}).type === 'generic'">
    <svg class="{{ $sizeClass }}" viewBox="0 0 64 36" fill="none" stroke="currentColor" aria-hidden="true">
        <rect x="6" y="6" width="52" height="24" rx="2" fill="currentColor" fill-opacity="0.08" stroke="currentColor" stroke-width="1.2" stroke-dasharray="3 3" />
        <circle cx="32" cy="18" r="4" fill="currentColor" fill-opacity="0.3" />
    </svg>
</template>
