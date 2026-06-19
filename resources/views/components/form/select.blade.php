@props([
    'disabled' => false,
    'multiple' => false,
    'placeholder' => null,
])

<select
    @disabled($disabled)
    @if ($multiple) multiple @endif
    data-enhance="select"
    @if ($multiple) data-select-mode="multiple" @endif
    @if ($placeholder) data-placeholder="{{ $placeholder }}" @endif
    {{ $attributes->merge(['class' => 'form-select-control mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500']) }}
>
    {{ $slot }}
</select>
