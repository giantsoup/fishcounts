<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">Sources</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 bg-white p-6 shadow sm:rounded-lg">
            @if (session('status'))
                <p class="mb-4 text-sm text-green-700">{{ session('status') }}</p>
            @endif

            @if ($errors->any())
                <div class="mb-6 rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">
                    <p class="font-medium">The source could not be saved.</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="mb-6 rounded-md border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
                AI primary parsing is
                <span class="font-medium">{{ config('fish.ai_parsing.enabled') ? 'globally enabled' : 'globally disabled' }}</span>
                and provider credentials are
                <span class="font-medium">{{ filled(config('services.openai.api_key')) ? 'configured' : 'missing' }}</span>.
                When either guardrail is unavailable, AI sources safely use deterministic fallback.
            </div>

            <div class="space-y-6">
                @foreach ($sources as $source)
                    @php($isSubmittedSource = (int) old('submitted_source_id') === $source->id)
                    <form method="POST" action="{{ route('admin.sources.update', $source) }}" class="border-b pb-6">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="submitted_source_id" value="{{ $source->id }}">

                        <div class="grid gap-4 lg:grid-cols-6">
                            <div class="lg:col-span-2">
                                <p class="font-medium text-gray-900">{{ $source->name }}</p>
                                <p class="break-all text-xs text-gray-500">{{ $source->base_url }}</p>
                                <p class="mt-2 text-xs text-gray-500">Last success: {{ $source->last_success_at?->diffForHumans() ?? 'Never' }}</p>
                                <p class="text-xs text-gray-500">Last failure: {{ $source->last_failure_at?->diffForHumans() ?? 'Never' }}</p>
                                @if ($execution = $source->latestParserExecution)
                                    <p class="mt-2 text-xs text-gray-600">
                                        Latest parse: {{ $execution->status }} · {{ $execution->selected_engine?->value ?? $execution->requested_engine->value }}
                                        @if ($execution->fallback_category) · fallback: {{ str($execution->fallback_category)->replace('_', ' ') }} @endif
                                        · {{ $execution->cost_is_estimated ? '~' : '' }}${{ number_format($execution->cost_micros / 1_000_000, 4) }}
                                    </p>
                                    <p class="text-xs text-gray-500">
                                        Comparison: {{ $execution->comparison_status ?? 'not available' }}
                                        @if (is_array($execution->comparison))
                                            · {{ data_get($execution->comparison, 'summary.missing_reports', 0) }} missing,
                                            {{ data_get($execution->comparison, 'summary.extra_reports', 0) }} extra,
                                            {{ data_get($execution->comparison, 'summary.different_reports', 0) }} different
                                        @endif
                                    </p>
                                    <p class="text-xs text-gray-500">
                                        Provider: HTTP {{ $execution->provider_http_status ?? 'n/a' }}
                                        · {{ $execution->provider_status ?? 'no response' }}
                                        · {{ number_format($execution->total_tokens) }} tokens
                                        · {{ $execution->attempts }} attempt(s)
                                    </p>
                                    @if ($execution->provider_response_id || $execution->provider_request_id)
                                        <p class="break-all text-xs text-gray-500">
                                            Response: {{ $execution->provider_response_id ?? 'n/a' }}
                                            · Request: {{ $execution->provider_request_id ?? 'n/a' }}
                                        </p>
                                    @endif
                                    @if ($execution->fallback_category)
                                        <div class="mt-2 rounded border border-amber-200 bg-amber-50 p-2 text-xs text-amber-900">
                                            <p class="font-medium">
                                                Completed via deterministic fallback
                                                · {{ str($execution->fallback_stage ?? 'unknown')->replace('_', ' ') }}
                                                / {{ str($execution->fallback_category)->replace('_', ' ') }}
                                            </p>
                                            @if ($execution->fallback_message)
                                                <p class="mt-1 break-words">{{ $execution->fallback_message }}</p>
                                            @endif
                                            @if ($execution->provider_incomplete_reason)
                                                <p class="mt-1">Incomplete reason: {{ $execution->provider_incomplete_reason }}</p>
                                            @endif
                                            @if ($execution->provider_error_code || $execution->provider_error_type)
                                                <p class="mt-1">Provider error: {{ $execution->provider_error_type ?? 'unknown' }} / {{ $execution->provider_error_code ?? 'unknown' }}</p>
                                            @endif
                                        </div>
                                    @endif
                                    @if ($execution->aiAttempts->isNotEmpty())
                                        <details class="mt-2 text-xs text-gray-600">
                                            <summary class="cursor-pointer font-medium">Provider attempt audit</summary>
                                            <ul class="mt-1 space-y-2">
                                                @foreach ($execution->aiAttempts as $attempt)
                                                    <li class="rounded border border-gray-200 p-2">
                                                        Attempt {{ $attempt->attempt_number ?? $loop->iteration }}
                                                        · {{ $attempt->status->value }}
                                                        · {{ str($attempt->cost_basis->value)->replace('_', ' ') }}
                                                        · ${{ number_format(($attempt->actual_micros ?? 0) / 1_000_000, 4) }}
                                                        · {{ number_format($attempt->total_tokens) }} tokens
                                                        @if ($attempt->provider_http_status) · HTTP {{ $attempt->provider_http_status }} @endif
                                                        @if ($attempt->failure_category)
                                                            <p class="mt-1 text-amber-800">
                                                                {{ str($attempt->failure_stage ?? 'unknown')->replace('_', ' ') }}
                                                                / {{ str($attempt->failure_category)->replace('_', ' ') }}:
                                                                {{ $attempt->failure_message }}
                                                            </p>
                                                        @endif
                                                        <p class="mt-1 break-all text-gray-500">
                                                            Client: {{ $attempt->client_request_id ?? 'n/a' }}
                                                            · Request: {{ $attempt->provider_request_id ?? 'n/a' }}
                                                            · Response: {{ $attempt->provider_response_id ?? 'n/a' }}
                                                        </p>
                                                        @if ($attempt->provider_response_body_hash)
                                                            <p class="mt-1 break-all text-gray-500">
                                                                Response body SHA-256: {{ $attempt->provider_response_body_hash }}
                                                            </p>
                                                        @endif
                                                        @if ($attempt->provider_output_excerpt)
                                                            <details class="mt-1">
                                                                <summary class="cursor-pointer">Provider output excerpt</summary>
                                                                <pre class="mt-1 max-h-48 overflow-auto whitespace-pre-wrap break-words rounded bg-gray-50 p-2 text-[11px]">{{ $attempt->provider_output_excerpt }}</pre>
                                                            </details>
                                                        @endif
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </details>
                                    @endif
                                @endif
                            </div>

                            <div>
                                <x-input-label for="priority-{{ $source->id }}" value="Priority" />
                                <x-form.number id="priority-{{ $source->id }}" name="priority" min="1" max="1000" :value="$isSubmittedSource ? old('priority') : $source->priority" />
                            </div>

                            <div>
                                <x-input-label for="rate-{{ $source->id }}" value="Rate limit seconds" />
                                <x-form.number id="rate-{{ $source->id }}" name="rate_limit_seconds" min="1" max="3600" :value="$isSubmittedSource ? old('rate_limit_seconds') : $source->rate_limit_seconds" />
                            </div>

                            <div class="space-y-2 text-sm">
                                <x-form.checkbox name="is_enabled" value="1" :checked="$isSubmittedSource ? (bool) old('is_enabled') : $source->is_enabled" class="flex">Enabled</x-form.checkbox>
                                <x-form.checkbox name="supports_historical_dates" value="1" :checked="$isSubmittedSource ? (bool) old('supports_historical_dates') : $source->supports_historical_dates" class="flex">Historical dates</x-form.checkbox>
                                <x-form.checkbox name="supports_landing_filter" value="1" :checked="$isSubmittedSource ? (bool) old('supports_landing_filter') : $source->supports_landing_filter" class="flex">Landing filter</x-form.checkbox>
                            </div>

                            <div>
                                <x-input-label for="parser-engine-{{ $source->id }}" value="Parsing engine" />
                                <select id="parser-engine-{{ $source->id }}" name="parser_engine" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                                    @foreach (\App\Enums\ParserEngine::cases() as $engine)
                                        <option value="{{ $engine->value }}" @selected(($isSubmittedSource ? old('parser_engine') : $source->parser_engine->value) === $engine->value)>
                                            {{ $engine === \App\Enums\ParserEngine::Ai ? 'AI primary' : 'Deterministic' }}
                                        </option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-xs text-gray-500">AI model: {{ config('fish.ai_parsing.model') }}</p>
                            </div>

                            <div class="lg:col-span-2">
                                <x-input-label for="parser-reason-{{ $source->id }}" value="Engine change reason" />
                                <input id="parser-reason-{{ $source->id }}" name="parser_engine_change_reason" type="text" maxlength="1000" value="{{ $isSubmittedSource ? old('parser_engine_change_reason') : '' }}" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                                <p class="mt-1 text-xs text-amber-700">Changing engines can invalidate parser-version-bound report overrides. In-flight jobs keep their snapshotted engine.</p>
                            </div>

                            <div class="lg:col-span-6">
                                <x-input-label for="notes-{{ $source->id }}" value="Notes" />
                                <textarea id="notes-{{ $source->id }}" name="notes" rows="2" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">{{ $isSubmittedSource ? old('notes') : $source->notes }}</textarea>
                            </div>

                            <div class="lg:col-span-6">
                                <x-primary-button>Save</x-primary-button>
                            </div>
                        </div>
                    </form>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>
