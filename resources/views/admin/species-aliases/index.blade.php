@php
    $speciesOptions = $species
        ->map(fn ($item) => [
            'id' => $item->id,
            'name' => $item->name,
            'environmental_location_profile' => $item->environmental_location_profile,
            'environmental_location_profile_label' => $environmentalLocationProfiles[$item->environmental_location_profile] ?? $item->environmental_location_profile,
            'update_url' => route('admin.species.update', $item),
            'aliases' => $item->aliases
                ->map(fn ($alias) => [
                    'id' => $alias->id,
                    'alias' => $alias->alias,
                    'normalized_alias' => $alias->normalized_alias,
                ])
                ->values(),
        ])
        ->values();
    $normalizedSelectedSpeciesId = filled($selectedSpeciesId) ? (int) $selectedSpeciesId : null;
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">Species</h2>
    </x-slot>

    <div class="py-8">
        <div
            class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6"
            x-data="{
                species: {{ Illuminate\Support\Js::from($speciesOptions) }},
                selectedSpeciesId: {{ Illuminate\Support\Js::from($normalizedSelectedSpeciesId) }},
                selectedEnvironmentalLocationProfile: {{ Illuminate\Support\Js::from(old('species_environmental_location_profile')) }},
                init() {
                    if (! this.selectedEnvironmentalLocationProfile) {
                        this.syncEnvironmentalLocationProfile();
                    }
                },
                get selectedSpecies() {
                    return this.species.find((species) => species.id === this.selectedSpeciesId) || null;
                },
                selectSpecies(id) {
                    this.selectedSpeciesId = id;
                    this.syncEnvironmentalLocationProfile();
                },
                syncEnvironmentalLocationProfile() {
                    this.selectedEnvironmentalLocationProfile = this.selectedSpecies?.environmental_location_profile || null;
                },
            }"
        >
            @if (session('status'))
                <p class="text-sm text-green-700">{{ session('status') }}</p>
            @endif

            <div class="bg-white p-6 shadow sm:rounded-lg">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <h3 class="font-semibold text-gray-900">Active species</h3>

                    <form method="POST" action="{{ route('admin.species.store') }}" class="grid gap-3 sm:grid-cols-[minmax(14rem,1fr)_minmax(15rem,1fr)_auto] sm:items-end">
                        @csrf
                        <div>
                            <x-input-label for="species_name" value="Name" />
                            <x-text-input id="species_name" name="name" class="mt-1 block w-full" :value="old('name')" />
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="new_species_environmental_location_profile" value="Condition profile" />
                            <x-form.select id="new_species_environmental_location_profile" name="environmental_location_profile">
                                @foreach ($environmentalLocationProfiles as $profile => $label)
                                    <option value="{{ $profile }}" @selected(old('environmental_location_profile', config('fish.conditions.location_profile')) === $profile)>{{ $label }}</option>
                                @endforeach
                            </x-form.select>
                            <x-input-error :messages="$errors->get('environmental_location_profile')" class="mt-2" />
                        </div>
                        <x-primary-button>Save species</x-primary-button>
                    </form>
                </div>

                <div class="mt-4 grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2 text-sm">
                    @foreach ($species as $item)
                        <button
                            type="button"
                            class="flex min-h-12 items-center justify-center rounded border px-3 py-2 text-center transition focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                            :class="selectedSpeciesId === {{ $item->id }} ? 'border-gray-950 bg-gray-950 text-white' : 'border-gray-200 bg-white text-gray-900 hover:border-gray-400'"
                            @click="selectSpecies({{ $item->id }})"
                        >
                            <span class="text-base font-semibold leading-tight">{{ $item->name }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="bg-white p-6 shadow sm:rounded-lg">
                <div x-show="! selectedSpecies">
                    <p class="text-sm text-gray-500">Select a species to edit it.</p>
                    <x-input-error :messages="$errors->get('species_id')" class="mt-2" />
                </div>

                <div x-show="selectedSpecies">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <h3 class="font-semibold text-gray-900" x-text="selectedSpecies?.name"></h3>
                            <p class="mt-1 text-sm text-gray-500" x-text="selectedSpecies ? selectedSpecies.aliases.length + (selectedSpecies.aliases.length === 1 ? ' alias' : ' aliases') : ''"></p>
                            <p class="mt-1 text-sm text-gray-500" x-text="selectedSpecies?.environmental_location_profile_label"></p>
                        </div>

                        <form method="POST" action="{{ route('admin.species-aliases.store') }}" class="grid gap-3 sm:grid-cols-[minmax(16rem,1fr)_auto] sm:items-end">
                            @csrf
                            <input type="hidden" name="species_id" :value="selectedSpeciesId">
                            <div>
                                <x-input-label for="alias" value="New alias" />
                                <x-text-input id="alias" name="alias" class="mt-1 block w-full" :value="old('alias')" />
                                <x-input-error :messages="$errors->get('alias')" class="mt-2" />
                                <x-input-error :messages="$errors->get('species_id')" class="mt-2" />
                            </div>
                            <x-primary-button>Save alias</x-primary-button>
                        </form>
                    </div>

                    <form method="POST" x-bind:action="selectedSpecies?.update_url" class="mt-6 grid gap-3 border-t pt-6 sm:grid-cols-[minmax(16rem,24rem)_auto] sm:items-end">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="species_id" :value="selectedSpeciesId">
                        <div>
                            <x-input-label for="species_environmental_location_profile" value="Condition profile" />
                            <x-form.select id="species_environmental_location_profile" name="species_environmental_location_profile" :enhance="false" x-model="selectedEnvironmentalLocationProfile">
                                @foreach ($environmentalLocationProfiles as $profile => $label)
                                    <option value="{{ $profile }}">{{ $label }}</option>
                                @endforeach
                            </x-form.select>
                            <p class="mt-1 text-sm text-gray-500">Controls which environmental conditions appear in alerts and weekly digests for this species.</p>
                            <x-input-error :messages="$errors->get('species_environmental_location_profile')" class="mt-2" />
                        </div>
                        <x-secondary-button type="submit">Save condition profile</x-secondary-button>
                    </form>

                    <div class="mt-6 divide-y">
                        <template x-if="selectedSpecies && selectedSpecies.aliases.length === 0">
                            <p class="text-sm text-gray-500">No aliases for this species.</p>
                        </template>

                        <template x-for="alias in selectedSpecies ? selectedSpecies.aliases : []" :key="alias.id">
                            <div class="grid gap-2 py-3 text-sm md:grid-cols-2">
                                <p class="font-medium text-gray-900" x-text="alias.alias"></p>
                                <p class="text-gray-500" x-text="alias.normalized_alias"></p>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
