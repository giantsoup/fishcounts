<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">Failed jobs</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 bg-white p-6 shadow sm:rounded-lg">
            @forelse ($jobs as $job)
                <div class="border-b py-4">
                    <div class="flex flex-col gap-1 md:flex-row md:items-start md:justify-between">
                        <div>
                            <p class="font-medium text-gray-900">{{ $job->display_name }}</p>
                            <p class="text-sm text-gray-600">{{ $job->connection }} · {{ $job->queue }} · {{ \Illuminate\Support\Carbon::parse($job->failed_at)->diffForHumans() }}</p>
                        </div>
                        <p class="break-all text-xs text-gray-500">{{ $job->uuid }}</p>
                    </div>
                    <p class="mt-3 text-sm text-red-700">{{ $job->exception_summary }}</p>
                </div>
            @empty
                <p class="text-sm text-gray-500">No failed jobs.</p>
            @endforelse

            {{ $jobs->links() }}
        </div>
    </div>
</x-app-layout>
