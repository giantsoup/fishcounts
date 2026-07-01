@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-border focus:border-focus focus:ring-focus rounded-md shadow-sm disabled:cursor-not-allowed disabled:bg-background disabled:text-muted']) }}>
