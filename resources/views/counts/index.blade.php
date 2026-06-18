<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800">Counts</h2>
            <a href="{{ route('counts.index') }}" class="text-sm text-gray-600 underline">Reset filters</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white p-6 shadow sm:rounded-lg">
                <form method="GET" action="{{ route('counts.index') }}" class="grid gap-4 md:grid-cols-4">
                    <div>
                        <label for="from" class="block text-sm font-medium text-gray-700">From</label>
                        <input id="from" name="from" type="date" value="{{ $filters['from'] }}" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        <x-input-error :messages="$errors->get('from')" class="mt-2" />
                    </div>

                    <div>
                        <label for="to" class="block text-sm font-medium text-gray-700">To</label>
                        <input id="to" name="to" type="date" value="{{ $filters['to'] }}" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        <x-input-error :messages="$errors->get('to')" class="mt-2" />
                    </div>

                    <div>
                        <label for="species_id" class="block text-sm font-medium text-gray-700">Species</label>
                        <select id="species_id" name="species_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            <option value="">All species</option>
                            @foreach ($species as $option)
                                <option value="{{ $option->id }}" @selected($filters['species_id'] === $option->id)>{{ $option->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="trip_type_id" class="block text-sm font-medium text-gray-700">Trip Type</label>
                        <select id="trip_type_id" name="trip_type_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            <option value="">All trip types</option>
                            @foreach ($tripTypes as $option)
                                <option value="{{ $option->id }}" @selected($filters['trip_type_id'] === $option->id)>{{ $option->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="landing_id" class="block text-sm font-medium text-gray-700">Landing</label>
                        <select id="landing_id" name="landing_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            <option value="">All landings</option>
                            @foreach ($landings as $option)
                                <option value="{{ $option->id }}" @selected($filters['landing_id'] === $option->id)>{{ $option->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="boat_id" class="block text-sm font-medium text-gray-700">Boat</label>
                        <select id="boat_id" name="boat_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            <option value="">All boats</option>
                            @foreach ($boats as $option)
                                <option value="{{ $option->id }}" @selected($filters['boat_id'] === $option->id)>{{ $option->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="md:col-span-2 flex items-end">
                        <x-primary-button>Apply filters</x-primary-button>
                    </div>
                </form>
            </div>

            <div class="bg-white shadow sm:rounded-lg overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Date</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Landing</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Boat</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Trip</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-700">Anglers</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Species</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-700">Retained</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-700">Released</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-700">Per Angler</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Source</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @forelse ($counts as $count)
                                @php
                                    $report = $count->tripReport;
                                    $perAngler = $report?->anglers ? round($count->count / $report->anglers, 2) : null;
                                @endphp
                                <tr>
                                    <td class="px-4 py-3 whitespace-nowrap text-gray-700">{{ $report?->trip_date?->toDateString() }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-gray-900">{{ $report?->landing?->name ?? $report?->raw_landing_name ?? 'Unknown' }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-gray-700">{{ $report?->boat?->name ?? $report?->raw_boat_name ?? 'Unknown' }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-gray-700">{{ $report?->tripType?->name ?? $report?->raw_trip_type ?? 'Unknown' }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-right text-gray-700">{{ $report?->anglers ?? '—' }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap font-medium text-gray-900">{{ $count->species->name }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-right text-gray-900">{{ number_format($count->count) }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-right text-gray-700">{{ $count->released_count > 0 ? number_format($count->released_count) : '—' }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-right text-gray-700">{{ $perAngler !== null ? number_format($perAngler, 2) : '—' }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-gray-700">{{ $report?->source?->name ?? 'Unknown' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10" class="px-4 py-8 text-center text-sm text-gray-500">No counts match the current filters.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-gray-200 px-4 py-3">
                    {{ $counts->links() }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
