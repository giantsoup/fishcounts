<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">Species aliases</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <p class="text-sm text-green-700">{{ session('status') }}</p>
            @endif

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="bg-white p-6 shadow sm:rounded-lg">
                    <h3 class="font-semibold text-gray-900">Add species</h3>
                    <form method="POST" action="{{ route('admin.species.store') }}" class="mt-4 space-y-4">
                        @csrf
                        <div>
                            <x-input-label for="name" value="Name" />
                            <x-text-input id="name" name="name" class="mt-1 block w-full" :value="old('name')" />
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>
                        <x-primary-button>Save species</x-primary-button>
                    </form>
                </div>

                <div class="bg-white p-6 shadow sm:rounded-lg lg:col-span-2">
                    <h3 class="font-semibold text-gray-900">Active species</h3>
                    <div class="mt-4 grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2 text-sm">
                        @foreach ($species as $item)
                            <div class="rounded border border-gray-200 px-3 py-2">
                                <p class="font-medium text-gray-900">{{ $item->name }}</p>
                                <p class="text-xs text-gray-500">{{ $item->slug }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="bg-white p-6 shadow sm:rounded-lg">
                <h3 class="font-semibold text-gray-900">Add species alias</h3>
                <form method="POST" action="{{ route('admin.species-aliases.store') }}" class="mt-4 grid gap-4 md:grid-cols-3">
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
                <h3 class="font-semibold text-gray-900">Species aliases</h3>
                <div class="mt-4 divide-y">
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
