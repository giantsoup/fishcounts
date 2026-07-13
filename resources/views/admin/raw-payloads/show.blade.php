<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="font-semibold text-xl text-gray-800">Raw payload</h2>
                @if ($payload->scrapeRun)
                    <a class="mt-1 block text-sm text-blue-700" href="{{ route('admin.scrape-runs.show', $payload->scrapeRun) }}">Back to scrape run #{{ $payload->scrapeRun->id }}</a>
                @endif
            </div>
            <div class="flex items-center gap-3">
                <a class="text-sm text-blue-700" href="{{ route('admin.scrape-runs.index') }}">All scrape runs</a>
                <form method="POST" action="{{ route('admin.raw-payloads.reparse', $payload) }}">
                    @csrf
                    <x-secondary-button type="submit">Reparse</x-secondary-button>
                </form>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <p class="text-sm text-green-700">{{ session('status') }}</p>
            @endif

            <div class="bg-white p-6 shadow sm:rounded-lg">
                <dl class="grid gap-3 text-sm md:grid-cols-3">
                    <div>
                        <dt class="font-medium text-gray-500">Source</dt>
                        <dd class="text-gray-900">{{ $payload->scrapeSource->name }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-500">Date</dt>
                        <dd class="text-gray-900">{{ $payload->target_date->format('n/j/Y') }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-500">HTTP status</dt>
                        <dd class="text-gray-900">{{ $payload->http_status ?? 'n/a' }}</dd>
                    </div>
                    <div class="md:col-span-2">
                        <dt class="font-medium text-gray-500">URL</dt>
                        <dd class="break-all text-gray-900">{{ $payload->url }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-500">Parser version</dt>
                        <dd class="text-gray-900">{{ $payload->parser_version ?? 'Not parsed' }}</dd>
                    </div>
                    <div class="md:col-span-3">
                        <dt class="font-medium text-gray-500">Payload hash</dt>
                        <dd class="break-all text-gray-900">{{ $payload->payload_hash }}</dd>
                    </div>
                </dl>
            </div>

            <div class="bg-white p-6 shadow sm:rounded-lg">
                <h3 class="font-semibold text-gray-900">Parsed result preview</h3>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="py-2 pe-4">Boat</th>
                                <th class="py-2 pe-4">Landing</th>
                                <th class="py-2 pe-4">Trip</th>
                                <th class="py-2 pe-4">Anglers</th>
                                <th class="py-2 pe-4">Counts</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($payload->tripReports as $report)
                                <tr>
                                    <td class="py-2 pe-4">{{ $report->boat?->name ?? $report->raw_boat_name ?? 'Unknown' }}</td>
                                    <td class="py-2 pe-4">{{ $report->landing?->name ?? $report->raw_landing_name ?? 'Unknown' }}</td>
                                    <td class="py-2 pe-4">{{ $report->tripType?->name ?? $report->raw_trip_type ?? 'Unknown' }}</td>
                                    <td class="py-2 pe-4">{{ $report->anglers ?? 'n/a' }}</td>
                                    <td class="py-2 pe-4">
                                        {{ $report->speciesCounts->map(fn ($count) => $count->species->name.' '.$count->count.($count->released_count > 0 ? ' / '.$count->released_count.' released' : ''))->implode(', ') }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-4 text-gray-500">No parsed reports for this payload yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($payload->parserReportOverrides->isNotEmpty())
                <div class="bg-white p-6 shadow sm:rounded-lg">
                    <h3 class="font-semibold text-gray-900">Report override audit history</h3>
                    <div class="mt-4 space-y-4">
                        @foreach ($payload->parserReportOverrides->sortByDesc('created_at') as $reportOverride)
                            <article class="rounded border border-gray-200 p-4 text-sm">
                                <p class="font-medium text-gray-900">
                                    {{ str($reportOverride->status->value)->headline() }} · report {{ $reportOverride->report_index + 1 }} · GitHub issue #{{ $reportOverride->parserBugReport->issue_number }}
                                </p>
                                <p class="mt-1 text-xs text-gray-600">
                                    Proposed by {{ $reportOverride->created_by_name }} on {{ $reportOverride->created_at->format('n/j/Y g:i A') }}
                                    @if ($reportOverride->approved_at)
                                        · approved by {{ $reportOverride->approved_by_name }} on {{ $reportOverride->approved_at->format('n/j/Y g:i A') }}
                                    @endif
                                </p>
                                @if ($reportOverride->invalidation_reason)
                                    <p class="mt-2 text-amber-800">Invalidated: {{ str($reportOverride->invalidation_reason)->headline() }}</p>
                                @elseif ($reportOverride->disable_reason)
                                    <p class="mt-2 text-gray-700">Disabled: {{ $reportOverride->disable_reason }}</p>
                                @endif
                                @if ($reportOverride->status === \App\Enums\ParserReportOverrideStatus::Active)
                                    <form method="POST" action="{{ route('admin.parser-report-overrides.disable', $reportOverride) }}" class="mt-3 space-y-2">
                                        @csrf
                                        <textarea name="disable_reason" rows="2" maxlength="1000" required class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Reason for rollback"></textarea>
                                        <x-secondary-button type="submit">Disable override and restore deterministic parse</x-secondary-button>
                                    </form>
                                @endif
                                <details class="mt-2">
                                    <summary class="cursor-pointer font-medium text-gray-700">Original and corrected parse</summary>
                                    <pre class="mt-2 max-h-96 overflow-auto whitespace-pre-wrap rounded bg-gray-50 p-3 text-xs">{{ json_encode(['original' => $reportOverride->original_parse, 'corrected' => $reportOverride->corrected_parse], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            </article>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="bg-white p-6 shadow sm:rounded-lg">
                <h3 class="font-semibold text-gray-900">Raw preview</h3>
                <pre class="mt-4 overflow-auto whitespace-pre-wrap rounded bg-gray-50 p-4 text-xs">{{ $payload->payload }}</pre>
            </div>
        </div>
    </div>
</x-app-layout>
