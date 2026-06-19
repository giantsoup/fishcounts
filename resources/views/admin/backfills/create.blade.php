<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">New backfill</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 bg-white p-6 shadow sm:rounded-lg">
            <form method="POST" action="{{ route('admin.backfills.store') }}" class="space-y-4">
                @csrf

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <x-input-label for="from_date" value="From date" />
                        <x-form.date id="from_date" name="from_date" value="2026-01-01" />
                    </div>
                    <div>
                        <x-input-label for="to_date" value="To date" />
                        <x-form.date id="to_date" name="to_date" value="{{ now()->toDateString() }}" />
                    </div>
                </div>

                <div>
                    <x-input-label for="source_ids" value="Sources" />
                    <x-form.select id="source_ids" name="source_ids[]" multiple class="min-h-40" placeholder="Select sources">
                        @foreach ($sources as $source)
                            <option value="{{ $source->id }}" selected>{{ $source->name }}</option>
                        @endforeach
                    </x-form.select>
                </div>

                <div>
                    <x-input-label for="batch_size_days" value="Batch size days" />
                    <x-form.number id="batch_size_days" name="batch_size_days" min="1" max="31" value="7" />
                </div>

                <x-primary-button>Start backfill</x-primary-button>
            </form>
        </div>
    </div>
</x-app-layout>
