@props(['disabled' => false])

<input
    type="text"
    inputmode="numeric"
    autocomplete="off"
    data-enhance="date"
    @disabled($disabled)
    {{ $attributes->merge(['class' => 'form-date-control mt-1 block w-full rounded-md border-border shadow-xs focus:border-focus focus:ring-focus']) }}
>
