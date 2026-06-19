<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold text-gray-800">Invite user</h2>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-3xl bg-white p-6 shadow sm:rounded-lg sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('admin.users.store') }}" class="space-y-5">
                @csrf

                <div>
                    <x-input-label for="name" value="Name" />
                    <x-text-input id="name" name="name" class="mt-1 block w-full" :value="old('name')" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="email" value="Email" />
                    <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email')" required />
                    <x-input-error :messages="$errors->get('email')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="role" value="Role" />
                    <x-form.select id="role" name="role">
                        <option value="user" @selected(old('role', 'user') === 'user')>user</option>
                        <option value="admin" @selected(old('role') === 'admin')>admin</option>
                    </x-form.select>
                    <x-input-error :messages="$errors->get('role')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="timezone" value="Timezone" />
                    <x-text-input id="timezone" name="timezone" class="mt-1 block w-full" :value="old('timezone', 'America/Los_Angeles')" required />
                    <x-input-error :messages="$errors->get('timezone')" class="mt-2" />
                </div>

                <x-form.checkbox name="is_active" value="1" :checked="old('is_active', true)">Active</x-form.checkbox>

                <div class="flex items-center gap-3">
                    <x-primary-button>Create and send invite</x-primary-button>
                    <a href="{{ route('admin.users.index') }}" class="text-sm text-gray-600">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
