@forelse ($backfills as $backfill)
    @php
        $finishedItems = $backfill->succeeded_items_count + $backfill->failed_items_count + $backfill->unavailable_items_count;
        $progress = $backfill->items_count > 0 ? (int) floor(($finishedItems / $backfill->items_count) * 100) : 0;
    @endphp

    <div class="py-5 border-b space-y-4">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="font-medium text-gray-900">
                    <a class="text-blue-700" href="{{ route('admin.backfills.show', $backfill) }}">#{{ $backfill->id }}</a>
                    · {{ $backfill->from_date->format('n/j/Y') }} to {{ $backfill->to_date->format('n/j/Y') }}
                </p>
                <p class="text-sm text-gray-600">
                    {{ $backfill->status->value }} · {{ $finishedItems }} of {{ $backfill->items_count }} source dates complete · {{ $progress }}%
                </p>
                <div class="mt-2 h-2 rounded bg-gray-100">
                    <div class="h-2 rounded bg-blue-600" style="width: {{ $progress }}%"></div>
                </div>
                <p class="mt-2 text-xs text-gray-500">
                    Pending {{ $backfill->pending_items_count }} · Running {{ $backfill->running_items_count }} · Succeeded {{ $backfill->succeeded_items_count }} · Failed {{ $backfill->failed_items_count }} · Unavailable {{ $backfill->unavailable_items_count }}
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <a class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm transition duration-150 ease-in-out hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-25" href="{{ route('admin.backfills.show', $backfill) }}">
                    View source dates
                </a>

                @if (in_array($backfill->status, [\App\Enums\BackfillRunStatus::Pending, \App\Enums\BackfillRunStatus::Running], true))
                    <form method="POST" action="{{ route('admin.backfills.pause', $backfill) }}">
                        @csrf
                        <x-secondary-button type="submit">Pause</x-secondary-button>
                    </form>
                @endif

                @if ($backfill->status === \App\Enums\BackfillRunStatus::Paused)
                    <form method="POST" action="{{ route('admin.backfills.resume', $backfill) }}">
                        @csrf
                        <x-primary-button>Resume</x-primary-button>
                    </form>
                @endif

                @if ($backfill->failed_items_count > 0 || $backfill->unavailable_items_count > 0)
                    <form method="POST" action="{{ route('admin.backfills.retry-failed', $backfill) }}">
                        @csrf
                        <x-secondary-button type="submit">Retry failed dates</x-secondary-button>
                    </form>
                @endif

                @if (! in_array($backfill->status, [\App\Enums\BackfillRunStatus::Cancelled, \App\Enums\BackfillRunStatus::Succeeded], true))
                    <form method="POST" action="{{ route('admin.backfills.cancel', $backfill) }}">
                        @csrf
                        <x-secondary-button type="submit">Cancel</x-secondary-button>
                    </form>
                @endif
            </div>
        </div>

        @if ($backfill->items->isNotEmpty())
            <div class="rounded border border-gray-200 bg-gray-50 p-3">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Dates needing attention</p>
                <div class="mt-2 grid gap-2 md:grid-cols-2">
                    @foreach ($backfill->items->take(8) as $item)
                        <p class="text-sm text-gray-700">
                            {{ $item->target_date->format('n/j/Y') }} · {{ $item->scrapeSource->name }} · {{ $item->status->value }}
                            @if ($item->raw_scrape_payload_id)
                                <a class="text-blue-700" href="{{ route('admin.raw-payloads.show', $item->raw_scrape_payload_id) }}">Raw payload</a>
                            @endif
                            @if ($item->error_message)
                                <span class="text-gray-500">· {{ $item->error_message }}</span>
                            @endif
                        </p>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
@empty
    <p class="text-sm text-gray-500">No backfills.</p>
@endforelse

{{ $backfills->links() }}
