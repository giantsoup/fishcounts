<x-app-layout><x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">Notification logs</h2></x-slot>
<div class="py-8"><div class="max-w-7xl mx-auto sm:px-6 lg:px-8 bg-white p-6 shadow sm:rounded-lg">
@forelse ($deliveries as $delivery)<p class="py-2 text-sm">{{ $delivery->created_at->format('n/j/Y g:i A') }} · {{ $delivery->user->email }} · {{ $delivery->channel->value }} · {{ $delivery->status->value }}</p>@empty<p class="text-sm text-gray-500">No delivery attempts.</p>@endforelse
{{ $deliveries->links() }}
</div></div></x-app-layout>
