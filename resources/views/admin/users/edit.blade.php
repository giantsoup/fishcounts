<x-app-layout><x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">Edit user</h2></x-slot>
<div class="py-8"><div class="max-w-3xl mx-auto sm:px-6 lg:px-8 bg-white p-6 shadow sm:rounded-lg">
@if (session('status'))<p class="mb-4 text-sm text-green-700">{{ session('status') }}</p>@endif
<form method="POST" action="{{ route('admin.users.update', $user) }}" class="space-y-4">@csrf @method('PUT')
<x-text-input name="name" class="block w-full" :value="old('name', $user->name)" /><x-text-input name="email" class="block w-full" :value="old('email', $user->email)" />
<select name="role" class="block w-full border-gray-300 rounded-md"><option value="user" @selected($user->role->value === 'user')>user</option><option value="admin" @selected($user->role->value === 'admin')>admin</option></select>
<x-text-input name="timezone" class="block w-full" :value="old('timezone', $user->timezone)" /><label class="block text-sm"><input type="checkbox" name="is_active" value="1" @checked($user->is_active)> Active</label><x-primary-button>Save</x-primary-button>
</form></div></div></x-app-layout>
