<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800">Raw payload</h2>
            <form method="POST" action="{{ route('admin.raw-payloads.reparse', $payload) }}">
                @csrf
                <x-secondary-button>Reparse</x-secondary-button>
            </form>
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

            <div class="bg-white p-6 shadow sm:rounded-lg">
                <h3 class="font-semibold text-gray-900">Raw preview</h3>
                <pre class="mt-4 overflow-auto whitespace-pre-wrap rounded bg-gray-50 p-4 text-xs">{{ $payload->payload }}</pre>
            </div>
        </div>
    </div>
</x-app-layout>
