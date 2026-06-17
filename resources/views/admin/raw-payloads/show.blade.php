<x-app-layout><x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">Raw payload</h2></x-slot>
<div class="py-8"><div class="max-w-7xl mx-auto sm:px-6 lg:px-8 bg-white p-6 shadow sm:rounded-lg">
<p class="text-sm text-gray-700">{{ $payload->scrapeSource->name }} · {{ $payload->target_date->toDateString() }} · {{ $payload->http_status }}</p>
<pre class="mt-4 overflow-auto whitespace-pre-wrap text-xs bg-gray-50 p-4 rounded">{{ $payload->payload }}</pre>
</div></div></x-app-layout>
