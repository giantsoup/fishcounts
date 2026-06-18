<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800">Scores</h2>
            <a href="{{ route('scores.index') }}" class="text-sm text-gray-600 underline">Reset filters</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white p-6 shadow sm:rounded-lg">
                <form method="GET" action="{{ route('scores.index') }}" class="grid gap-4 md:grid-cols-5">
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
                        <label for="alert_rule_id" class="block text-sm font-medium text-gray-700">Rule</label>
                        <select id="alert_rule_id" name="alert_rule_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            <option value="">All rules</option>
                            @foreach ($rules as $rule)
                                <option value="{{ $rule->id }}" @selected($filters['alert_rule_id'] === $rule->id)>{{ $rule->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="level" class="block text-sm font-medium text-gray-700">Level</label>
                        <select id="level" name="level" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            <option value="">All levels</option>
                            @foreach ($levels as $level)
                                <option value="{{ $level->value }}" @selected($filters['level'] === $level->value)>{{ str($level->value)->replace('_', ' ')->title() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="minimum_score" class="block text-sm font-medium text-gray-700">Min Score</label>
                        <input id="minimum_score" name="minimum_score" type="number" min="0" max="100" value="{{ $filters['minimum_score'] }}" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        <x-input-error :messages="$errors->get('minimum_score')" class="mt-2" />
                    </div>

                    <div class="md:col-span-5 flex items-end">
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
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Rule</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Species</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-700">Score</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Level</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-700">Count</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-700">Per Angler</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-700">Boats</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-700">Landings</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-700">Trend</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-700">Breadth</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @forelse ($scores as $score)
                                <tr>
                                    <td class="px-4 py-3 whitespace-nowrap text-gray-700">{{ $score->score_date->toDateString() }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap font-medium text-gray-900">{{ $score->alertRule->name }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-gray-700">{{ $score->alertRule->species->name }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-right font-semibold text-gray-900">{{ $score->score }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ $score->score >= 80 ? 'bg-red-100 text-red-800' : ($score->score >= 70 ? 'bg-orange-100 text-orange-800' : ($score->score >= 60 ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-700')) }}">
                                            {{ str($score->level->value)->replace('_', ' ')->title() }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-right text-gray-700">{{ number_format($score->total_count) }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-right text-gray-700">{{ $score->count_per_angler !== null ? number_format((float) $score->count_per_angler, 2) : '—' }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-right text-gray-700">{{ $score->boat_count }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-right text-gray-700">{{ $score->landing_count }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-right text-gray-700">{{ $score->trend_score }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-right text-gray-700">{{ $score->breadth_score }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="11" class="px-4 py-8 text-center text-sm text-gray-500">No scores match the current filters.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-gray-200 px-4 py-3">
                    {{ $scores->links() }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
