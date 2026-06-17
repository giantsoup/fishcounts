<x-app-layout><x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">Scrape run #{{ $run->id }}</h2></x-slot>
<div class="py-8"><div class="max-w-7xl mx-auto sm:px-6 lg:px-8 bg-white p-6 shadow sm:rounded-lg">
<p class="text-sm text-gray-700">{{ $run->target_date->toDateString() }} · {{ $run->run_type->value }} · {{ $run->status->value }}</p>
<div class="mt-4">@foreach ($run->rawScrapePayloads as $payload)<p class="py-2 text-sm"><a class="text-blue-700" href="{{ route('admin.raw-payloads.show', $payload) }}">{{ $payload->url }}</a></p>@endforeach</div>
</div></div></x-app-layout>
