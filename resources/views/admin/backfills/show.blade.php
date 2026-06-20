<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="font-semibold text-xl text-gray-800">Backfill #{{ $backfill->id }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ $backfill->from_date->format('n/j/Y') }} to {{ $backfill->to_date->format('n/j/Y') }}</p>
            </div>
            <a class="text-sm text-blue-700" href="{{ route('admin.backfills.index') }}">Back to backfills</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto space-y-6 sm:px-6 lg:px-8">
            @if (session('status'))
                <p class="text-sm text-green-700">{{ session('status') }}</p>
            @endif

            <div class="bg-white px-6 py-4 shadow sm:rounded-lg">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-max text-left">
                        <thead>
                            <tr class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                <th scope="col" class="pb-1 pe-12">Status</th>
                                <th scope="col" class="pb-1 pe-12">Total</th>
                                <th scope="col" class="pb-1 pe-12">Succeeded</th>
                                <th scope="col" class="pb-1 pe-12">Running</th>
                                <th scope="col" class="pb-1 pe-12">Pending</th>
                                <th scope="col" class="pb-1">Needs attention</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="text-sm">
                                <td class="pt-1 pe-12 font-semibold text-gray-900">{{ $backfill->status->value }}</td>
                                <td class="pt-1 pe-12 font-semibold text-gray-900">{{ $backfill->items_count }}</td>
                                <td class="pt-1 pe-12 font-semibold text-gray-900">{{ $backfill->succeeded_items_count }}</td>
                                <td class="pt-1 pe-12 font-semibold text-gray-900">{{ $backfill->running_items_count }}</td>
                                <td class="pt-1 pe-12 font-semibold text-gray-900">{{ $backfill->pending_items_count }}</td>
                                <td class="pt-1 font-semibold text-gray-900">{{ $backfill->failed_items_count + $backfill->unavailable_items_count }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div
                class="space-y-3"
                data-backfill-poll
                data-backfill-poll-active="{{ $hasActiveReparseRun ? 'true' : 'false' }}"
                data-backfill-poll-active-key="has_active_reparse"
                data-backfill-poll-active-message="Reparse running"
                data-backfill-poll-complete-message="Reparse complete"
                data-backfill-poll-paused-message="Reparse updates paused"
                data-backfill-poll-interval="2000"
                data-backfill-poll-url="{{ route('admin.backfills.reparse-poll', $backfill) }}"
            >
                <div class="flex items-center gap-2 text-xs text-gray-500" data-backfill-poll-status>
                    @if ($hasActiveReparseRun)
                        <span class="h-2 w-2 rounded-full bg-blue-600"></span>
                        <span>Reparse running</span>
                    @else
                        <span class="h-2 w-2 rounded-full bg-gray-300"></span>
                        <span>Reparse idle</span>
                    @endif
                </div>
                <div data-backfill-poll-target>
                    @include('admin.backfills._reparse', [
                        'backfill' => $backfill,
                        'latestReparseRun' => $latestReparseRun,
                        'reparseablePayloadCount' => $reparseablePayloadCount,
                        'hasActiveReparseRun' => $hasActiveReparseRun,
                    ])
                </div>
            </div>

            <div class="bg-white p-6 shadow sm:rounded-lg">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <h3 class="font-semibold text-gray-900">Source dates</h3>
                    <a class="text-sm text-blue-700" href="{{ route('admin.scrape-runs.index') }}">All scrape runs</a>
                </div>

                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="py-2 pe-4">Date</th>
                                <th class="py-2 pe-4">Source</th>
                                <th class="py-2 pe-4">Status</th>
                                <th class="py-2 pe-4">Scrape run</th>
                                <th class="py-2 pe-4">Raw payload</th>
                                <th class="py-2 pe-4">Error</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($items as $item)
                                <tr>
                                    <td class="py-2 pe-4">{{ $item->target_date->format('n/j/Y') }}</td>
                                    <td class="py-2 pe-4">{{ $item->scrapeSource->name }}</td>
                                    <td class="py-2 pe-4">{{ $item->status->value }}</td>
                                    <td class="py-2 pe-4">
                                        @if ($item->scrapeRun)
                                            <a class="text-blue-700" href="{{ route('admin.scrape-runs.show', $item->scrapeRun) }}">#{{ $item->scrapeRun->id }}</a>
                                        @else
                                            <span class="text-gray-500">n/a</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pe-4">
                                        @if ($item->rawScrapePayload)
                                            <a class="text-blue-700" href="{{ route('admin.raw-payloads.show', $item->rawScrapePayload) }}">View payload</a>
                                        @else
                                            <span class="text-gray-500">n/a</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pe-4 text-gray-600">{{ $item->error_message ?? 'n/a' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-4 text-gray-500">No source dates.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">
                    {{ $items->links() }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
