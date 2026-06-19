@props(['disabled' => false])

<input
    type="text"
    inputmode="numeric"
    autocomplete="off"
    data-enhance="date"
    @disabled($disabled)
    {{ $attributes->merge(['class' => 'form-date-control mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500']) }}
>
