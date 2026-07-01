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
    {{ $attributes->merge(['class' => 'form-select-control mt-1 block w-full rounded-md border-border shadow-sm focus:border-focus focus:ring-focus']) }}
>
    {{ $slot }}
</select>
