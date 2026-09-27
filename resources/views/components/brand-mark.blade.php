@props([
    'size' => 32,
])

{{--
    The brand mark, rendered the same way on every screen. The source art sits
    inside a 500px canvas with wide transparent margins, so the wrapper crops
    and optically centers it (see .brand-mark) — a bare <img> reads small and
    off-centre at these sizes.
--}}
<span
    {{ $attributes->class(['brand-mark']) }}
    style="width: {{ (int) $size }}px; height: {{ (int) $size }}px;"
    aria-hidden="true"
>
    <img
        src="{{ asset('logo.png') }}"
        width="{{ (int) $size }}"
        height="{{ (int) $size }}"
        alt=""
        decoding="async"
    >
</span>
