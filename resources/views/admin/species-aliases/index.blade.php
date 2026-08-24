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
    $normalizedCreatedSpeciesId = filled($createdSpeciesId) ? (int) $createdSpeciesId : null;
    $normalizedSelectedSpeciesId = filled($selectedSpeciesId) ? (int) $selectedSpeciesId : null;
    $oldEnvironmentalLocationProfile = old('species_environmental_location_profile');
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">Species</h2>
    </x-slot>

    <div class="py-8">
        <div
            class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:grid lg:grid-cols-2 lg:items-start lg:gap-6 lg:space-y-0 lg:px-8"
            x-data="speciesManager"
            data-species="{{ $speciesOptions->toJson() }}"
            @if ($normalizedCreatedSpeciesId !== null) data-created-species-id="{{ $normalizedCreatedSpeciesId }}" @endif
            data-selected-species-id="{{ $normalizedSelectedSpeciesId }}"
            @if ($oldEnvironmentalLocationProfile !== null) data-old-environmental-location-profile="{{ $oldEnvironmentalLocationProfile }}" @endif
        >
            @if (session('status'))
                <p class="text-sm text-green-700 lg:col-span-2">{{ session('status') }}</p>
            @endif

            <div class="bg-white p-6 shadow sm:rounded-lg">
                <div class="flex flex-col gap-4">
                    <div>
                        <h3 class="font-semibold text-gray-900">Active species</h3>
                        <p class="mt-1 text-sm text-gray-500">Choose the canonical species names used throughout counts, alerts, and fishing reports.</p>
                    </div>

                    <form method="POST" action="{{ route('admin.species.store') }}" class="grid gap-3 sm:grid-cols-2 sm:items-end" autocomplete="off" x-ref="createForm">
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
                        <x-primary-button class="w-full justify-center sm:col-span-2 sm:w-auto sm:justify-self-end">Save species</x-primary-button>
                    </form>
                </div>

                <div class="mt-4 grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-2 text-sm">
                    @foreach ($species as $item)
                        <button
                            type="button"
                            class="flex min-h-14 flex-col items-center justify-center rounded border px-3 py-2 text-center transition focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                            :class="selectedSpeciesId === {{ $item->id }} ? 'border-gray-950 bg-gray-950 text-white' : 'border-gray-200 bg-white text-gray-900 hover:border-gray-400'"
                            :aria-pressed="selectedSpeciesId === {{ $item->id }}"
                            aria-controls="species_editor"
                            @click="selectSpecies({{ $item->id }})"
                        >
                            <span class="text-base font-semibold leading-tight">{{ $item->name }}</span>
                            <span class="mt-1 text-xs" :class="selectedSpeciesId === {{ $item->id }} ? 'text-gray-200' : 'text-gray-500'">{{ $environmentalLocationProfiles[$item->environmental_location_profile] ?? $item->environmental_location_profile }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

            <div id="species_editor" x-ref="speciesEditor" role="region" aria-label="Species editor" class="scroll-mt-4 overflow-hidden bg-white shadow sm:rounded-lg">
                <div x-show="! selectedSpecies">
                    <div class="px-6 py-12 text-center">
                        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-fc-blue-soft text-xl font-semibold text-primary">
                            S
                        </div>
                        <h3 class="mt-4 text-base font-semibold text-gray-900">Choose a species to manage</h3>
                        <p class="mx-auto mt-1 max-w-md text-sm text-gray-500">Select a species above to update its condition profile or add another name for it.</p>
                        <x-input-error :messages="$errors->get('species_id')" class="mt-3" />
                    </div>
                </div>

                <div x-show="selectedSpecies">
                    <div class="border-b border-border bg-fc-blue-soft px-6 py-5">
                        <p class="text-xs font-semibold uppercase tracking-wider text-link">Editing canonical species</p>
                        <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <h3 class="text-2xl font-semibold tracking-tight text-gray-900" x-text="selectedSpecies?.name"></h3>
                                <p class="mt-1 text-sm text-gray-600">Changes below apply to this species everywhere it appears in FishCounts.</p>
                            </div>
                            <div class="flex flex-wrap gap-2 text-xs font-medium">
                                <span class="inline-flex items-center rounded-full border border-border bg-white px-3 py-1.5 text-gray-700" x-text="selectedSpecies?.environmental_location_profile_label"></span>
                                <span class="inline-flex items-center rounded-full border border-border bg-white px-3 py-1.5 text-gray-700" x-text="selectedSpecies ? selectedSpecies.aliases.length + (selectedSpecies.aliases.length === 1 ? ' alternate name' : ' alternate names') : ''"></span>
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-5 p-6">
                        <form method="POST" :action="selectedSpecies?.update_url" class="flex h-full flex-col rounded-lg border border-border bg-white p-5 shadow-sm">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="species_id" :value="selectedSpeciesId">

                            <div class="flex items-start gap-3">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-white">1</span>
                                <div>
                                    <h4 class="font-semibold text-gray-900">Condition profile</h4>
                                    <p class="mt-1 text-sm text-gray-500">Choose the environmental area used for alerts and weekly digests about this species.</p>
                                </div>
                            </div>

                            <div class="mt-5">
                                <x-input-label for="species_environmental_location_profile" value="Environmental area" />
                                <x-form.select id="species_environmental_location_profile" name="species_environmental_location_profile" :enhance="false" x-model="environmentalLocationProfile">
                                    @foreach ($environmentalLocationProfiles as $profile => $label)
                                        <option value="{{ $profile }}">{{ $label }}</option>
                                    @endforeach
                                </x-form.select>
                                <x-input-error :messages="$errors->get('species_environmental_location_profile')" class="mt-2" />
                            </div>

                            <div class="mt-auto flex justify-end pt-5">
                                <x-secondary-button type="submit" class="w-full justify-center sm:w-auto">Save condition profile</x-secondary-button>
                            </div>
                        </form>

                        <form method="POST" action="{{ route('admin.species-aliases.store') }}" class="flex h-full flex-col rounded-lg border border-border bg-white p-5 shadow-sm">
                            @csrf
                            <input type="hidden" name="species_id" :value="selectedSpeciesId">

                            <div class="flex items-start gap-3">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-white">2</span>
                                <div>
                                    <h4 class="font-semibold text-gray-900">Add an alternate name</h4>
                                    <p class="mt-1 text-sm text-gray-500">Connect another spelling or source label to this canonical species.</p>
                                </div>
                            </div>

                            <div class="mt-5">
                                <x-input-label for="alias" value="Alternate species name" />
                                <x-text-input id="alias" name="alias" class="mt-1 block w-full" :value="old('alias')" placeholder="e.g. Calicos" aria-describedby="species_alias_help" />
                                <x-input-error :messages="$errors->get('alias')" class="mt-2" />
                                <x-input-error :messages="$errors->get('species_id')" class="mt-2" />
                            </div>

                            <div id="species_alias_help" class="mt-4 rounded-md border border-border bg-fc-blue-soft px-4 py-3">
                                <p class="text-xs font-semibold uppercase tracking-wide text-primary">What happens next</p>
                                <p class="mt-1 text-sm text-gray-600">Future imports automatically match this name to the selected species, keeping counts and alerts under one canonical record.</p>
                            </div>

                            <div class="mt-auto flex justify-end pt-5">
                                <x-primary-button class="w-full justify-center sm:w-auto">Save alternate name</x-primary-button>
                            </div>
                        </form>
                    </div>

                    <div class="border-t border-border bg-gray-50 px-6 py-5">
                        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h4 class="font-semibold text-gray-900">Known alternate names</h4>
                                <p class="mt-1 text-sm text-gray-500">Names below are automatically matched to this species during imports.</p>
                            </div>
                            <span class="mt-2 inline-flex w-fit rounded-full bg-white px-3 py-1 text-xs font-medium text-gray-600 ring-1 ring-border sm:mt-0" x-text="selectedSpecies ? selectedSpecies.aliases.length + (selectedSpecies.aliases.length === 1 ? ' name' : ' names') : ''"></span>
                        </div>

                        <template x-if="selectedSpecies && selectedSpecies.aliases.length === 0">
                            <div class="mt-4 rounded-lg border border-dashed border-border bg-white px-5 py-6 text-center">
                                <p class="text-sm font-medium text-gray-700">No alternate names for this species.</p>
                                <p class="mt-1 text-xs text-gray-500">Use the alternate-name form above when another label should resolve here.</p>
                            </div>
                        </template>

                        <div class="mt-4 divide-y divide-border overflow-hidden rounded-lg border border-border bg-white" x-show="selectedSpecies && selectedSpecies.aliases.length > 0">
                            <template x-for="alias in selectedSpecies ? selectedSpecies.aliases : []" :key="alias.id">
                                <div class="flex flex-col gap-1 px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between">
                                    <p class="font-medium text-gray-900" x-text="alias.alias"></p>
                                    <p class="text-xs text-gray-500">
                                        Match key:
                                        <span class="font-mono" x-text="alias.normalized_alias"></span>
                                    </p>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
