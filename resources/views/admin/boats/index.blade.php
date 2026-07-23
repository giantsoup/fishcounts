@php
    $boatOptions = $boats
        ->map(fn ($boat) => [
            'id' => $boat->id,
            'name' => $boat->name,
            'landing_name' => $boat->landing?->name ?? 'Unknown landing',
            'booking_url' => $boat->booking_url,
            'update_url' => route('admin.boats.update', $boat),
            'aliases' => $boat->aliases
                ->map(fn ($alias) => [
                    'id' => $alias->id,
                    'alias' => $alias->alias,
                    'normalized_alias' => $alias->normalized_alias,
                ])
                ->values(),
        ])
        ->values();
    $normalizedSelectedBoatId = filled($selectedBoatId) ? (int) $selectedBoatId : null;
    $oldBookingUrl = old('booking_url');
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">Boats</h2>
    </x-slot>

    <div class="py-8">
        <div
            class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:grid lg:grid-cols-2 lg:items-start lg:gap-6 lg:space-y-0 lg:px-8"
            x-data="boatManager"
            data-boats="{{ $boatOptions->toJson() }}"
            data-selected-boat-id="{{ $normalizedSelectedBoatId }}"
            @if ($oldBookingUrl !== null) data-old-booking-url="{{ $oldBookingUrl }}" @endif
        >
            @if (session('status'))
                <p class="text-sm text-green-700 lg:col-span-2">{{ session('status') }}</p>
            @endif

            <div class="bg-white p-6 shadow sm:rounded-lg">
                <div class="flex flex-col gap-4">
                    <div>
                        <h3 class="font-semibold text-gray-900">Active boats</h3>
                        <p class="mt-1 text-sm text-gray-500">Choose the canonical boat name used throughout counts, alerts, and booking links.</p>
                    </div>

                    <form method="POST" action="{{ route('admin.boats.store') }}" class="grid gap-3 sm:grid-cols-2 sm:items-end">
                        @csrf
                        <div>
                            <x-input-label for="boat_name" value="Name" />
                            <x-text-input id="boat_name" name="boat_name" class="mt-1 block w-full" :value="old('boat_name')" />
                            <x-input-error :messages="$errors->get('boat_name')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="landing_id" value="Landing" />
                            <x-form.select id="landing_id" name="landing_id" class="mt-1 block w-full">
                                <option value="">Unknown landing</option>
                                @foreach ($landings as $landing)
                                    <option value="{{ $landing->id }}" @selected((string) old('landing_id') === (string) $landing->id)>{{ $landing->name }}</option>
                                @endforeach
                            </x-form.select>
                            <x-input-error :messages="$errors->get('landing_id')" class="mt-2" />
                        </div>
                        <x-primary-button class="w-full justify-center sm:col-span-2 sm:w-auto sm:justify-self-end">Save boat</x-primary-button>
                    </form>
                </div>

                <div class="mt-4 grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-2 text-sm">
                    @foreach ($boats as $boat)
                        <button
                            type="button"
                            data-booking-url="{{ $boat->booking_url }}"
                            class="flex min-h-14 flex-col items-center justify-center rounded border px-3 py-2 text-center transition focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                            :class="selectedBoatId === {{ $boat->id }} ? 'border-gray-950 bg-gray-950 text-white' : 'border-gray-200 bg-white text-gray-900 hover:border-gray-400'"
                            @click="selectBoat({{ $boat->id }})"
                        >
                            <span class="text-base font-semibold leading-tight">{{ $boat->name }}</span>
                            <span class="mt-1 text-xs" :class="selectedBoatId === {{ $boat->id }} ? 'text-gray-200' : 'text-gray-500'">{{ $boat->landing?->name ?? 'Unknown landing' }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

            <div x-ref="boatEditor" class="scroll-mt-4 overflow-hidden bg-white shadow sm:rounded-lg">
                <div x-show="! selectedBoat">
                    <div class="px-6 py-12 text-center">
                        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-fc-blue-soft text-xl font-semibold text-primary">
                            B
                        </div>
                        <h3 class="mt-4 text-base font-semibold text-gray-900">Choose a boat to manage</h3>
                        <p class="mx-auto mt-1 max-w-md text-sm text-gray-500">Select a boat above to update its booking link or consolidate another name into it.</p>
                        <x-input-error :messages="$errors->get('boat_id')" class="mt-3" />
                    </div>
                </div>

                <div x-show="selectedBoat">
                    <div class="border-b border-border bg-fc-blue-soft px-6 py-5">
                        <p class="text-xs font-semibold uppercase tracking-wider text-link">Editing canonical boat</p>
                        <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <h3 class="text-2xl font-semibold tracking-tight text-gray-900" x-text="selectedBoat?.name"></h3>
                                <p class="mt-1 text-sm text-gray-600">Changes below apply to this boat everywhere it appears in FishCounts.</p>
                            </div>
                            <div class="flex flex-wrap gap-2 text-xs font-medium">
                                <span class="inline-flex items-center rounded-full border border-border bg-white px-3 py-1.5 text-gray-700" x-text="selectedBoat?.landing_name"></span>
                                <span class="inline-flex items-center rounded-full border border-border bg-white px-3 py-1.5 text-gray-700" x-text="selectedBoat ? selectedBoat.aliases.length + (selectedBoat.aliases.length === 1 ? ' alternate name' : ' alternate names') : ''"></span>
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-5 p-6">
                        <form method="POST" :action="selectedBoat?.update_url" class="flex h-full flex-col rounded-lg border border-border bg-white p-5 shadow-sm">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="boat_id" :value="selectedBoatId">

                            <div class="flex items-start gap-3">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-white">1</span>
                                <div>
                                    <h4 class="font-semibold text-gray-900">Booking details</h4>
                                    <p class="mt-1 text-sm text-gray-500">Add the public page where customers can view availability or reserve this boat.</p>
                                </div>
                            </div>

                            <div class="mt-5">
                                <x-input-label for="booking_url" value="Booking URL" />
                                <x-text-input id="booking_url" name="booking_url" type="url" class="mt-1 block w-full" x-model="bookingUrl" placeholder="https://..." />
                                <x-input-error :messages="$errors->get('booking_url')" class="mt-2" />
                            </div>

                            <div class="mt-auto flex justify-end pt-5">
                                <x-secondary-button type="submit" class="w-full justify-center sm:w-auto">Save booking URL</x-secondary-button>
                            </div>
                        </form>

                        <form method="POST" action="{{ route('admin.boat-aliases.store') }}" class="flex h-full flex-col rounded-lg border border-border bg-white p-5 shadow-sm">
                            @csrf
                            <input type="hidden" name="boat_id" :value="selectedBoatId">

                            <div class="flex items-start gap-3">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-white">2</span>
                                <div>
                                    <h4 class="font-semibold text-gray-900">Consolidate an alternate name</h4>
                                    <p class="mt-1 text-sm text-gray-500">Connect another spelling or duplicate boat record to this canonical boat.</p>
                                </div>
                            </div>

                            <div class="mt-5">
                                <x-input-label for="alias" value="Alternate boat name" />
                                <x-text-input id="alias" name="alias" class="mt-1 block w-full" :value="old('alias')" placeholder="e.g. The Apollo" aria-describedby="alias_consolidation_help" />
                                <x-input-error :messages="$errors->get('alias')" class="mt-2" />
                                <x-input-error :messages="$errors->get('boat_id')" class="mt-2" />
                            </div>

                            <div id="alias_consolidation_help" class="mt-4 rounded-md border border-border bg-fc-blue-soft px-4 py-3">
                                <p class="text-xs font-semibold uppercase tracking-wide text-primary">What happens next</p>
                                <p class="mt-1 text-sm text-gray-600">If this is already a separate boat, its reports, aliases, and alert selections move here. Existing details on this canonical boat take precedence.</p>
                            </div>

                            <div class="mt-auto flex justify-end pt-5">
                                <x-primary-button class="w-full justify-center sm:w-auto">Consolidate name</x-primary-button>
                            </div>
                        </form>
                    </div>

                    <div class="border-t border-border bg-gray-50 px-6 py-5">
                        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h4 class="font-semibold text-gray-900">Known alternate names</h4>
                                <p class="mt-1 text-sm text-gray-500">Names below are automatically matched to this boat during imports.</p>
                            </div>
                            <span class="mt-2 inline-flex w-fit rounded-full bg-white px-3 py-1 text-xs font-medium text-gray-600 ring-1 ring-border sm:mt-0" x-text="selectedBoat ? selectedBoat.aliases.length + (selectedBoat.aliases.length === 1 ? ' name' : ' names') : ''"></span>
                        </div>

                        <template x-if="selectedBoat && selectedBoat.aliases.length === 0">
                            <div class="mt-4 rounded-lg border border-dashed border-border bg-white px-5 py-6 text-center">
                                <p class="text-sm font-medium text-gray-700">No alternate names for this boat.</p>
                                <p class="mt-1 text-xs text-gray-500">Use the consolidation form above when another name should resolve here.</p>
                            </div>
                        </template>

                        <div class="mt-4 divide-y divide-border overflow-hidden rounded-lg border border-border bg-white" x-show="selectedBoat && selectedBoat.aliases.length > 0">
                            <template x-for="alias in selectedBoat ? selectedBoat.aliases : []" :key="alias.id">
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
