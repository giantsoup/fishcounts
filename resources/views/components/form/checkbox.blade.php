@props([
    'checked' => false,
    'disabled' => false,
    'label' => null,
])

<label {{ $attributes->only('class')->merge(['class' => 'inline-flex items-center gap-2 text-sm text-text']) }}>
    <input
        type="checkbox"
        class="form-checkbox-control rounded border-border text-primary shadow-sm focus:ring-focus disabled:cursor-not-allowed disabled:opacity-60"
        @checked($checked)
        @disabled($disabled)
        {{ $attributes->except('class') }}
    >
    @if ($label)
        <span>{{ $label }}</span>
    @else
        {{ $slot }}
    @endif
</label>
