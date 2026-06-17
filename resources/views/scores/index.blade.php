<x-app-layout><x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">Scores</h2></x-slot>
<div class="py-8"><div class="max-w-7xl mx-auto sm:px-6 lg:px-8 bg-white p-6 shadow sm:rounded-lg">
@forelse ($scores as $score)
    <p class="py-2 text-sm text-gray-700">{{ $score->score_date->toDateString() }} · {{ $score->alertRule->name }} · {{ $score->score }} · {{ $score->level->value }}</p>
@empty
    <p class="text-sm text-gray-500">No scores yet.</p>
@endforelse
<div class="mt-4">{{ $scores->links() }}</div>
</div></div></x-app-layout>
