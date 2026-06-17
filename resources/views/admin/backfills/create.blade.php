<x-app-layout><x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">New backfill</h2></x-slot>
<div class="py-8"><div class="max-w-3xl mx-auto sm:px-6 lg:px-8 bg-white p-6 shadow sm:rounded-lg">
<form method="POST" action="{{ route('admin.backfills.store') }}" class="space-y-4">@csrf
<div class="grid gap-4 md:grid-cols-2"><x-text-input name="from_date" type="date" class="block w-full" value="2026-01-01" /><x-text-input name="to_date" type="date" class="block w-full" value="{{ now()->toDateString() }}" /></div>
<select name="source_ids[]" multiple class="block w-full border-gray-300 rounded-md min-h-40">@foreach ($sources as $source)<option value="{{ $source->id }}" selected>{{ $source->name }}</option>@endforeach</select>
<x-text-input name="batch_size_days" type="number" min="1" max="31" class="block w-full" value="7" /><x-primary-button>Start backfill</x-primary-button>
</form></div></div></x-app-layout>
