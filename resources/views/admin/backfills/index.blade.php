<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between">
            <h2 class="font-semibold text-xl text-gray-800">Backfills</h2>
            <a class="text-sm text-blue-700" href="{{ route('admin.backfills.create') }}">New backfill</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div
            class="max-w-7xl mx-auto sm:px-6 lg:px-8 bg-white p-6 shadow sm:rounded-lg"
            data-backfill-poll
            data-backfill-poll-active="{{ $hasActiveBackfills ? 'true' : 'false' }}"
            data-backfill-poll-interval="5000"
            data-backfill-poll-url="{{ route('admin.backfills.poll', request()->query()) }}"
        >
            @if (session('status'))
                <p class="mb-4 text-sm text-green-700">{{ session('status') }}</p>
            @endif

            <div class="mb-4 flex items-center gap-2 text-xs text-gray-500" data-backfill-poll-status>
                @if ($hasActiveBackfills)
                    <span class="h-2 w-2 rounded-full bg-blue-600"></span>
                    <span>Live updates enabled</span>
                @else
                    <span class="h-2 w-2 rounded-full bg-gray-300"></span>
                    <span>Live updates idle</span>
                @endif
            </div>

            <div data-backfill-poll-target>
                @include('admin.backfills._list', ['backfills' => $backfills])
            </div>
        </div>
    </div>
</x-app-layout>
