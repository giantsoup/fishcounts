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
            class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:grid lg:grid-cols-2 lg:items-start lg:gap-6 lg:space-y-0 lg:px-8"
            x-data="tripTypeManager"
            data-trip-types="{{ $tripTypeOptions->toJson() }}"
            data-selected-trip-type-id="{{ $normalizedSelectedTripTypeId }}"
            @if ($oldOrderSortOrder !== null) data-old-order-sort-order="{{ $oldOrderSortOrder }}" @endif
        >
            @if (session('status'))
                <p class="text-sm text-green-700 lg:col-span-2">{{ session('status') }}</p>
            @endif

            <div class="bg-white p-6 shadow sm:rounded-lg">
                <div class="flex flex-col gap-4">
                    <div>
                        <h3 class="font-semibold text-gray-900">Active trips</h3>
                        <p class="mt-1 text-sm text-gray-500">Choose the canonical trip names and display order used throughout counts, alerts, and fishing reports.</p>
                    </div>

                    <form method="POST" action="{{ route('admin.trip-types.store') }}" class="grid gap-3 sm:grid-cols-[minmax(12rem,1fr)_7rem] sm:items-end">
                        @csrf
                        <div>
                            <x-input-label for="trip_type_name" value="Name" />
                            <x-text-input id="trip_type_name" name="name" class="mt-1 block w-full" :value="old('name')" />
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="sort_order" value="Order" />
                            <x-text-input id="sort_order" name="sort_order" type="number" min="0" :max="$maximumTripTypeSortOrder" class="mt-1 block w-full" :value="old('sort_order')" />
                            <x-input-error :messages="$errors->get('sort_order')" class="mt-2" />
                        </div>
                        <x-primary-button class="w-full justify-center sm:col-span-2 sm:w-auto sm:justify-self-end">Save trip</x-primary-button>
                    </form>
                </div>

                <div class="mt-4 grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-2 text-sm">
                    @foreach ($tripTypes as $tripType)
                        <button
                            type="button"
                            class="flex min-h-14 flex-col items-center justify-center rounded border px-3 py-2 text-center transition focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                            :class="selectedTripTypeId === {{ $tripType->id }} ? 'border-gray-950 bg-gray-950 text-white' : 'border-gray-200 bg-white text-gray-900 hover:border-gray-400'"
                            :aria-pressed="selectedTripTypeId === {{ $tripType->id }}"
                            aria-controls="trip_type_editor"
                            @click="selectTripType({{ $tripType->id }})"
                        >
                            <span class="text-base font-semibold leading-tight">{{ $tripType->name }}</span>
                            <span class="mt-1 text-xs" :class="selectedTripTypeId === {{ $tripType->id }} ? 'text-gray-200' : 'text-gray-500'">Order {{ $tripType->sort_order }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

            <div id="trip_type_editor" x-ref="tripTypeEditor" role="region" aria-label="Trip editor" class="scroll-mt-4 overflow-hidden bg-white shadow sm:rounded-lg">
                <div x-show="! selectedTripType">
                    <div class="px-6 py-12 text-center">
                        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-fc-blue-soft text-xl font-semibold text-primary">
                            T
                        </div>
                        <h3 class="mt-4 text-base font-semibold text-gray-900">Choose a trip to manage</h3>
                        <p class="mx-auto mt-1 max-w-md text-sm text-gray-500">Select a trip above to update its display order or add another name for it.</p>
                        <x-input-error :messages="$errors->get('trip_type_id')" class="mt-3" />
                        <x-input-error :messages="$errors->get('order_sort_order')" class="mt-3" />
                    </div>
                </div>

                <div x-show="selectedTripType">
                    <div class="border-b border-border bg-fc-blue-soft px-6 py-5">
                        <p class="text-xs font-semibold uppercase tracking-wider text-link">Editing canonical trip</p>
                        <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <h3 class="text-2xl font-semibold tracking-tight text-gray-900" x-text="selectedTripType?.name"></h3>
                                <p class="mt-1 text-sm text-gray-600">Changes below apply to this trip everywhere it appears in FishCounts.</p>
                            </div>
                            <div class="flex flex-wrap gap-2 text-xs font-medium">
                                <span class="inline-flex items-center rounded-full border border-border bg-white px-3 py-1.5 text-gray-700" x-text="selectedTripType ? 'Order ' + selectedTripType.sort_order : ''"></span>
                                <span class="inline-flex items-center rounded-full border border-border bg-white px-3 py-1.5 text-gray-700" x-text="selectedTripType ? selectedTripType.aliases.length + (selectedTripType.aliases.length === 1 ? ' alternate name' : ' alternate names') : ''"></span>
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-5 p-6">
                        <form method="POST" :action="selectedTripType?.update_url" class="flex h-full flex-col rounded-lg border border-border bg-white p-5 shadow-sm">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="order_trip_type_id" :value="selectedTripTypeId">

                            <div class="flex items-start gap-3">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-white">1</span>
                                <div>
                                    <h4 class="font-semibold text-gray-900">Display order</h4>
                                    <p class="mt-1 text-sm text-gray-500">Set where this trip appears in filters and management lists. Lower numbers appear first.</p>
                                </div>
                            </div>

                            <div class="mt-5 max-w-32">
                                <x-input-label for="order_sort_order" value="Order" />
                                <x-text-input id="order_sort_order" name="order_sort_order" type="number" min="0" :max="$maximumTripTypeSortOrder" class="mt-1 block w-full" x-model="orderSortOrder" />
                                <x-input-error :messages="$errors->get('order_sort_order')" class="mt-2" />
                            </div>

                            <div class="mt-auto flex justify-end pt-5">
                                <x-secondary-button type="submit" class="w-full justify-center sm:w-auto">Save display order</x-secondary-button>
                            </div>
                        </form>

                        <form method="POST" action="{{ route('admin.trip-type-aliases.store') }}" class="flex h-full flex-col rounded-lg border border-border bg-white p-5 shadow-sm">
                            @csrf
                            <input type="hidden" name="trip_type_id" :value="selectedTripTypeId">

                            <div class="flex items-start gap-3">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-white">2</span>
                                <div>
                                    <h4 class="font-semibold text-gray-900">Add an alternate name</h4>
                                    <p class="mt-1 text-sm text-gray-500">Connect another spelling or source label to this canonical trip.</p>
                                </div>
                            </div>

                            <div class="mt-5">
                                <x-input-label for="alias" value="Alternate trip name" />
                                <x-text-input id="alias" name="alias" class="mt-1 block w-full" :value="old('alias')" placeholder="e.g. Three Quarter Day" aria-describedby="trip_type_alias_help" />
                                <x-input-error :messages="$errors->get('alias')" class="mt-2" />
                                <x-input-error :messages="$errors->get('trip_type_id')" class="mt-2" />
                            </div>

                            <div id="trip_type_alias_help" class="mt-4 rounded-md border border-border bg-fc-blue-soft px-4 py-3">
                                <p class="text-xs font-semibold uppercase tracking-wide text-primary">What happens next</p>
                                <p class="mt-1 text-sm text-gray-600">Future imports automatically match this name to the selected trip, keeping reports and alerts under one canonical record.</p>
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
                                <p class="mt-1 text-sm text-gray-500">Names below are automatically matched to this trip during imports.</p>
                            </div>
                            <span class="mt-2 inline-flex w-fit rounded-full bg-white px-3 py-1 text-xs font-medium text-gray-600 ring-1 ring-border sm:mt-0" x-text="selectedTripType ? selectedTripType.aliases.length + (selectedTripType.aliases.length === 1 ? ' name' : ' names') : ''"></span>
                        </div>

                        <template x-if="selectedTripType && selectedTripType.aliases.length === 0">
                            <div class="mt-4 rounded-lg border border-dashed border-border bg-white px-5 py-6 text-center">
                                <p class="text-sm font-medium text-gray-700">No alternate names for this trip.</p>
                                <p class="mt-1 text-xs text-gray-500">Use the alternate-name form above when another label should resolve here.</p>
                            </div>
                        </template>

                        <div class="mt-4 divide-y divide-border overflow-hidden rounded-lg border border-border bg-white" x-show="selectedTripType && selectedTripType.aliases.length > 0">
                            <template x-for="alias in selectedTripType ? selectedTripType.aliases : []" :key="alias.id">
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
