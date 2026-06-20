<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold text-gray-800">Edit user</h2>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-3xl space-y-6 sm:px-6 lg:px-8">
            @if (session('status'))
                <p class="rounded-md bg-white p-4 text-sm text-green-700 shadow">{{ session('status') }}</p>
            @endif

            <div class="bg-white p-6 shadow sm:rounded-lg">
                <form method="POST" action="{{ route('admin.users.update', $user) }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <div>
                        <x-input-label for="name" value="Name" />
                        <x-text-input id="name" name="name" class="mt-1 block w-full" :value="old('name', $user->name)" required />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="email" value="Email" />
                        <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" required />
                        <x-input-error :messages="$errors->get('email')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="role" value="Role" />
                        <x-form.select id="role" name="role">
                            <option value="user" @selected(old('role', $user->role->value) === 'user')>user</option>
                            <option value="admin" @selected(old('role', $user->role->value) === 'admin')>admin</option>
                        </x-form.select>
                        <x-input-error :messages="$errors->get('role')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="timezone" value="Timezone" />
                        <x-text-input id="timezone" name="timezone" class="mt-1 block w-full" :value="old('timezone', $user->timezone)" required />
                        <x-input-error :messages="$errors->get('timezone')" class="mt-2" />
                    </div>

                    <x-form.checkbox name="is_active" value="1" :checked="old('is_active', $user->is_active)">Active</x-form.checkbox>
                    <x-input-error :messages="$errors->get('is_active')" class="mt-2" />

                    <div class="flex items-center gap-3">
                        <x-primary-button>Save</x-primary-button>
                        <a href="{{ route('admin.users.index') }}" class="text-sm text-gray-600">Back to users</a>
                    </div>
                </form>
            </div>

            @canany(['sendPasswordResetLink', 'delete'], $user)
                <section class="space-y-6 bg-white p-6 shadow sm:rounded-lg">
                    <header>
                        <h3 class="text-lg font-medium text-gray-900">Account access</h3>
                        <p class="mt-1 text-sm text-gray-600">Manage password setup and permanent account removal.</p>
                    </header>

                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                        @can('sendPasswordResetLink', $user)
                            <form method="POST" action="{{ route('admin.users.password-reset', $user) }}">
                                @csrf

                                <x-secondary-button type="submit">Send password setup email</x-secondary-button>
                            </form>
                        @endcan

                        @can('delete', $user)
                            <x-danger-button
                                type="button"
                                x-data=""
                                x-on:click.prevent="$dispatch('open-modal', 'confirm-managed-user-deletion')"
                            >Delete user</x-danger-button>
                        @endcan
                    </div>
                </section>
            @endcanany
        </div>
    </div>

    @can('delete', $user)
        <x-modal name="confirm-managed-user-deletion" focusable>
            <form method="POST" action="{{ route('admin.users.destroy', $user) }}" class="p-6">
                @csrf
                @method('DELETE')

                <h2 class="text-lg font-medium text-gray-900">
                    Delete {{ $user->name }}?
                </h2>

                <p class="mt-1 text-sm text-gray-600">
                    This permanently deletes the account and all user-owned alert rules, destinations, alerts, and delivery history.
                </p>

                <div class="mt-6 flex justify-end">
                    <x-secondary-button x-on:click="$dispatch('close')">
                        Cancel
                    </x-secondary-button>

                    <x-danger-button class="ms-3">
                        Delete user
                    </x-danger-button>
                </div>
            </form>
        </x-modal>
    @endcan
</x-app-layout>
