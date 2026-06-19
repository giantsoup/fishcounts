<x-app-layout><x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">Scrape runs</h2></x-slot>
<div class="py-8"><div class="max-w-7xl mx-auto sm:px-6 lg:px-8 bg-white p-6 shadow sm:rounded-lg">
@forelse ($runs as $run)<p class="py-2 text-sm"><a class="text-blue-700" href="{{ route('admin.scrape-runs.show', $run) }}">#{{ $run->id }}</a> · {{ $run->target_date->format('n/j/Y') }} · {{ $run->run_type->value }} · {{ $run->status->value }}</p>@empty<p class="text-sm text-gray-500">No scrape runs.</p>@endforelse
{{ $runs->links() }}
</div></div></x-app-layout>
