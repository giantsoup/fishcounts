<x-app-layout><x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">Admin</h2></x-slot>
<div class="py-8"><div class="max-w-7xl mx-auto sm:px-6 lg:px-8 grid gap-6 md:grid-cols-4">
<div class="bg-white p-6 shadow sm:rounded-lg"><p class="text-sm text-gray-500">Users</p><p class="text-3xl font-semibold">{{ $userCount }}</p></div>
<div class="bg-white p-6 shadow sm:rounded-lg"><p class="text-sm text-gray-500">Latest scrape</p><p class="text-sm">{{ $latestScrapeRun?->status?->value ?? 'None' }}</p></div>
<div class="bg-white p-6 shadow sm:rounded-lg"><p class="text-sm text-gray-500">Latest backfill</p><p class="text-sm">{{ $latestBackfillRun?->status?->value ?? 'None' }}</p></div>
<div class="bg-white p-6 shadow sm:rounded-lg"><p class="text-sm text-gray-500">Parser errors</p><p class="text-3xl font-semibold">{{ $openParserErrorCount }}</p></div>
</div></div></x-app-layout>
