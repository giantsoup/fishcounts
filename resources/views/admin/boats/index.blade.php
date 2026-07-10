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
            class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6"
            x-data="{
                boats: {{ Illuminate\Support\Js::from($boatOptions) }},
                selectedBoatId: {{ Illuminate\Support\Js::from($normalizedSelectedBoatId) }},
                bookingUrl: {{ Illuminate\Support\Js::from($oldBookingUrl) }},
                get selectedBoat() {
                    return this.boats.find((boat) => boat.id === this.selectedBoatId) || null;
                },
                init() {
                    if (this.selectedBoat && this.bookingUrl === null) {
                        this.bookingUrl = this.selectedBoat.booking_url || '';
                    }
                },
                selectBoat(id) {
                    this.selectedBoatId = id;
                    this.bookingUrl = this.selectedBoat?.booking_url || '';
                },
            }"
        >
            @if (session('status'))
                <p class="text-sm text-green-700">{{ session('status') }}</p>
            @endif

            <div class="bg-white p-6 shadow sm:rounded-lg">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h3 class="font-semibold text-gray-900">Active boats</h3>
                        <p class="mt-1 text-sm text-gray-500">Choose the canonical boat name used throughout counts, alerts, and booking links.</p>
                    </div>

                    <form method="POST" action="{{ route('admin.boats.store') }}" class="grid gap-3 sm:grid-cols-[minmax(12rem,1fr)_minmax(12rem,1fr)_auto] sm:items-end">
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
                        <x-primary-button>Save boat</x-primary-button>
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

            <div class="bg-white p-6 shadow sm:rounded-lg">
                <div x-show="! selectedBoat">
                    <p class="text-sm text-gray-500">Select a boat to edit it or consolidate another name into it.</p>
                    <x-input-error :messages="$errors->get('boat_id')" class="mt-2" />
                </div>

                <div x-show="selectedBoat">
                    <div>
                        <h3 class="font-semibold text-gray-900" x-text="selectedBoat?.name"></h3>
                        <p class="mt-1 text-sm text-gray-500">
                            <span x-text="selectedBoat?.landing_name"></span>
                            <span x-show="selectedBoat"> · </span>
                            <span x-text="selectedBoat ? selectedBoat.aliases.length + (selectedBoat.aliases.length === 1 ? ' alias' : ' aliases') : ''"></span>
                        </p>
                    </div>

                    <div class="mt-5 grid gap-5 xl:grid-cols-2">
                        <form method="POST" :action="selectedBoat?.update_url" class="grid gap-3 sm:grid-cols-[minmax(16rem,1fr)_auto] sm:items-end">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="boat_id" :value="selectedBoatId">
                            <div>
                                <x-input-label for="booking_url" value="Booking URL" />
                                <x-text-input id="booking_url" name="booking_url" type="url" class="mt-1 block w-full" x-model="bookingUrl" placeholder="https://..." />
                                <x-input-error :messages="$errors->get('booking_url')" class="mt-2" />
                            </div>
                            <x-secondary-button type="submit">Save booking URL</x-secondary-button>
                        </form>

                        <form method="POST" action="{{ route('admin.boat-aliases.store') }}" class="grid gap-3 sm:grid-cols-[minmax(16rem,1fr)_auto] sm:items-end">
                            @csrf
                            <input type="hidden" name="boat_id" :value="selectedBoatId">
                            <div>
                                <x-input-label for="alias" value="Alternate boat name" />
                                <x-text-input id="alias" name="alias" class="mt-1 block w-full" :value="old('alias')" />
                                <p class="mt-1 text-xs text-gray-500">If this name is already a separate boat, its reports, aliases, and alert selections will move here. Existing metadata on this canonical boat takes precedence.</p>
                                <x-input-error :messages="$errors->get('alias')" class="mt-2" />
                                <x-input-error :messages="$errors->get('boat_id')" class="mt-2" />
                            </div>
                            <x-primary-button>Consolidate name</x-primary-button>
                        </form>
                    </div>

                    <div class="mt-6 divide-y">
                        <template x-if="selectedBoat && selectedBoat.aliases.length === 0">
                            <p class="text-sm text-gray-500">No alternate names for this boat.</p>
                        </template>

                        <template x-for="alias in selectedBoat ? selectedBoat.aliases : []" :key="alias.id">
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
