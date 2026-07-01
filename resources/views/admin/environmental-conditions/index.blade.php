<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800">Environmental conditions</h2>
            <a href="{{ route('admin.conditions.index') }}" class="text-sm text-gray-600 underline">Reset filters</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[1480px] mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white p-6 shadow sm:rounded-lg">
                <form method="GET" action="{{ route('admin.conditions.index') }}" class="grid gap-4 md:grid-cols-2 lg:grid-cols-6">
                    <div>
                        <label for="from" class="block text-sm font-medium text-gray-700">From</label>
                        <x-form.date id="from" name="from" value="{{ $filters['from'] }}" />
                        <x-input-error :messages="$errors->get('from')" class="mt-2" />
                    </div>

                    <div>
                        <label for="to" class="block text-sm font-medium text-gray-700">To</label>
                        <x-form.date id="to" name="to" value="{{ $filters['to'] }}" />
                        <x-input-error :messages="$errors->get('to')" class="mt-2" />
                    </div>

                    <div class="lg:col-span-2">
                        <label for="location_profile" class="block text-sm font-medium text-gray-700">Profile</label>
                        <x-form.select id="location_profile" name="location_profile">
                            @foreach ($locationProfiles as $locationProfile)
                                <option value="{{ $locationProfile }}" @selected($filters['location_profile'] === $locationProfile)>{{ str($locationProfile)->replace('_', ' ')->headline() }}</option>
                            @endforeach
                        </x-form.select>
                    </div>

                    <div class="lg:col-span-2">
                        <label for="source_id" class="block text-sm font-medium text-gray-700">Source</label>
                        <x-form.select id="source_id" name="source_id">
                            <option value="">All sources</option>
                            @foreach ($sources as $source)
                                <option value="{{ $source->id }}" @selected($filters['source_id'] === $source->id)>{{ $source->name }}</option>
                            @endforeach
                        </x-form.select>
                        <x-input-error :messages="$errors->get('source_id')" class="mt-2" />
                    </div>

                    <div class="lg:col-span-2">
                        <label for="metric" class="block text-sm font-medium text-gray-700">Metric</label>
                        <x-form.select id="metric" name="metric">
                            <option value="">All metrics</option>
                            @foreach ($metrics as $metric)
                                <option value="{{ $metric }}" @selected($filters['metric'] === $metric)>{{ str($metric)->replace('_', ' ')->headline() }}</option>
                            @endforeach
                        </x-form.select>
                        <x-input-error :messages="$errors->get('metric')" class="mt-2" />
                    </div>

                    <div class="lg:col-span-2">
                        <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                        <x-form.select id="status" name="status">
                            <option value="">Any status</option>
                            <option value="partial" @selected($filters['status'] === 'partial')>Partial</option>
                            <option value="finalized" @selected($filters['status'] === 'finalized')>Finalized</option>
                        </x-form.select>
                    </div>

                    <div class="md:col-span-2 lg:col-span-2 flex items-end">
                        <x-primary-button>Apply filters</x-primary-button>
                    </div>
                </form>
            </div>

            <section class="bg-white shadow sm:rounded-lg overflow-hidden">
                <div class="border-b border-gray-200 px-4 py-4">
                    <h3 class="font-semibold text-gray-900">Daily summaries</h3>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Date</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Status</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Moon</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-700">Water</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-700">Swell</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Tide</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-700">Raw</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Summary</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @forelse ($summaries as $summary)
                                <tr>
                                    <td class="px-4 py-3 whitespace-nowrap text-gray-700">{{ $summary->observed_date->format('n/j/Y') }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ $summary->is_partial ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-800' }}">
                                            {{ $summary->is_partial ? 'Partial' : 'Finalized' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-gray-700">
                                        {{ $summary->moon_phase ?? 'n/a' }}
                                        @if ($summary->moon_illumination_percent !== null)
                                            · {{ round((float) $summary->moon_illumination_percent) }}%
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-right text-gray-700">{{ $summary->water_temp_f_avg !== null ? number_format((float) $summary->water_temp_f_avg, 1).' F' : '—' }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-right text-gray-700">
                                        @if ($summary->swell_height_ft_avg !== null)
                                            {{ number_format((float) $summary->swell_height_ft_avg, 1) }} ft
                                            @if ($summary->swell_period_seconds_avg !== null)
                                                at {{ number_format((float) $summary->swell_period_seconds_avg, 0) }}s
                                            @endif
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-gray-700">
                                        H {{ $summary->high_tide_at?->format('g:i A') ?? '—' }}
                                        @if ($summary->high_tide_height_ft !== null)
                                            {{ number_format((float) $summary->high_tide_height_ft, 1) }} ft
                                        @endif
                                        <span class="mx-1 text-gray-300">/</span>
                                        L {{ $summary->low_tide_at?->format('g:i A') ?? '—' }}
                                        @if ($summary->low_tide_height_ft !== null)
                                            {{ number_format((float) $summary->low_tide_height_ft, 1) }} ft
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-right text-gray-700">{{ $summary->observations_count }} obs · {{ $summary->payloads_count }} payloads</td>
                                    <td class="px-4 py-3 min-w-[22rem] text-gray-700">{{ $summary->condition_summary ?? 'No summary available.' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-4 py-8 text-center text-sm text-gray-500">No daily summaries match the current filters.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-gray-200 px-4 py-3">
                    {{ $summaries->links() }}
                </div>
            </section>

            <section class="bg-white shadow sm:rounded-lg overflow-hidden">
                <div class="border-b border-gray-200 px-4 py-4">
                    <h3 class="font-semibold text-gray-900">Normalized observations</h3>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Observed</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Source</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Metric</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-700">Value</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Quality</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Payload</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @forelse ($observations as $observation)
                                <tr>
                                    <td class="px-4 py-3 whitespace-nowrap text-gray-700">{{ $observation->observed_at->format('n/j/Y g:i A') }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-gray-900">{{ $observation->environmentalSource->name }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap font-medium text-gray-900">{{ str($observation->metric)->replace('_', ' ')->headline() }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-right text-gray-700">
                                        {{ $observation->value !== null ? rtrim(rtrim(number_format((float) $observation->value, 3), '0'), '.') : ($observation->text_value ?? '—') }}
                                        @if ($observation->unit)
                                            {{ $observation->unit }}
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 min-w-[16rem] text-xs text-gray-600">
                                        @if ($observation->quality_flags)
                                            <pre class="whitespace-pre-wrap">{{ json_encode($observation->quality_flags, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-gray-700">#{{ $observation->environmental_payload_id ?? 'n/a' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500">No observations match the current filters.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-gray-200 px-4 py-3">
                    {{ $observations->links() }}
                </div>
            </section>

            <section class="space-y-3">
                <div class="flex items-center justify-between">
                    <h3 class="font-semibold text-gray-900">Raw payloads</h3>
                    <p class="text-sm text-gray-500">{{ $payloads->total() }} matching payloads</p>
                </div>

                @forelse ($payloads as $payload)
                    <details class="bg-white p-4 shadow sm:rounded-lg">
                        <summary class="cursor-pointer text-sm font-medium text-gray-900">
                            #{{ $payload->id }} · {{ $payload->environmentalSource->name }} · {{ $payload->observed_date->format('n/j/Y') }} · HTTP {{ $payload->http_status ?? 'n/a' }}
                        </summary>

                        <dl class="mt-4 grid gap-3 text-sm md:grid-cols-3">
                            <div class="md:col-span-2">
                                <dt class="font-medium text-gray-500">URL</dt>
                                <dd class="break-all text-gray-900">{{ $payload->url }}</dd>
                            </div>
                            <div>
                                <dt class="font-medium text-gray-500">Fetched</dt>
                                <dd class="text-gray-900">{{ $payload->fetched_at->format('n/j/Y g:i A') }}</dd>
                            </div>
                            <div class="md:col-span-3">
                                <dt class="font-medium text-gray-500">Payload hash</dt>
                                <dd class="break-all text-gray-900">{{ $payload->payload_hash }}</dd>
                            </div>
                        </dl>

                        <pre class="mt-4 max-h-[32rem] overflow-auto whitespace-pre-wrap rounded bg-gray-50 p-4 text-xs text-gray-800">{{ $payload->payload }}</pre>
                    </details>
                @empty
                    <div class="bg-white p-6 text-center text-sm text-gray-500 shadow sm:rounded-lg">No raw payloads match the current filters.</div>
                @endforelse

                <div class="bg-white px-4 py-3 shadow sm:rounded-lg">
                    {{ $payloads->links() }}
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
