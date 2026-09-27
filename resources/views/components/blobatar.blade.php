@props([
    'name',
    'size' => 32,
    'background' => 'circle',
    'fallback' => null,
])

@php($fallbackText = trim((string) ($fallback ?? $name)))

<span
    {{ $attributes->class([
        'inline-flex shrink-0 items-center justify-center overflow-hidden bg-indigo-600 text-xs font-semibold uppercase text-white',
        'rounded-full' => $background === 'circle',
        'rounded-xl' => $background === 'squircle',
    ]) }}
    data-blobatar-name="{{ $name }}"
    data-blobatar-background="{{ $background }}"
    aria-hidden="true"
    style="width: {{ (int) $size }}px; height: {{ (int) $size }}px;"
>{{ mb_strtoupper(mb_substr($fallbackText, 0, 1)) }}</span>