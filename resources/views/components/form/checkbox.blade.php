@props([
    'checked' => false,
    'disabled' => false,
    'label' => null,
])

<label {{ $attributes->only('class')->merge(['class' => 'inline-flex items-center gap-2 text-sm text-gray-700']) }}>
    <input
        type="checkbox"
        class="form-checkbox-control rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
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
