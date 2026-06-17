<x-app-layout><x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">Sources</h2></x-slot>
<div class="py-8"><div class="max-w-7xl mx-auto sm:px-6 lg:px-8 bg-white p-6 shadow sm:rounded-lg">
@if (session('status'))<p class="mb-4 text-sm text-green-700">{{ session('status') }}</p>@endif
@foreach ($sources as $source)
<form method="POST" action="{{ route('admin.sources.update', $source) }}" class="py-4 border-b grid gap-3 md:grid-cols-5">@csrf @method('PUT')
<div class="md:col-span-2"><p class="font-medium">{{ $source->name }}</p><p class="text-xs text-gray-500">{{ $source->base_url }}</p></div>
<x-text-input name="priority" type="number" :value="$source->priority" /><x-text-input name="rate_limit_seconds" type="number" :value="$source->rate_limit_seconds" />
<div><label class="text-sm"><input type="checkbox" name="is_enabled" value="1" @checked($source->is_enabled)> Enabled</label><x-primary-button class="ms-3">Save</x-primary-button></div>
</form>
@endforeach
</div></div></x-app-layout>
