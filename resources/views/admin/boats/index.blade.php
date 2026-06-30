<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">Boats</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 bg-white p-6 shadow sm:rounded-lg">
            @if (session('status'))
                <p class="mb-4 text-sm text-green-700">{{ session('status') }}</p>
            @endif

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700">Boat</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700">Landing</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700">Booking URL</th>
                            <th class="px-4 py-3 text-right font-semibold text-gray-700">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @forelse ($boats as $boat)
                            <tr>
                                <td class="px-4 py-3 whitespace-nowrap font-medium text-gray-900">{{ $boat->name }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-gray-700">{{ $boat->landing?->name ?? 'Unknown' }}</td>
                                <td class="px-4 py-3">
                                    <form id="boat-{{ $boat->id }}-form" method="POST" action="{{ route('admin.boats.update', $boat) }}">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="boat_id" value="{{ $boat->id }}">
                                        <x-text-input
                                            id="booking-url-{{ $boat->id }}"
                                            name="booking_url"
                                            type="url"
                                            class="w-full min-w-80"
                                            :value="(string) old('boat_id') === (string) $boat->id ? old('booking_url', $boat->booking_url) : $boat->booking_url"
                                            placeholder="https://..."
                                        />
                                        <x-input-error
                                            :messages="(string) old('boat_id') === (string) $boat->id ? $errors->get('booking_url') : []"
                                            class="mt-2"
                                        />
                                    </form>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <x-primary-button form="boat-{{ $boat->id }}-form">Save</x-primary-button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-sm text-gray-500">No active boats are available.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="border-t border-gray-200 px-4 py-3">
                {{ $boats->links() }}
            </div>
        </div>
    </div>
</x-app-layout>
