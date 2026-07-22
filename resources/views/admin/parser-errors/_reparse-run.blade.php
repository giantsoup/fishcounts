@if ($latestReparseRun)
    @php
        $processedItems = $latestReparseRun->completed_items + $latestReparseRun->failed_items;
        $progress = $latestReparseRun->total_items > 0
            ? min(100, (int) round(($processedItems / $latestReparseRun->total_items) * 100))
            : 100;
    @endphp

    <section class="rounded-lg border border-blue-100 bg-blue-50 p-4" aria-labelledby="parser-reparse-run-heading">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h3 id="parser-reparse-run-heading" class="font-semibold text-gray-900">
                    Reparse run #{{ $latestReparseRun->id }}
                </h3>
                <p class="mt-1 text-sm text-gray-600">
                    Requested {{ $latestReparseRun->created_at->diffForHumans() }}
                    @if ($latestReparseRun->requester)
                        by {{ $latestReparseRun->requester->name }}
                    @endif
                    · {{ str($latestReparseRun->status->value)->headline() }}
                </p>
            </div>

            @if ($latestReparseRun->status === \App\Enums\ParserReparseRunStatus::Failed && $latestReparseRun->failed_items > 0)
                <form method="POST" action="{{ route('admin.parser-errors.reparse-runs.retry', $latestReparseRun) }}">
                    @csrf
                    <x-secondary-button type="submit">Retry failed items</x-secondary-button>
                </form>
            @endif
        </div>

        <progress class="mt-4 h-2 w-full overflow-hidden rounded-full" value="{{ $progress }}" max="100" aria-label="Reparse progress">{{ $progress }}%</progress>
        <p class="mt-2 text-xs text-gray-600" aria-live="polite">
            {{ $processedItems }} of {{ $latestReparseRun->total_items }} items finished
            @if ($latestReparseRun->failed_items > 0)
                · {{ $latestReparseRun->failed_items }} failed
            @endif
        </p>

        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-5">
            <div>
                <dt class="text-xs font-medium uppercase text-gray-500">Payloads</dt>
                <dd class="font-semibold text-gray-900">{{ $latestReparseRun->initial_payloads }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase text-gray-500">Dates</dt>
                <dd class="font-semibold text-gray-900">{{ $latestReparseRun->affected_dates }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase text-gray-500">Initial diagnostics</dt>
                <dd class="font-semibold text-gray-900">{{ $latestReparseRun->initial_open_errors }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase text-gray-500">Structural remaining</dt>
                <dd class="font-semibold text-gray-900">{{ $latestReparseRun->remaining_structural_errors ?? 'Pending' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase text-gray-500">Alias remaining</dt>
                <dd class="font-semibold text-gray-900">{{ $latestReparseRun->remaining_alias_errors ?? 'Pending' }}</dd>
            </div>
        </dl>

        @if ($latestReparseRun->error_message)
            <p class="mt-3 text-sm text-red-700">{{ $latestReparseRun->error_message }}</p>
        @endif

        @if (! $latestReparseRun->status->isActive())
            <p class="mt-4 text-sm">
                <a class="font-medium text-blue-700 hover:text-blue-900" href="{{ route('admin.parser-errors.index', request()->only('status')) }}">
                    Refresh parser errors
                </a>
                <span class="text-gray-500">to update the list below.</span>
            </p>
        @endif
    </section>
@endif
