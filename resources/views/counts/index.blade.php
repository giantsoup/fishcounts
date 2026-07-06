@php
    $formatSpeciesCounts = function ($report): string {
        return $report->speciesCounts
            ->flatMap(function ($count) {
                $parts = collect();

                if ($count->count > 0) {
                    $parts->push(number_format($count->count).' '.$count->species->name);
                }

                if ($count->released_count > 0) {
                    $parts->push(number_format($count->released_count).' '.$count->species->name.' Released');
                }

                return $parts->isNotEmpty()
                    ? $parts
                    : collect([number_format($count->count).' '.$count->species->name]);
            })
            ->implode(', ');
    };
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-800">Counts</h2>
                <p class="text-sm text-gray-600">{{ $dateLabel }}</p>
            </div>
            <a href="{{ route('counts.index') }}" class="text-sm text-gray-600 underline">Reset filters</a>
        </div>
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="mx-auto max-w-[1480px] space-y-5 sm:px-6 lg:px-8">
            <section class="bg-white px-4 py-4 shadow sm:rounded-lg sm:px-5">
                <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-5">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Trips</p>
                        <p class="mt-1 text-2xl font-semibold text-gray-900">{{ number_format($summary['trips']) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Boats</p>
                        <p class="mt-1 text-2xl font-semibold text-gray-900">{{ number_format($summary['boats']) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Anglers</p>
                        <p class="mt-1 text-2xl font-semibold text-gray-900">{{ number_format($summary['anglers']) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Retained</p>
                        <p class="mt-1 text-2xl font-semibold text-gray-900">{{ number_format($summary['retained']) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Released</p>
                        <p class="mt-1 text-2xl font-semibold text-gray-900">{{ number_format($summary['released']) }}</p>
                    </div>
                </div>
            </section>

            <section class="bg-white px-4 py-5 shadow sm:rounded-lg sm:p-6">
                <form method="GET" action="{{ route('counts.index') }}" class="grid gap-4 md:grid-cols-6">
                    <div>
                        <label for="from" class="block text-sm font-medium text-gray-700">From</label>
                        <x-form.date id="from" name="from" value="{{ $filters['from'] }}" />
                        <x-input-error :messages="$errors->get('from')" class="mt-2" />
                    </div>

                    <div>
                        <label for="to" class="block text-sm font-medium text-gray-700">To</label>
                        <x-form.date id="to" name="to" value="{{ $filters['to'] }}" />
                        <x-input-error :messages="$errors->get('to')" class="mt-2" />
                    </div>

                    <div>
                        <label for="species_id" class="block text-sm font-medium text-gray-700">Species</label>
                        <x-form.select id="species_id" name="species_id" placeholder="All species">
                            <option value="">All species</option>
                            @foreach ($species as $option)
                                <option value="{{ $option->id }}" @selected($filters['species_id'] === $option->id)>{{ $option->name }}</option>
                            @endforeach
                        </x-form.select>
                    </div>

                    <div>
                        <label for="trip_type_id" class="block text-sm font-medium text-gray-700">Trip Type</label>
                        <x-form.select id="trip_type_id" name="trip_type_id" placeholder="All trip types">
                            <option value="">All trip types</option>
                            @foreach ($tripTypes as $option)
                                <option value="{{ $option->id }}" @selected($filters['trip_type_id'] === $option->id)>{{ $option->name }}</option>
                            @endforeach
                        </x-form.select>
                    </div>

                    <div>
                        <label for="landing_id" class="block text-sm font-medium text-gray-700">Landing</label>
                        <x-form.select id="landing_id" name="landing_id" placeholder="All landings">
                            <option value="">All landings</option>
                            @foreach ($landings as $option)
                                <option value="{{ $option->id }}" @selected($filters['landing_id'] === $option->id)>{{ $option->name }}</option>
                            @endforeach
                        </x-form.select>
                    </div>

                    <div>
                        <label for="boat_id" class="block text-sm font-medium text-gray-700">Boat</label>
                        <x-form.select id="boat_id" name="boat_id" placeholder="All boats">
                            <option value="">All boats</option>
                            @foreach ($boats as $option)
                                <option value="{{ $option->id }}" @selected($filters['boat_id'] === $option->id)>{{ $option->name }}</option>
                            @endforeach
                        </x-form.select>
                    </div>

                    <div class="flex items-end md:col-span-6">
                        <x-primary-button>Apply filters</x-primary-button>
                    </div>
                </form>
            </section>

            <section class="overflow-hidden bg-white shadow sm:rounded-lg">
                <div class="divide-y divide-gray-100 md:hidden">
                    @forelse ($reports as $report)
                        @php
                            $boatName = $report->boat?->name ?? $report->raw_boat_name ?? 'Unknown';
                            $landingName = $report->landing?->name ?? $report->raw_landing_name ?? 'Unknown';
                            $tripTypeName = $report->tripType?->name ?? $report->raw_trip_type ?? 'Unknown';
                            $fishCountText = $formatSpeciesCounts($report);
                        @endphp

                        <article class="px-4 py-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h3 class="truncate text-base font-semibold text-gray-900">{{ $boatName }}</h3>
                                    <p class="mt-0.5 text-sm text-gray-600">{{ $landingName }}</p>
                                </div>
                                <p class="shrink-0 text-sm font-medium text-gray-700">{{ $report->trip_date->format('n/j/Y') }}</p>
                            </div>

                            <div class="mt-3 flex flex-wrap gap-x-3 gap-y-1 text-xs font-medium uppercase tracking-wide text-gray-500">
                                <span>{{ $tripTypeName }}</span>
                                <span>{{ $report->anglers !== null ? number_format($report->anglers).' Anglers' : 'Anglers —' }}</span>
                                <span>{{ $report->source?->name ?? 'Unknown' }}</span>
                            </div>

                            <p class="mt-3 text-sm leading-6 text-gray-900">{{ $fishCountText !== '' ? $fishCountText : 'No fish count reported.' }}</p>
                        </article>
                    @empty
                        <p class="px-4 py-8 text-center text-sm text-gray-500">No counts match the current filters.</p>
                    @endforelse
                </div>

                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Date</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Boat</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Trip Details</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-700">Anglers</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Fish Count</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Source</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @forelse ($reports as $report)
                                @php
                                    $boatName = $report->boat?->name ?? $report->raw_boat_name ?? 'Unknown';
                                    $landingName = $report->landing?->name ?? $report->raw_landing_name ?? 'Unknown';
                                    $tripTypeName = $report->tripType?->name ?? $report->raw_trip_type ?? 'Unknown';
                                    $fishCountText = $formatSpeciesCounts($report);
                                @endphp
                                <tr>
                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $report->trip_date->format('n/j/Y') }}</td>
                                    <td class="px-4 py-3">
                                        <p class="whitespace-nowrap font-semibold text-gray-900">{{ $boatName }}</p>
                                        <p class="whitespace-nowrap text-xs text-gray-500">{{ $landingName }}</p>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $tripTypeName }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-gray-700">{{ $report->anglers !== null ? number_format($report->anglers) : '—' }}</td>
                                    <td class="min-w-[360px] px-4 py-3 leading-6 text-gray-900">{{ $fishCountText !== '' ? $fishCountText : 'No fish count reported.' }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $report->source?->name ?? 'Unknown' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500">No counts match the current filters.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-gray-200 px-4 py-3">
                    {{ $reports->links() }}
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
