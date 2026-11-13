<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold text-gray-800">API tokens</h2>
            <a href="{{ route('admin.api-tokens.create') }}" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white">
                Create token
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
            @if (session('status'))
                <p role="status" class="rounded-md bg-white p-4 text-sm text-green-700 shadow-sm">{{ session('status') }}</p>
            @endif

            <div class="overflow-x-auto bg-white shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-6 py-3">Name</th>
                            <th class="px-6 py-3">Token</th>
                            <th class="px-6 py-3">Abilities</th>
                            <th class="px-6 py-3">Last used</th>
                            <th class="px-6 py-3">Expires</th>
                            <th class="px-6 py-3">Status</th>
                            <th class="px-6 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($tokens as $token)
                            <tr>
                                <td class="px-6 py-4">
                                    <div class="font-medium text-gray-900">{{ $token->name }}</div>
                                    <div class="text-gray-500">{{ $token->tokenable?->email }}</div>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4">@include('admin.api-tokens._masked', ['token' => $token])</td>
                                <td class="px-6 py-4">{{ implode(', ', $token->abilities ?? []) }}</td>
                                <td class="whitespace-nowrap px-6 py-4">{{ $token->last_used_at?->format('n/j/Y g:i A') ?? 'Never' }}</td>
                                <td class="whitespace-nowrap px-6 py-4">{{ $token->effectiveExpiresAt()?->format('n/j/Y g:i A') ?? 'Never' }}</td>
                                <td class="px-6 py-4">
                                    @if ($token->isExpired())
                                        <span class="text-red-700">Expired</span>
                                    @else
                                        Active
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <a href="{{ route('admin.api-tokens.edit', $token) }}" class="font-medium text-blue-700" aria-label="Manage token {{ $token->name }}">Manage</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-4 text-sm text-gray-500">No API tokens yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $tokens->links() }}
        </div>
    </div>
</x-app-layout>
