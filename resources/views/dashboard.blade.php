<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Dashboard</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="bg-white p-4 shadow sm:rounded-lg text-sm text-green-700">{{ session('status') }}</div>
            @endif

            <div class="grid gap-6 md:grid-cols-3">
                <section class="bg-white p-6 shadow sm:rounded-lg">
                    <h3 class="font-semibold text-gray-900">Latest scrape</h3>
                    <p class="mt-2 text-sm text-gray-600">{{ $latestScrapeRun?->status?->value ?? 'No scrape runs yet' }}</p>
                </section>
                <section class="bg-white p-6 shadow sm:rounded-lg">
                    <h3 class="font-semibold text-gray-900">Active rules</h3>
                    <p class="mt-2 text-3xl font-semibold text-gray-900">{{ $activeAlertRules->count() }}</p>
                </section>
                <section class="bg-white p-6 shadow sm:rounded-lg">
                    <h3 class="font-semibold text-gray-900">Recent alerts</h3>
                    <p class="mt-2 text-3xl font-semibold text-gray-900">{{ $recentAlertEvents->count() }}</p>
                </section>
            </div>

            <section class="bg-white p-6 shadow sm:rounded-lg">
                <div class="flex items-center justify-between">
                    <h3 class="font-semibold text-gray-900">Alert rules</h3>
                    <a class="text-sm text-blue-700" href="{{ route('alert-rules.index') }}">Manage</a>
                </div>
                <div class="mt-4 space-y-2">
                    @forelse ($activeAlertRules as $rule)
                        <p class="text-sm text-gray-700">{{ $rule->name }} · {{ $rule->species->name }}</p>
                    @empty
                        <p class="text-sm text-gray-500">No active alert rules.</p>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
