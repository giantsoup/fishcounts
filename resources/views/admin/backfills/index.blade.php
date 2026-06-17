<x-app-layout><x-slot name="header"><div class="flex justify-between"><h2 class="font-semibold text-xl text-gray-800">Backfills</h2><a class="text-sm text-blue-700" href="{{ route('admin.backfills.create') }}">New backfill</a></div></x-slot>
<div class="py-8"><div class="max-w-7xl mx-auto sm:px-6 lg:px-8 bg-white p-6 shadow sm:rounded-lg">
@if (session('status'))<p class="mb-4 text-sm text-green-700">{{ session('status') }}</p>@endif
@forelse ($backfills as $backfill)<div class="py-3 border-b flex justify-between text-sm"><span>#{{ $backfill->id }} · {{ $backfill->from_date->toDateString() }} to {{ $backfill->to_date->toDateString() }} · {{ $backfill->status->value }}</span><form method="POST" action="{{ route('admin.backfills.cancel', $backfill) }}">@csrf<x-secondary-button>Cancel</x-secondary-button></form></div>@empty<p class="text-sm text-gray-500">No backfills.</p>@endforelse
{{ $backfills->links() }}
</div></div></x-app-layout>
