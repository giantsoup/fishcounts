@props([
    'disabled' => false,
    'enhance' => true,
    'multiple' => false,
    'placeholder' => null,
])

<select
    @disabled($disabled)
    @if ($multiple) multiple @endif
    @if ($enhance) data-enhance="select" @endif
    @if ($enhance && $multiple) data-select-mode="multiple" @endif
    @if ($multiple && $placeholder) data-placeholder="{{ $placeholder }}" @endif
    {{ $attributes->merge(['class' => 'form-select-control mt-1 block w-full rounded-md border-border shadow-sm focus:border-focus focus:ring-focus']) }}
>
    {{ $slot }}
</select>
