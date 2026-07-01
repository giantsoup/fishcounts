@props(['active'])

@php
$classes = ($active ?? false)
            ? 'block w-full ps-3 pe-4 py-2 border-l-4 border-link text-start text-base font-medium text-primary bg-fc-blue-soft focus:outline-none focus:text-primary focus:bg-fc-blue-soft focus:border-primary transition duration-150 ease-in-out'
            : 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-muted hover:text-text hover:bg-fc-blue-soft hover:border-border focus:outline-none focus:text-text focus:bg-fc-blue-soft focus:border-link transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
