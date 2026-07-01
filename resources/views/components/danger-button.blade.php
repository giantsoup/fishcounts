<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-danger border border-transparent rounded-md font-semibold text-xs text-surface uppercase tracking-widest hover:bg-danger-accent active:bg-danger focus:outline-none focus:ring-2 focus:ring-danger-accent focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
