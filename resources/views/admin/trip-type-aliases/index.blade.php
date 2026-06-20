<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">Trip type aliases</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <p class="text-sm text-green-700">{{ session('status') }}</p>
            @endif

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="bg-white p-6 shadow sm:rounded-lg">
                    <h3 class="font-semibold text-gray-900">Add trip type</h3>
                    <form method="POST" action="{{ route('admin.trip-types.store') }}" class="mt-4 space-y-4">
                        @csrf
                        <div>
                            <x-input-label for="name" value="Name" />
                            <x-text-input id="name" name="name" class="mt-1 block w-full" :value="old('name')" />
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="sort_order" value="Sort order" />
                            <x-text-input id="sort_order" name="sort_order" type="number" min="0" class="mt-1 block w-full" :value="old('sort_order')" />
                            <x-input-error :messages="$errors->get('sort_order')" class="mt-2" />
                        </div>
                        <x-primary-button>Save trip type</x-primary-button>
                    </form>
                </div>

                <div class="bg-white p-6 shadow sm:rounded-lg lg:col-span-2">
                    <h3 class="font-semibold text-gray-900">Active trip types</h3>
                    <div class="mt-4 grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2 text-sm">
                        @foreach ($tripTypes as $tripType)
                            <div class="rounded border border-gray-200 px-3 py-2">
                                <p class="font-medium text-gray-900">{{ $tripType->name }}</p>
                                <p class="text-xs text-gray-500">{{ $tripType->slug }} · {{ $tripType->sort_order }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="bg-white p-6 shadow sm:rounded-lg">
                <h3 class="font-semibold text-gray-900">Add trip type alias</h3>
                <form method="POST" action="{{ route('admin.trip-type-aliases.store') }}" class="mt-4 grid gap-4 md:grid-cols-3">
                    @csrf
                    <div>
                        <x-input-label for="trip_type_id" value="Trip type" />
                        <x-form.select id="trip_type_id" name="trip_type_id">
                            @foreach ($tripTypes as $tripType)
                                <option value="{{ $tripType->id }}">{{ $tripType->name }}</option>
                            @endforeach
                        </x-form.select>
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
                <h3 class="font-semibold text-gray-900">Trip type aliases</h3>
                <div class="mt-4 divide-y">
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
