@props([
    'decimal' => false,
    'disabled' => false,
    'mask' => null,
])

@php
    $inputAttributes = $attributes->except(['min', 'max', 'step']);
@endphp

<input
    type="text"
    inputmode="{{ $decimal ? 'decimal' : 'numeric' }}"
    data-form-number
    @if ($attributes->has('min')) data-min="{{ $attributes->get('min') }}" @endif
    @if ($attributes->has('max')) data-max="{{ $attributes->get('max') }}" @endif
    @if ($attributes->has('step')) data-step="{{ $attributes->get('step') }}" @endif
    @if ($mask) x-mask="{{ $mask }}" @endif
    @disabled($disabled)
    {{ $inputAttributes->merge(['class' => 'form-number-control mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500']) }}
>
