<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">Sources</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 bg-white p-6 shadow sm:rounded-lg">
            @if (session('status'))
                <p class="mb-4 text-sm text-green-700">{{ session('status') }}</p>
            @endif

            <div class="space-y-6">
                @foreach ($sources as $source)
                    <form method="POST" action="{{ route('admin.sources.update', $source) }}" class="border-b pb-6">
                        @csrf
                        @method('PUT')

                        <div class="grid gap-4 lg:grid-cols-6">
                            <div class="lg:col-span-2">
                                <p class="font-medium text-gray-900">{{ $source->name }}</p>
                                <p class="break-all text-xs text-gray-500">{{ $source->base_url }}</p>
                                <p class="mt-2 text-xs text-gray-500">Last success: {{ $source->last_success_at?->diffForHumans() ?? 'Never' }}</p>
                                <p class="text-xs text-gray-500">Last failure: {{ $source->last_failure_at?->diffForHumans() ?? 'Never' }}</p>
                            </div>

                            <div>
                                <x-input-label for="priority-{{ $source->id }}" value="Priority" />
                                <x-form.number id="priority-{{ $source->id }}" name="priority" min="1" max="1000" :value="$source->priority" />
                            </div>

                            <div>
                                <x-input-label for="rate-{{ $source->id }}" value="Rate limit seconds" />
                                <x-form.number id="rate-{{ $source->id }}" name="rate_limit_seconds" min="1" max="3600" :value="$source->rate_limit_seconds" />
                            </div>

                            <div class="space-y-2 text-sm">
                                <x-form.checkbox name="is_enabled" value="1" :checked="$source->is_enabled" class="flex">Enabled</x-form.checkbox>
                                <x-form.checkbox name="supports_historical_dates" value="1" :checked="$source->supports_historical_dates" class="flex">Historical dates</x-form.checkbox>
                                <x-form.checkbox name="supports_landing_filter" value="1" :checked="$source->supports_landing_filter" class="flex">Landing filter</x-form.checkbox>
                            </div>

                            <div class="lg:col-span-6">
                                <x-input-label for="notes-{{ $source->id }}" value="Notes" />
                                <textarea id="notes-{{ $source->id }}" name="notes" rows="2" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">{{ old('notes', $source->notes) }}</textarea>
                            </div>

                            <div class="lg:col-span-6">
                                <x-primary-button>Save</x-primary-button>
                            </div>
                        </div>
                    </form>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>
