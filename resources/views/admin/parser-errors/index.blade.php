<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">Parser errors</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 bg-white p-6 shadow sm:rounded-lg">
            @if (session('status'))
                <p class="mb-4 text-sm text-green-700">{{ session('status') }}</p>
            @endif

            <nav class="mb-4 flex gap-4 text-sm" aria-label="Parser error filters">
                <a href="{{ route('admin.parser-errors.index') }}" @class(['font-semibold text-blue-700' => ! $showAll, 'text-gray-600 hover:text-gray-900' => $showAll])>Open</a>
                <a href="{{ route('admin.parser-errors.index', ['status' => 'all']) }}" @class(['font-semibold text-blue-700' => $showAll, 'text-gray-600 hover:text-gray-900' => ! $showAll])>All</a>
            </nav>

            @forelse ($errors as $error)
                <div class="border-b py-5">
                    <div class="grid gap-3 lg:grid-cols-5">
                        <div class="lg:col-span-2">
                            <p class="font-medium text-gray-900">{{ $error->error_type }}</p>
                            <p class="text-sm text-gray-600">{{ $error->message }}</p>
                            <p class="mt-2 text-xs text-gray-500">
                                {{ $error->scrapeSource->name }} · {{ $error->target_date?->format('n/j/Y') ?? 'No date' }} · {{ $error->created_at->format('n/j/Y g:i A') }}
                            </p>
                            @if ($error->rawScrapePayload)
                                <p class="mt-2 text-xs">
                                    <a class="text-blue-700" href="{{ route('admin.raw-payloads.show', $error->rawScrapePayload) }}">View raw payload</a>
                                </p>
                            @endif
                            @if ($error->resolved_at)
                                <p class="mt-2 text-xs text-green-700">
                                    {{ $error->resolution_type === \App\Enums\ParserErrorResolutionType::Dismissed ? 'Dismissed' : 'Resolved' }} {{ $error->resolved_at->diffForHumans() }}
                                    @if ($error->resolver)
                                        by {{ $error->resolver->name }}
                                    @endif
                                </p>
                            @endif
                        </div>

                        <div>
                            <p class="text-xs font-medium uppercase text-gray-500">Raw field</p>
                            <p class="text-sm text-gray-900">{{ $error->raw_field ?? 'n/a' }}</p>
                        </div>

                        <div>
                            <p class="text-xs font-medium uppercase text-gray-500">Raw value</p>
                            <p class="text-sm text-gray-900">{{ $error->raw_value ?? 'n/a' }}</p>
                        </div>

                        <div class="space-y-4">
                            @if (! $error->resolved_at && $error->error_type === 'unknown_boat_alias' && $error->raw_value)
                                <form method="POST" action="{{ route('admin.boat-aliases.store') }}" class="space-y-2">
                                    @csrf
                                    <input type="hidden" name="alias" value="{{ $error->raw_value }}">
                                    <input type="hidden" name="parser_error_id" value="{{ $error->id }}">
                                    <x-form.select name="boat_id" class="text-sm">
                                        @foreach ($boats as $boat)
                                            <option value="{{ $boat->id }}">{{ $boat->name }}</option>
                                        @endforeach
                                    </x-form.select>
                                    <x-secondary-button type="submit">Resolve as boat</x-secondary-button>
                                </form>
                            @elseif (! $error->resolved_at && $error->error_type === 'unknown_species_alias' && $error->raw_value)
                                <form method="POST" action="{{ route('admin.species-aliases.store') }}" class="space-y-2">
                                    @csrf
                                    <input type="hidden" name="alias" value="{{ $error->raw_value }}">
                                    <input type="hidden" name="parser_error_id" value="{{ $error->id }}">
                                    <x-form.select name="species_id" class="text-sm">
                                        @foreach ($species as $item)
                                            <option value="{{ $item->id }}">{{ $item->name }}</option>
                                        @endforeach
                                    </x-form.select>
                                    <x-secondary-button type="submit">Resolve as species</x-secondary-button>
                                </form>
                            @elseif (! $error->resolved_at && $error->error_type === 'unknown_trip_type_alias' && $error->raw_value)
                                <form method="POST" action="{{ route('admin.trip-type-aliases.store') }}" class="space-y-2">
                                    @csrf
                                    <input type="hidden" name="alias" value="{{ $error->raw_value }}">
                                    <input type="hidden" name="parser_error_id" value="{{ $error->id }}">
                                    <x-form.select name="trip_type_id" class="text-sm">
                                        @foreach ($tripTypes as $tripType)
                                            <option value="{{ $tripType->id }}">{{ $tripType->name }}</option>
                                        @endforeach
                                    </x-form.select>
                                    <x-secondary-button type="submit">Resolve as trip type</x-secondary-button>
                                </form>
                            @elseif (! $error->resolved_at)
                                <p class="text-sm text-gray-500">No alias action.</p>
                            @endif

                            @if (! $error->resolved_at)
                                <form method="POST" action="{{ route('admin.parser-errors.dismiss', $error) }}" onsubmit="return confirm('Dismiss this parser error without creating an alias?');">
                                    @csrf
                                    @method('PATCH')
                                    <x-secondary-button type="submit">Dismiss error</x-secondary-button>
                                    <p class="mt-1 text-xs text-gray-500">Marks this error resolved without creating an alias.</p>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">{{ $showAll ? 'No parser errors.' : 'No open parser errors.' }}</p>
            @endforelse

            {{ $errors->links() }}
        </div>
    </div>
</x-app-layout>
