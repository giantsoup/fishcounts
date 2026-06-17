<x-app-layout><x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">Notification settings</h2></x-slot>
<div class="py-8"><div class="max-w-3xl mx-auto sm:px-6 lg:px-8 bg-white p-6 shadow sm:rounded-lg">
@if (session('status'))<p class="mb-4 text-sm text-green-700">{{ session('status') }}</p>@endif
<form method="POST" action="{{ route('notification-settings.update') }}" class="space-y-4">@csrf @method('PUT')
    <label class="block text-sm"><input type="checkbox" name="email_enabled" value="1" checked> Email notifications to {{ auth()->user()->email }}</label>
    <label class="block text-sm"><input type="checkbox" name="discord_enabled" value="1"> Discord notifications</label>
    <div><x-input-label for="discord_webhook_url" value="Discord webhook URL" /><x-text-input id="discord_webhook_url" name="discord_webhook_url" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('discord_webhook_url')" class="mt-2" /></div>
    <x-primary-button>Save</x-primary-button>
</form>
<div class="mt-6 space-y-2">@foreach ($destinations as $destination)<p class="text-sm text-gray-600">{{ $destination->channel->value }} · {{ $destination->name }} · {{ $destination->is_enabled ? 'enabled' : 'disabled' }}</p>@endforeach</div>
</div></div></x-app-layout>
