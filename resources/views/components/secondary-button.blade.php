<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center px-4 py-2 bg-surface border border-border rounded-md font-semibold text-xs text-text uppercase tracking-widest shadow-sm hover:bg-fc-blue-soft focus:outline-none focus:ring-2 focus:ring-focus focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
