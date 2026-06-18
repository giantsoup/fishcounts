<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">Admin</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="grid gap-6 md:grid-cols-5">
                <div class="bg-white p-6 shadow sm:rounded-lg"><p class="text-sm text-gray-500">Users</p><p class="text-3xl font-semibold">{{ $userCount }}</p></div>
                <div class="bg-white p-6 shadow sm:rounded-lg"><p class="text-sm text-gray-500">Latest scrape</p><p class="text-sm">{{ $latestScrapeRun?->status?->value ?? 'None' }}</p></div>
                <div class="bg-white p-6 shadow sm:rounded-lg"><p class="text-sm text-gray-500">Latest backfill</p><p class="text-sm">{{ $latestBackfillRun?->status?->value ?? 'None' }}</p></div>
                <div class="bg-white p-6 shadow sm:rounded-lg"><p class="text-sm text-gray-500">Parser errors</p><p class="text-3xl font-semibold">{{ $openParserErrorCount }}</p></div>
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
                        <a class="text-blue-700" href="{{ route('admin.species-aliases.index') }}">Species aliases</a>
                        <a class="text-blue-700" href="{{ route('admin.trip-type-aliases.index') }}">Trip aliases</a>
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
        </div>
    </div>
</x-app-layout>
