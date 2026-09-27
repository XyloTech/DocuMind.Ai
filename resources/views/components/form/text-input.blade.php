@props(['name', 'label', 'type' => 'text', 'value' => null, 'autocomplete' => null])

<div {{ $attributes->only('class')->merge(['class' => 'space-y-1.5']) }}>
    <label for="{{ $name }}" class="block text-sm font-medium text-slate-700 dark:text-slate-600">
        {{ $label }}
    </label>

    <input
        id="{{ $name }}"
        name="{{ $name }}"
        type="{{ $type }}"
        value="{{ old($name, $value) }}"
        @if ($autocomplete !== null) autocomplete="{{ $autocomplete }}" @endif
        {{ $attributes->except('class')->merge(['class' => 'block w-full rounded-lg border border-slate-300 bg-white dark:bg-[#0d0f15] px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 dark:border-white/10 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-indigo-400']) }}
    >

    @error($name)
        <p class="text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>
    @enderror
</div>
