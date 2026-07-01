@php
    use App\Enums\ScoreLevel;
@endphp

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
                        <x-form.date id="from" name="from" value="{{ $filters['from'] }}" />
                        <x-input-error :messages="$errors->get('from')" class="mt-2" />
                    </div>

                    <div>
                        <label for="to" class="block text-sm font-medium text-gray-700">To</label>
                        <x-form.date id="to" name="to" value="{{ $filters['to'] }}" />
                        <x-input-error :messages="$errors->get('to')" class="mt-2" />
                    </div>

                    <div>
                        <label for="alert_rule_id" class="block text-sm font-medium text-gray-700">Rule</label>
                        <x-form.select id="alert_rule_id" name="alert_rule_id" placeholder="All rules">
                            <option value="">All rules</option>
                            @foreach ($rules as $rule)
                                <option value="{{ $rule->id }}" @selected($filters['alert_rule_id'] === $rule->id)>{{ $rule->name }}</option>
                            @endforeach
                        </x-form.select>
                    </div>

                    <div>
                        <label for="level" class="block text-sm font-medium text-gray-700">Level</label>
                        <x-form.select id="level" name="level" placeholder="All levels">
                            <option value="">All levels</option>
                            @foreach ($levels as $level)
                                <option value="{{ $level->value }}" @selected($filters['level'] === $level->value)>{{ str($level->value)->replace('_', ' ')->title() }}</option>
                            @endforeach
                        </x-form.select>
                    </div>

                    <div>
                        <label for="minimum_score" class="block text-sm font-medium text-gray-700">Min Score</label>
                        <x-form.number id="minimum_score" name="minimum_score" min="0" max="100" value="{{ $filters['minimum_score'] }}" />
                        <x-input-error :messages="$errors->get('minimum_score')" class="mt-2" />
                    </div>

                    <div class="md:col-span-5 flex items-end">
                        <x-primary-button>Apply filters</x-primary-button>
                    </div>
                </form>
            </div>

            <div class="bg-white shadow sm:rounded-lg overflow-hidden">
                @if (session('status'))
                    <p class="border-b border-gray-200 px-4 py-3 text-sm text-green-700">{{ session('status') }}</p>
                @endif

                @if (session('error'))
                    <p class="border-b border-gray-200 px-4 py-3 text-sm text-red-700">{{ session('error') }}</p>
                @endif

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
                                <th class="px-4 py-3 text-right font-semibold text-gray-700">Boats</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-700">Landings</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-700">Trend</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-700">Resend</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @forelse ($scores as $score)
                                <tr>
                                    <td class="px-4 py-3 whitespace-nowrap text-gray-700">{{ $score->score_date->format('n/j/Y') }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap font-medium text-gray-900">{{ $score->alertRule->name }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-gray-700">{{ $score->alertRule->species->name }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-right font-semibold text-gray-900">{{ $score->score }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        @php
                                            $levelBadgeClass = match ($score->level) {
                                                ScoreLevel::WideOpen => 'bg-danger',
                                                ScoreLevel::Hot => 'bg-danger-accent',
                                                ScoreLevel::Active => 'bg-primary',
                                                ScoreLevel::Watch => 'bg-link',
                                                ScoreLevel::Cold => 'bg-muted',
                                            };
                                        @endphp

                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold text-surface shadow-sm {{ $levelBadgeClass }}">
                                            {{ str($score->level->value)->replace('_', ' ')->title() }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-right text-gray-700">{{ number_format($score->total_count) }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-right text-gray-700">{{ $score->boat_count }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-right text-gray-700">{{ $score->landing_count }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-right text-gray-700">{{ $score->trend_score }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-right">
                                        <form method="POST" action="{{ route('scores.hot-bite-email', $score) }}">
                                            @csrf
                                            <button
                                                type="submit"
                                                class="inline-flex min-w-24 items-center justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-xs font-semibold uppercase tracking-widest text-surface shadow-sm transition ease-in-out duration-150 hover:bg-link focus:bg-link focus:outline-none focus:ring-2 focus:ring-focus focus:ring-offset-2 active:bg-primary"
                                                onclick="return confirm(@js("Resend this hot bite email to {$score->alertRule->user->email}?"))"
                                            >
                                                Resend
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10" class="px-4 py-8 text-center text-sm text-gray-500">No scores match the current filters.</td>
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
