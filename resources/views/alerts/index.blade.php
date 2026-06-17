<x-app-layout><x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">Alert history</h2></x-slot>
<div class="py-8"><div class="max-w-7xl mx-auto sm:px-6 lg:px-8 bg-white p-6 shadow sm:rounded-lg">
@forelse ($events as $event)
    <p class="py-2 text-sm text-gray-700">{{ $event->event_date->toDateString() }} · {{ $event->event_type->value }} · {{ $event->score }}</p>
@empty
    <p class="text-sm text-gray-500">No alerts yet.</p>
@endforelse
<div class="mt-4">{{ $events->links() }}</div>
</div></div></x-app-layout>
