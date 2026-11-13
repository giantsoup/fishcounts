<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold text-gray-800">Create API token</h2>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-3xl bg-white p-6 shadow-sm sm:rounded-lg sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('admin.api-tokens.store') }}" class="space-y-5">
                @csrf

                <div>
                    <x-input-label for="name" value="Name" />
                    <x-text-input id="name" name="name" class="mt-1 block w-full" :value="old('name')" required maxlength="255" />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="owner_id" value="Acts as" />
                    <x-form.select id="owner_id" name="owner_id">
                        @foreach ($owners as $owner)
                            <option value="{{ $owner->id }}" @selected((int) old('owner_id', $defaultOwnerId) === $owner->id)>{{ $owner->name }} ({{ $owner->email }})</option>
                        @endforeach
                    </x-form.select>
                    <p class="mt-1 text-sm text-gray-600">The active admin account the token authenticates as. API writes are attributed to it.</p>
                    <x-input-error :messages="$errors->get('owner_id')" class="mt-2" />
                </div>

                @include('admin.api-tokens._ability-fields', ['selectedAbilities' => []])

                <p class="text-sm text-gray-600">You may be asked to confirm your password again; submit the form again afterwards.</p>

                <div class="flex items-center gap-3">
                    <x-primary-button>Create token</x-primary-button>
                    <a href="{{ route('admin.api-tokens.index') }}" class="text-sm text-gray-600">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
