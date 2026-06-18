<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">Trip type aliases</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <p class="text-sm text-green-700">{{ session('status') }}</p>
            @endif

            <div class="bg-white p-6 shadow sm:rounded-lg">
                <form method="POST" action="{{ route('admin.trip-type-aliases.store') }}" class="grid gap-4 md:grid-cols-3">
                    @csrf
                    <div>
                        <x-input-label for="trip_type_id" value="Trip type" />
                        <select id="trip_type_id" name="trip_type_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            @foreach ($tripTypes as $tripType)
                                <option value="{{ $tripType->id }}">{{ $tripType->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('trip_type_id')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="alias" value="Alias" />
                        <x-text-input id="alias" name="alias" class="mt-1 block w-full" :value="old('alias')" />
                        <x-input-error :messages="$errors->get('alias')" class="mt-2" />
                    </div>
                    <div class="flex items-end">
                        <x-primary-button>Save alias</x-primary-button>
                    </div>
                </form>
            </div>

            <div class="bg-white p-6 shadow sm:rounded-lg">
                <div class="divide-y">
                    @forelse ($aliases as $alias)
                        <div class="grid gap-2 py-3 text-sm md:grid-cols-3">
                            <p class="font-medium text-gray-900">{{ $alias->alias }}</p>
                            <p class="text-gray-600">{{ $alias->tripType->name }}</p>
                            <p class="text-gray-500">{{ $alias->normalized_alias }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">No trip type aliases.</p>
                    @endforelse
                </div>

                {{ $aliases->links() }}
            </div>
        </div>
    </div>
</x-app-layout>
