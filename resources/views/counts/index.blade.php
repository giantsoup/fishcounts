<x-app-layout><x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">Counts</h2></x-slot>
<div class="py-8"><div class="max-w-7xl mx-auto sm:px-6 lg:px-8 bg-white p-6 shadow sm:rounded-lg">
@forelse ($counts as $count)
    <p class="py-2 text-sm text-gray-700">{{ $count->tripReport->trip_date?->toDateString() }} · {{ $count->species->name }} · {{ $count->count }}</p>
@empty
    <p class="text-sm text-gray-500">No counts yet.</p>
@endforelse
<div class="mt-4">{{ $counts->links() }}</div>
</div></div></x-app-layout>
