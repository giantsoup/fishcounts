<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold text-gray-800">Edit API token</h2>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-3xl space-y-6 sm:px-6 lg:px-8">
            @if (session('status'))
                <p role="status" class="rounded-md bg-white p-4 text-sm text-green-700 shadow-sm">{{ session('status') }}</p>
            @endif

            <p class="text-sm text-gray-600">You may be asked to confirm your password again; submit the form again afterwards.</p>

            <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                <form method="POST" action="{{ route('admin.api-tokens.update', $token) }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <div>
                        <x-input-label for="name" value="Name" />
                        <x-text-input id="name" name="name" class="mt-1 block w-full" :value="old('name', $token->name)" required maxlength="255" />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
                        <dt class="font-medium text-gray-900">Token</dt>
                        <dd class="sm:col-span-2">@include('admin.api-tokens._masked', ['token' => $token])</dd>

                        <dt class="font-medium text-gray-900">Acts as</dt>
                        <dd class="sm:col-span-2">{{ $token->tokenable?->name }} ({{ $token->tokenable?->email }})</dd>

                        <dt class="font-medium text-gray-900">Abilities</dt>
                        <dd class="sm:col-span-2">{{ implode(', ', $token->abilities ?? []) }}</dd>

                        <dt class="font-medium text-gray-900">Expires</dt>
                        <dd class="sm:col-span-2">{{ $token->effectiveExpiresAt()?->format('n/j/Y g:i A') ?? 'Never' }}</dd>
                    </dl>
                    <p class="text-sm text-gray-600">Abilities and expiry can't be changed. Rotate the token to change them.</p>

                    <div class="flex items-center gap-3">
                        <x-primary-button>Save</x-primary-button>
                        <a href="{{ route('admin.api-tokens.index') }}" class="text-sm text-gray-600">Back to API tokens</a>
                    </div>
                </form>
            </div>

            <section class="space-y-6 bg-white p-6 shadow-sm sm:rounded-lg">
                <header>
                    <h3 class="text-lg font-medium text-gray-900">Rotate token</h3>
                    <p class="mt-1 text-sm text-gray-600">Issue a new secret with the same name. The current token stops working immediately, and the new one is shown once.</p>
                </header>

                <form
                    method="POST"
                    action="{{ route('admin.api-tokens.rotate', $token) }}"
                    class="space-y-5"
                    data-confirm="Rotate this token? The current token stops working immediately."
                >
                    @csrf

                    @include('admin.api-tokens._ability-fields', ['selectedAbilities' => $token->abilities ?? []])

                    <x-secondary-button type="submit">Rotate token</x-secondary-button>
                </form>
            </section>

            <section class="space-y-6 bg-white p-6 shadow-sm sm:rounded-lg">
                <header>
                    <h3 class="text-lg font-medium text-gray-900">Revoke token</h3>
                    <p class="mt-1 text-sm text-gray-600">Revoking deletes the token. Anything using it is rejected from the next request.</p>
                </header>

                <x-danger-button
                    type="button"
                    x-data=""
                    x-on:click.prevent="$dispatch('open-modal', 'confirm-api-token-revocation')"
                >Revoke token</x-danger-button>
            </section>
        </div>
    </div>

    <x-modal name="confirm-api-token-revocation" focusable>
        <form method="POST" action="{{ route('admin.api-tokens.destroy', $token) }}" class="p-6">
            @csrf
            @method('DELETE')

            <h2 class="text-lg font-medium text-gray-900">
                Revoke {{ $token->name }}?
            </h2>

            <p class="mt-1 text-sm text-gray-600">
                This permanently deletes the token. Anything using it stops working immediately.
            </p>

            <div class="mt-6 flex justify-end">
                <x-secondary-button x-on:click="$dispatch('close')">
                    Cancel
                </x-secondary-button>

                <x-danger-button class="ms-3">
                    Revoke token
                </x-danger-button>
            </div>
        </form>
    </x-modal>
</x-app-layout>
