<x-app-layout><x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">Users</h2></x-slot>
<div class="py-8"><div class="max-w-7xl mx-auto sm:px-6 lg:px-8 bg-white p-6 shadow sm:rounded-lg">
@foreach ($users as $user)<p class="py-2 text-sm"><a class="text-blue-700" href="{{ route('admin.users.edit', $user) }}">{{ $user->name }}</a> · {{ $user->email }} · {{ $user->role->value }}</p>@endforeach
{{ $users->links() }}
</div></div></x-app-layout>
