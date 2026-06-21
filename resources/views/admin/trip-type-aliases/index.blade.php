@php
    $tripTypeOptions = $tripTypes
        ->map(fn ($tripType) => [
            'id' => $tripType->id,
            'name' => $tripType->name,
            'sort_order' => $tripType->sort_order,
            'update_url' => route('admin.trip-types.update', $tripType),
            'aliases' => $tripType->aliases
                ->map(fn ($alias) => [
                    'id' => $alias->id,
                    'alias' => $alias->alias,
                    'normalized_alias' => $alias->normalized_alias,
                ])
                ->values(),
        ])
        ->values();
    $normalizedSelectedTripTypeId = filled($selectedTripTypeId) ? (int) $selectedTripTypeId : null;
    $oldOrderSortOrder = old('order_sort_order');
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">Trips</h2>
    </x-slot>

    <div class="py-8">
        <div
            class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6"
            x-data="{
                tripTypes: {{ Illuminate\Support\Js::from($tripTypeOptions) }},
                selectedTripTypeId: {{ Illuminate\Support\Js::from($normalizedSelectedTripTypeId) }},
                orderSortOrder: {{ Illuminate\Support\Js::from($oldOrderSortOrder) }},
                get selectedTripType() {
                    return this.tripTypes.find((tripType) => tripType.id === this.selectedTripTypeId) || null;
                },
                init() {
                    if (this.selectedTripType && this.orderSortOrder === null) {
                        this.orderSortOrder = this.selectedTripType.sort_order;
                    }
                },
                selectTripType(id) {
                    this.selectedTripTypeId = id;
                    this.orderSortOrder = this.selectedTripType?.sort_order ?? '';
                },
            }"
        >
            @if (session('status'))
                <p class="text-sm text-green-700">{{ session('status') }}</p>
            @endif

            <div class="bg-white p-6 shadow sm:rounded-lg">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <h3 class="font-semibold text-gray-900">Active trips</h3>

                    <form method="POST" action="{{ route('admin.trip-types.store') }}" class="grid gap-3 sm:grid-cols-[minmax(12rem,1fr)_7rem_auto] sm:items-end">
                        @csrf
                        <div>
                            <x-input-label for="trip_type_name" value="Name" />
                            <x-text-input id="trip_type_name" name="name" class="mt-1 block w-full" :value="old('name')" />
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="sort_order" value="Order" />
                            <x-text-input id="sort_order" name="sort_order" type="number" min="0" class="mt-1 block w-full" :value="old('sort_order')" />
                            <x-input-error :messages="$errors->get('sort_order')" class="mt-2" />
                        </div>
                        <x-primary-button>Save trip</x-primary-button>
                    </form>
                </div>

                <div class="mt-4 grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2 text-sm">
                    @foreach ($tripTypes as $tripType)
                        <button
                            type="button"
                            class="flex min-h-14 flex-col items-center justify-center rounded border px-3 py-2 text-center transition focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                            :class="selectedTripTypeId === {{ $tripType->id }} ? 'border-gray-950 bg-gray-950 text-white' : 'border-gray-200 bg-white text-gray-900 hover:border-gray-400'"
                            @click="selectTripType({{ $tripType->id }})"
                        >
                            <span class="text-base font-semibold leading-tight">{{ $tripType->name }}</span>
                            <span class="mt-1 text-xs" :class="selectedTripTypeId === {{ $tripType->id }} ? 'text-gray-200' : 'text-gray-500'">Order {{ $tripType->sort_order }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="bg-white p-6 shadow sm:rounded-lg">
                <div x-show="! selectedTripType">
                    <p class="text-sm text-gray-500">Select a trip to edit it.</p>
                    <x-input-error :messages="$errors->get('trip_type_id')" class="mt-2" />
                    <x-input-error :messages="$errors->get('order_sort_order')" class="mt-2" />
                </div>

                <div x-show="selectedTripType">
                    <div class="flex flex-col gap-6 xl:flex-row xl:items-start xl:justify-between">
                        <div>
                            <h3 class="font-semibold text-gray-900" x-text="selectedTripType?.name"></h3>
                            <p class="mt-1 text-sm text-gray-500">
                                <span x-text="selectedTripType ? 'Order ' + selectedTripType.sort_order : ''"></span>
                                <span x-show="selectedTripType"> · </span>
                                <span x-text="selectedTripType ? selectedTripType.aliases.length + (selectedTripType.aliases.length === 1 ? ' alias' : ' aliases') : ''"></span>
                            </p>
                        </div>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <form method="POST" :action="selectedTripType?.update_url" class="grid gap-3 sm:grid-cols-[7rem_auto] sm:items-end">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="order_trip_type_id" :value="selectedTripTypeId">
                                <div>
                                    <x-input-label for="order_sort_order" value="Order" />
                                    <x-text-input id="order_sort_order" name="order_sort_order" type="number" min="0" class="mt-1 block w-full" x-model="orderSortOrder" />
                                    <x-input-error :messages="$errors->get('order_sort_order')" class="mt-2" />
                                </div>
                                <x-secondary-button type="submit">Save order</x-secondary-button>
                            </form>

                            <form method="POST" action="{{ route('admin.trip-type-aliases.store') }}" class="grid gap-3 sm:grid-cols-[minmax(12rem,1fr)_auto] sm:items-end">
                                @csrf
                                <input type="hidden" name="trip_type_id" :value="selectedTripTypeId">
                                <div>
                                    <x-input-label for="alias" value="New alias" />
                                    <x-text-input id="alias" name="alias" class="mt-1 block w-full" :value="old('alias')" />
                                    <x-input-error :messages="$errors->get('alias')" class="mt-2" />
                                    <x-input-error :messages="$errors->get('trip_type_id')" class="mt-2" />
                                </div>
                                <x-primary-button>Save alias</x-primary-button>
                            </form>
                        </div>
                    </div>

                    <div class="mt-6 divide-y">
                        <template x-if="selectedTripType && selectedTripType.aliases.length === 0">
                            <p class="text-sm text-gray-500">No aliases for this trip.</p>
                        </template>

                        <template x-for="alias in selectedTripType ? selectedTripType.aliases : []" :key="alias.id">
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
