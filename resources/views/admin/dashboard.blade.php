<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">Admin</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if ($aiOperationalAlerts !== [])
                <div class="rounded-lg border border-amber-300 bg-amber-50 p-5 text-sm text-amber-950" role="alert">
                    <h3 class="font-semibold">AI review operations need attention</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        @foreach ($aiOperationalAlerts as $alert)
                            <li>{{ $alert }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="grid gap-6 md:grid-cols-5">
                <a href="{{ route('admin.users.index') }}" class="bg-white p-6 shadow transition hover:shadow-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 sm:rounded-lg">
                    <p class="text-sm text-gray-500">Users</p>
                    <p class="text-3xl font-semibold">{{ $userCount }}</p>
                </a>
                <div class="bg-white p-6 shadow sm:rounded-lg">
                    <p class="text-sm text-gray-500">Latest scrape</p>
                    @if ($latestScrapeRun)
                        <a class="text-sm text-blue-700" href="{{ route('admin.scrape-runs.show', $latestScrapeRun) }}">
                            {{ $latestScrapeRun->status->value }}
                        </a>
                    @else
                        <p class="text-sm">None</p>
                    @endif
                    @if ($openParserErrorCount > 0)
                        <a href="{{ route('admin.parser-errors.index') }}" class="mt-2 block text-xs font-medium text-danger">
                            {{ $openParserErrorCount }} parser {{ Str::plural('warning', $openParserErrorCount) }} {{ $openParserErrorCount === 1 ? 'needs' : 'need' }} alias review
                        </a>
                    @endif
                </div>
                <a href="{{ route('admin.backfills.index') }}" class="bg-white p-6 shadow transition hover:shadow-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 sm:rounded-lg">
                    <p class="text-sm text-gray-500">Latest backfill</p>
                    <p class="text-sm">{{ $latestBackfillRun?->status?->value ?? 'None' }}</p>
                </a>
                <a href="{{ route('admin.parser-errors.index') }}" class="bg-white p-6 shadow transition hover:shadow-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 sm:rounded-lg">
                    <p class="text-sm text-gray-500">Parser errors</p>
                    <p class="text-3xl font-semibold">{{ $openParserErrorCount }}</p>
                </a>
                <a href="{{ route('admin.failed-jobs.index') }}" class="bg-white p-6 shadow sm:rounded-lg">
                    <p class="text-sm text-gray-500">Failed jobs</p>
                    <p class="text-3xl font-semibold">{{ $failedJobCount }}</p>
                </a>
            </div>

            <div class="bg-white p-6 shadow sm:rounded-lg">
                <div class="flex items-center justify-between">
                    <h3 class="font-semibold text-gray-900">Source health</h3>
                    <div class="flex gap-4 text-sm">
                        <a class="text-blue-700" href="{{ route('admin.sources.index') }}">Manage sources</a>
                        <a class="text-blue-700" href="{{ route('admin.species-aliases.index') }}">Species</a>
                        <a class="text-blue-700" href="{{ route('admin.trip-type-aliases.index') }}">Trips</a>
                    </div>
                </div>
                <div class="mt-4 divide-y">
                    @foreach ($sources as $source)
                        <div class="grid gap-2 py-3 text-sm md:grid-cols-4">
                            <p class="font-medium text-gray-900">{{ $source->name }}</p>
                            <p class="text-gray-600">{{ $source->is_enabled ? 'Enabled' : 'Disabled' }}</p>
                            <p class="text-gray-600">Last success: {{ $source->last_success_at?->diffForHumans() ?? 'Never' }}</p>
                            <p class="text-gray-600">Last failure: {{ $source->last_failure_at?->diffForHumans() ?? 'Never' }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="bg-white p-6 shadow sm:rounded-lg">
                <div class="flex items-center justify-between">
                    <h3 class="font-semibold text-gray-900">AI parser review operations</h3>
                    <p class="text-xs text-gray-500">Review metrics cover the last 24 hours unless noted.</p>
                </div>

                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div><p class="text-xs text-gray-500">Queue depth / oldest</p><p class="font-semibold">{{ number_format($aiMetrics['queue_depth']) }} / {{ $aiMetrics['queue_depth'] === 0 ? 'none' : now()->subSeconds($aiMetrics['queue_oldest_age_seconds'])->diffForHumans(short: true) }}</p></div>
                    <div><p class="text-xs text-gray-500">GitHub queue / oldest</p><p class="font-semibold">{{ number_format($aiMetrics['github_queue_depth']) }} / {{ $aiMetrics['github_queue_depth'] === 0 ? 'none' : now()->subSeconds($aiMetrics['github_queue_oldest_age_seconds'])->diffForHumans(short: true) }}</p></div>
                    <div><p class="text-xs text-gray-500">Succeeded / failed / refused</p><p class="font-semibold">{{ number_format($aiMetrics['succeeded']) }} / {{ number_format($aiMetrics['failed']) }} / {{ number_format($aiMetrics['refused']) }}</p></div>
                    <div><p class="text-xs text-gray-500">Schema failures / stale total</p><p class="font-semibold">{{ number_format($aiMetrics['schema_failures']) }} / {{ number_format($aiMetrics['stale']) }}</p></div>
                    <div><p class="text-xs text-gray-500">Accepted / rejected / automatic</p><p class="font-semibold">{{ number_format($aiMetrics['accepted']) }} / {{ number_format($aiMetrics['rejected']) }} / {{ number_format($aiMetrics['automatic_resolutions']) }}</p></div>
                    <div><p class="text-xs text-gray-500">Tokens / estimated cost</p><p class="font-semibold">{{ number_format($aiMetrics['tokens']) }} / ${{ number_format($aiMetrics['cost_micros'] / 1_000_000, 2) }}</p></div>
                    <div><p class="text-xs text-gray-500">GitHub duplicate occurrences</p><p class="font-semibold">{{ number_format($aiMetrics['github_duplicates']) }}</p></div>
                    <div><p class="text-xs text-gray-500">GitHub failures</p><p class="font-semibold">{{ number_format($aiMetrics['github_failures']) }}</p></div>
                    <div><p class="text-xs text-gray-500">Override invalidations</p><p class="font-semibold">{{ number_format($aiMetrics['override_invalidations']) }}</p></div>
                </div>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    @foreach ($aiMetrics['budgets'] as $budget)
                        <div class="rounded border border-gray-200 p-4">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $budget['period'] }} budget</p>
                            <p class="mt-1 text-lg font-semibold">${{ number_format($budget['remaining_micros'] / 1_000_000, 2) }} remaining</p>
                            <p class="text-xs text-gray-500">${{ number_format($budget['spent_micros'] / 1_000_000, 2) }} spent · ${{ number_format($budget['reserved_micros'] / 1_000_000, 2) }} reserved · ${{ number_format($budget['limit_micros'] / 1_000_000, 2) }} limit</p>
                        </div>
                    @endforeach
                </div>

                <div class="mt-6">
                    <h4 class="text-sm font-semibold text-gray-900">Recent bounded runs</h4>
                    <div class="mt-2 divide-y text-sm">
                        @forelse ($historicalAiReviewRuns as $run)
                            <div class="grid gap-1 py-2 md:grid-cols-5">
                                <p>#{{ $run->id }} · {{ $run->scope }}</p>
                                <p>{{ $run->date_from->toDateString() }}–{{ $run->date_to->toDateString() }}</p>
                                <p>{{ $run->status->value }}</p>
                                <p>{{ $run->completed_count }}/{{ $run->selected_count }} completed</p>
                                <p>${{ number_format($run->estimated_spent_micros / 1_000_000, 2) }} / ${{ number_format($run->budget_micros / 1_000_000, 2) }}</p>
                            </div>
                        @empty
                            <p class="py-2 text-gray-500">No bounded AI review runs yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
