<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold text-gray-800">Users</h2>
            <a href="{{ route('admin.users.create') }}" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white">
                Invite user
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
            @if (session('status'))
                <p class="rounded-md bg-white p-4 text-sm text-green-700 shadow">{{ session('status') }}</p>
            @endif

            <div class="overflow-hidden bg-white shadow sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-6 py-3">Name</th>
                            <th class="px-6 py-3">Role</th>
                            <th class="px-6 py-3">Status</th>
                            <th class="px-6 py-3">Last login</th>
                            <th class="px-6 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($users as $user)
                            <tr>
                                <td class="px-6 py-4">
                                    <div class="font-medium text-gray-900">{{ $user->name }}</div>
                                    <div class="text-gray-500">{{ $user->email }}</div>
                                </td>
                                <td class="px-6 py-4">{{ $user->role->value }}</td>
                                <td class="px-6 py-4">{{ $user->is_active ? 'Active' : 'Inactive' }}</td>
                                <td class="px-6 py-4">{{ $user->last_login_at?->toDayDateTimeString() ?? 'Never' }}</td>
                                <td class="px-6 py-4 text-right">
                                    <div class="flex items-center justify-end gap-3">
                                        <a href="{{ route('admin.users.edit', $user) }}" class="font-medium text-blue-700">Edit</a>

                                        @can('delete', $user)
                                            <form method="POST" action="{{ route('admin.users.destroy', $user) }}" onsubmit="return confirm('Delete this user account? This cannot be undone.');">
                                                @csrf
                                                @method('DELETE')

                                                <button type="submit" class="font-medium text-red-700">Delete</button>
                                            </form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $users->links() }}
        </div>
    </div>
</x-app-layout>
