<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">Parser errors</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 bg-white p-6 shadow sm:rounded-lg">
            @if (session('status'))
                <p class="mb-4 text-sm text-green-700">{{ session('status') }}</p>
            @endif

            @forelse ($errors as $error)
                <div class="border-b py-5">
                    <div class="grid gap-3 lg:grid-cols-5">
                        <div class="lg:col-span-2">
                            <p class="font-medium text-gray-900">{{ $error->error_type }}</p>
                            <p class="text-sm text-gray-600">{{ $error->message }}</p>
                            <p class="mt-2 text-xs text-gray-500">
                                {{ $error->scrapeSource->name }} · {{ $error->target_date?->format('n/j/Y') ?? 'No date' }} · {{ $error->created_at->format('n/j/Y g:i A') }}
                            </p>
                            @if ($error->resolved_at)
                                <p class="mt-2 text-xs text-green-700">Resolved {{ $error->resolved_at->diffForHumans() }}</p>
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

                        <div>
                            @if (! $error->resolved_at && $error->error_type === 'unknown_species_alias' && $error->raw_value)
                                <form method="POST" action="{{ route('admin.species-aliases.store') }}" class="space-y-2">
                                    @csrf
                                    <input type="hidden" name="alias" value="{{ $error->raw_value }}">
                                    <input type="hidden" name="parser_error_id" value="{{ $error->id }}">
                                    <select name="species_id" class="block w-full rounded-md border-gray-300 text-sm shadow-sm">
                                        @foreach ($species as $item)
                                            <option value="{{ $item->id }}">{{ $item->name }}</option>
                                        @endforeach
                                    </select>
                                    <x-secondary-button>Resolve as species</x-secondary-button>
                                </form>
                            @elseif (! $error->resolved_at && $error->error_type === 'unknown_trip_type_alias' && $error->raw_value)
                                <form method="POST" action="{{ route('admin.trip-type-aliases.store') }}" class="space-y-2">
                                    @csrf
                                    <input type="hidden" name="alias" value="{{ $error->raw_value }}">
                                    <input type="hidden" name="parser_error_id" value="{{ $error->id }}">
                                    <select name="trip_type_id" class="block w-full rounded-md border-gray-300 text-sm shadow-sm">
                                        @foreach ($tripTypes as $tripType)
                                            <option value="{{ $tripType->id }}">{{ $tripType->name }}</option>
                                        @endforeach
                                    </select>
                                    <x-secondary-button>Resolve as trip type</x-secondary-button>
                                </form>
                            @else
                                <p class="text-sm text-gray-500">No alias action.</p>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">No parser errors.</p>
            @endforelse

            {{ $errors->links() }}
        </div>
    </div>
</x-app-layout>
