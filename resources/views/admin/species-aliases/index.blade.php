<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">Species aliases</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <p class="text-sm text-green-700">{{ session('status') }}</p>
            @endif

            <div class="bg-white p-6 shadow sm:rounded-lg">
                <form method="POST" action="{{ route('admin.species-aliases.store') }}" class="grid gap-4 md:grid-cols-3">
                    @csrf
                    <div>
                        <x-input-label for="species_id" value="Species" />
                        <x-form.select id="species_id" name="species_id">
                            @foreach ($species as $item)
                                <option value="{{ $item->id }}">{{ $item->name }}</option>
                            @endforeach
                        </x-form.select>
                        <x-input-error :messages="$errors->get('species_id')" class="mt-2" />
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
                            <p class="text-gray-600">{{ $alias->species->name }}</p>
                            <p class="text-gray-500">{{ $alias->normalized_alias }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">No species aliases.</p>
                    @endforelse
                </div>

                {{ $aliases->links() }}
            </div>
        </div>
    </div>
</x-app-layout>
