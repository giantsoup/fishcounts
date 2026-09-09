<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">Reparse open errors</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto space-y-6 sm:px-6 lg:px-8">
            <section class="bg-white p-6 shadow sm:rounded-lg">
                <a class="text-sm text-blue-700" href="{{ route('admin.parser-errors.index') }}">Back to parser errors</a>
                <h3 class="mt-4 text-lg font-semibold text-gray-900">Choose the repair scope</h3>
                <p class="mt-2 text-sm text-gray-600">Preview the saved payloads and stored trips affected before starting a batch.</p>
                @if ($errors->any())
                    @if ($plan['skipped_errors'] > 0)
                        <p class="mt-2 text-sm text-amber-800">{{ $plan['skipped_errors'] }} additional errors have no saved payload and will remain untouched.</p>
                    @endif
                    <p class="mt-2 text-sm text-gray-700">{{ Number::format($plan['related_trips']) }} {{ Str::plural('trip', $plan['related_trips']) }} from other sources on these dates will participate in deduplication. Matching fallback trips may be removed when direct landing reports replace them.</p>
                    <ul class="mt-4 list-disc ps-5 text-sm text-red-700" role="alert">
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                @endif
                <form method="GET" action="{{ route('admin.parser-errors.reparse-runs.preview') }}" class="mt-5 grid gap-4 sm:grid-cols-3">
                    <input type="hidden" name="preview" value="1">
                    <div>
                        <x-input-label for="reparse-source" value="Source" />
                        <x-form.select id="reparse-source" name="source_id" :enhance="false">
                            <option value="">All sources</option>
                            @foreach ($sources as $source)
                                <option value="{{ $source->id }}" @selected((string) old('source_id', $filters['source_id'] ?? '') === (string) $source->id)>{{ $source->name }}</option>
                            @endforeach
                        </x-form.select>
                    </div>
                    <div>
                        <x-input-label for="reparse-from" value="From date" />
                        <x-text-input id="reparse-from" name="from" type="date" class="mt-1 block w-full" :value="old('from', $filters['from'] ?? '')" />
                    </div>
                    <div>
                        <x-input-label for="reparse-to" value="Through date" />
                        <x-text-input id="reparse-to" name="to" type="date" class="mt-1 block w-full" :value="old('to', $filters['to'] ?? '')" />
                    </div>
                    <div class="sm:col-span-3">
                        <x-primary-button type="submit">Preview batch</x-primary-button>
                    </div>
                </form>
            </section>

            @if ($plan !== null)
                <section class="bg-white p-6 shadow sm:rounded-lg" aria-labelledby="reparse-preview-heading">
                    <h3 id="reparse-preview-heading" class="text-lg font-semibold text-gray-900">Batch preview</h3>
                    <p class="mt-2 text-sm text-gray-700">
                        {{ Number::format($plan['open_errors']) }} open errors across {{ Number::format($plan['payloads']) }} affected saved {{ Str::plural('payload', $plan['payloads']) }} and {{ Number::format($plan['dates']) }} {{ Str::plural('date', $plan['dates']) }}.
                        {{ Number::format($plan['trips']) }} stored trips will be reevaluated.
                    </p>
                    @if ($plan['skipped_errors'] > 0)
                        <p class="mt-2 text-sm text-amber-800">{{ $plan['skipped_errors'] }} additional errors have no saved payload and will remain untouched.</p>
                    @endif
                    <p class="mt-2 text-sm text-gray-700">{{ Number::format($plan['related_trips']) }} {{ Str::plural('trip', $plan['related_trips']) }} from other sources on these dates will participate in deduplication. Matching fallback trips may be removed when direct landing reports replace them.</p>
                    <ul class="mt-4 list-disc space-y-2 ps-5 text-sm text-gray-700">
                        <li>No sources will be scraped.</li>
                        <li>This batch uses the deterministic parser and makes no AI requests.</li>
                        <li>Canonical aliases will not be created or dismissed. Legitimate alias errors will remain open.</li>
                        <li>Parser-version changes may invalidate stale report overrides before the payload is evaluated.</li>
                        <li>Previously resolved and dismissed errors are preserved.</li>
                    </ul>
                    @if ($plan['items']->isEmpty())
                        <p class="mt-4 text-sm text-gray-600">No saved payloads match this selection.</p>
                    @else
                        <div class="mt-5 max-h-96 overflow-auto">
                            <table class="min-w-full text-left text-sm">
                                <thead><tr class="border-b"><th class="p-2">Source</th><th class="p-2">Date</th><th class="p-2">Saved payload</th><th class="p-2">Action</th></tr></thead>
                                <tbody>
                                    @foreach ($plan['items'] as $item)
                                        <tr class="border-b">
                                            <td class="p-2">{{ $sources->firstWhere('id', $item['scrape_source_id'])?->name }}</td>
                                            <td class="p-2">{{ $item['target_date'] }}</td>
                                            <td class="p-2"><a class="text-blue-700" href="{{ route('admin.raw-payloads.show', $item['raw_scrape_payload_id']) }}">#{{ $item['raw_scrape_payload_id'] }}</a></td>
                                            <td class="p-2">{{ $item['mode'] === \App\Enums\ParserReparseItemMode::Authoritative ? 'Replace trips and refresh errors' : 'Refresh errors only' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <form method="POST" action="{{ route('admin.parser-errors.reparse-runs.store') }}" class="mt-5" x-data="{ submitting: false }" x-on:submit="submitting ? $event.preventDefault() : submitting = true">
                            @csrf
                            @foreach (['source_id', 'from', 'to'] as $field)
                                <input type="hidden" name="{{ $field }}" value="{{ $filters[$field] ?? '' }}">
                            @endforeach
                            <input type="hidden" name="fingerprint" value="{{ $plan['fingerprint'] }}">
                            <x-primary-button type="submit" x-bind:disabled="submitting">Queue batch</x-primary-button>
                        </form>
                        <p class="mt-3 text-xs text-gray-500">If affected data changes before submission, a new preview is required. A newer payload arriving during the run replaces the prior authoritative payload for that same source and date.</p>
                    @endif
                </section>
            @endif
        </div>
    </div>
</x-app-layout>
