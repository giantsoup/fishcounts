<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Alert rules</h2>
            <a class="text-sm text-blue-700" href="{{ route('alert-rules.create') }}">New rule</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 bg-white p-6 shadow sm:rounded-lg">
            @if (session('status'))
                <p class="mb-4 text-sm text-green-700">{{ session('status') }}</p>
            @endif
            <div class="divide-y">
                @forelse ($rules as $rule)
                    <div class="py-4 flex items-center justify-between">
                        <div>
                            <p class="font-medium text-gray-900">{{ $rule->name }}</p>
                            <p class="text-sm text-gray-600">{{ $rule->species->name }} · Score {{ $rule->minimum_score }}+</p>
                        </div>
                        <a class="text-sm text-blue-700" href="{{ route('alert-rules.edit', $rule) }}">Edit</a>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No alert rules yet.</p>
                @endforelse
            </div>
            <div class="mt-4">{{ $rules->links() }}</div>
        </div>
    </div>
</x-app-layout>
