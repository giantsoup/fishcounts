<div class="bg-white p-6 shadow sm:rounded-lg">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <h3 class="font-semibold text-gray-900">Reparse saved payloads</h3>
            <p class="mt-1 max-w-3xl text-sm text-gray-600">
                Runs the parser again against the {{ $reparseablePayloadCount }} raw payload(s) already saved for this backfill. This does not scrape sources or change the original backfill status.
            </p>
        </div>
        <form method="POST" action="{{ route('admin.backfills.reparse', $backfill) }}">
            @csrf
            <x-primary-button class="disabled:opacity-50 disabled:cursor-not-allowed" :disabled="$hasActiveReparseRun || $reparseablePayloadCount === 0">
                Reparse
            </x-primary-button>
        </form>
    </div>

    @if ($latestReparseRun)
        <div class="mt-5 overflow-x-auto">
            <table class="w-full min-w-max text-left">
                <thead>
                    <tr class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        <th scope="col" class="pb-1 pe-10">Latest run</th>
                        <th scope="col" class="pb-1 pe-10">Status</th>
                        <th scope="col" class="pb-1 pe-10">Queued</th>
                        <th scope="col" class="pb-1 pe-10">Completed</th>
                        <th scope="col" class="pb-1 pe-10">Failed</th>
                        <th scope="col" class="pb-1">Finished</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="text-sm">
                        <td class="pt-1 pe-10 font-semibold text-gray-900">#{{ $latestReparseRun->id }}</td>
                        <td class="pt-1 pe-10 font-semibold text-gray-900">{{ $latestReparseRun->status->value }}</td>
                        <td class="pt-1 pe-10 font-semibold text-gray-900">{{ $latestReparseRun->queued_payloads }} of {{ $latestReparseRun->total_payloads }}</td>
                        <td class="pt-1 pe-10 font-semibold text-gray-900">{{ $latestReparseRun->completed_payloads }}</td>
                        <td class="pt-1 pe-10 font-semibold text-gray-900">{{ $latestReparseRun->failed_payloads }}</td>
                        <td class="pt-1 font-semibold text-gray-900">{{ $latestReparseRun->finished_at?->diffForHumans() ?? 'Not finished' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        @if ($latestReparseRun->error_message)
            <p class="mt-3 text-sm text-red-700">{{ $latestReparseRun->error_message }}</p>
        @endif
    @endif
</div>
