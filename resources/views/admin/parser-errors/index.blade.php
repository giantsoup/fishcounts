<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">Parser errors</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 bg-white p-6 shadow sm:rounded-lg">
            @if (session('status'))
                <p class="mb-4 text-sm text-green-700">{{ session('status') }}</p>
            @endif

            @if (session('errors')?->has('review'))
                <p class="mb-4 text-sm text-red-700">{{ session('errors')->first('review') }}</p>
            @endif

            @if (session('errors')?->has('override'))
                <p class="mb-4 text-sm text-red-700">{{ session('errors')->first('override') }}</p>
            @endif

            <nav class="mb-4 flex gap-4 text-sm" aria-label="Parser error filters">
                <a href="{{ route('admin.parser-errors.index') }}" @class(['font-semibold text-blue-700' => ! $showAll, 'text-gray-600 hover:text-gray-900' => $showAll])>Open</a>
                <a href="{{ route('admin.parser-errors.index', ['status' => 'all']) }}" @class(['font-semibold text-blue-700' => $showAll, 'text-gray-600 hover:text-gray-900' => ! $showAll])>All</a>
            </nav>

            @forelse ($errors as $error)
                <div class="border-b py-5">
                    <div class="grid gap-3 lg:grid-cols-5">
                        <div class="lg:col-span-2">
                            <p class="font-medium text-gray-900">{{ $error->error_type }}</p>
                            <p class="text-sm text-gray-600">{{ $error->message }}</p>
                            <p class="mt-2 text-xs text-gray-500">
                                {{ $error->scrapeSource->name }} · {{ $error->target_date?->format('n/j/Y') ?? 'No date' }} · {{ $error->created_at->format('n/j/Y g:i A') }}
                            </p>
                            @if ($error->rawScrapePayload)
                                <p class="mt-2 text-xs">
                                    <a class="text-blue-700" href="{{ route('admin.raw-payloads.show', $error->rawScrapePayload) }}">View raw payload</a>
                                </p>
                            @endif
                            @if ($error->resolved_at)
                                <p class="mt-2 text-xs text-green-700">
                                    {{ $error->resolution_type === \App\Enums\ParserErrorResolutionType::Dismissed ? 'Dismissed' : 'Resolved' }} {{ $error->resolved_at->diffForHumans() }}
                                    @if ($error->resolver)
                                        by {{ $error->resolver->name }}
                                    @endif
                                </p>
                            @endif
                        </div>

                        <div>
                            <p class="text-xs font-medium uppercase text-gray-500">Raw field</p>
                            <p class="text-sm text-gray-900">{{ $error->raw_field ?? 'n/a' }}</p>
                        </div>

                        <div>
                            <p class="text-xs font-medium uppercase text-gray-500">Raw value</p>
                            <p class="text-sm text-gray-900">{{ $error->raw_value ?? 'n/a' }}</p>
                        </div>

                        <div class="space-y-4">
                            @if (! $error->resolved_at && $error->error_type === 'unknown_boat_alias' && $error->raw_value)
                                <form method="POST" action="{{ route('admin.boat-aliases.store') }}" class="space-y-2">
                                    @csrf
                                    <input type="hidden" name="alias" value="{{ $error->raw_value }}">
                                    <input type="hidden" name="parser_error_id" value="{{ $error->id }}">
                                    <x-form.select name="boat_id" class="text-sm">
                                        @foreach ($boats as $boat)
                                            <option value="{{ $boat->id }}">{{ $boat->name }}</option>
                                        @endforeach
                                    </x-form.select>
                                    <x-secondary-button type="submit">Resolve as boat</x-secondary-button>
                                </form>
                            @elseif (! $error->resolved_at && $error->error_type === 'unknown_species_alias' && $error->raw_value)
                                <form method="POST" action="{{ route('admin.species-aliases.store') }}" class="space-y-2">
                                    @csrf
                                    <input type="hidden" name="alias" value="{{ $error->raw_value }}">
                                    <input type="hidden" name="parser_error_id" value="{{ $error->id }}">
                                    <x-form.select name="species_id" class="text-sm">
                                        @foreach ($species as $item)
                                            <option value="{{ $item->id }}">{{ $item->name }}</option>
                                        @endforeach
                                    </x-form.select>
                                    <x-secondary-button type="submit">Resolve as species</x-secondary-button>
                                </form>
                            @elseif (! $error->resolved_at && $error->error_type === 'unknown_trip_type_alias' && $error->raw_value)
                                <form method="POST" action="{{ route('admin.trip-type-aliases.store') }}" class="space-y-2">
                                    @csrf
                                    <input type="hidden" name="alias" value="{{ $error->raw_value }}">
                                    <input type="hidden" name="parser_error_id" value="{{ $error->id }}">
                                    <x-form.select name="trip_type_id" class="text-sm">
                                        @foreach ($tripTypes as $tripType)
                                            <option value="{{ $tripType->id }}">{{ $tripType->name }}</option>
                                        @endforeach
                                    </x-form.select>
                                    <x-secondary-button type="submit">Resolve as trip type</x-secondary-button>
                                </form>
                            @elseif (! $error->resolved_at)
                                <p class="text-sm text-gray-500">No alias action.</p>
                            @endif

                            @if (! $error->resolved_at)
                                <form method="POST" action="{{ route('admin.parser-errors.dismiss', $error) }}">
                                    @csrf
                                    @method('PATCH')
                                    <x-secondary-button type="submit">Dismiss error</x-secondary-button>
                                    <p class="mt-1 text-xs text-gray-500">Marks this error resolved without creating an alias.</p>
                                </form>
                            @endif
                        </div>

                        @if ($reviewAuditEnabled)
                            @php
                                $review = $error->latestDiagnosticReview;
                                $reviewTarget = $review ? $reviewTargets->get($review->id) : null;
                                $parserBugReport = $review?->parserBugReport;
                                $reportOverride = $review?->reportOverride;
                                $reviewRun = $error->rawScrapePayload?->latestParserDiagnosticReviewRun;
                                $activeReviewRun = $reviewRun?->status->isActive() && ! $reviewRun->isStale() ? $reviewRun : null;
                            @endphp

                            <section class="rounded-lg border border-indigo-100 bg-indigo-50 p-4 lg:col-span-5" aria-label="AI diagnostic review">
                                <div class="grid gap-4 lg:grid-cols-3">
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">AI review</p>
                                        @if ($activeReviewRun)
                                            <p class="mt-1 text-sm font-medium text-gray-900">{{ str($activeReviewRun->status->value)->headline() }}</p>
                                            <p class="text-sm text-gray-700">
                                                @if ($activeReviewRun->status === \App\Enums\ParserDiagnosticReviewRunStatus::Preparing)
                                                    The payload reparse is queued to prepare this diagnostic for AI review.
                                                @elseif ($activeReviewRun->status === \App\Enums\ParserDiagnosticReviewRunStatus::Queued)
                                                    Waiting for an AI review worker. It is safe to leave or refresh this page.
                                                @else
                                                    The AI review is in progress.
                                                @endif
                                            </p>
                                            @if ($review?->status === \App\Enums\ParserDiagnosticReviewStatus::Failed)
                                                <p class="mt-2 text-sm text-amber-800">The last attempt failed, and an automatic retry is still scheduled.</p>
                                            @endif
                                            <p class="mt-2 text-xs text-gray-600">
                                                Requested {{ $activeReviewRun->created_at->diffForHumans() }}
                                                @if ($activeReviewRun->requestedBy)
                                                    by {{ $activeReviewRun->requestedBy->name }}
                                                @endif
                                            </p>
                                        @elseif ($review)
                                            <p class="mt-1 text-sm font-medium text-gray-900">
                                                {{ $review->status === \App\Enums\ParserDiagnosticReviewStatus::Pending ? 'Queued' : str($review->status->value)->headline() }}
                                            </p>
                                            @if ($review->status === \App\Enums\ParserDiagnosticReviewStatus::Pending)
                                                <p class="text-sm text-gray-700">Waiting for an AI review worker. It is safe to leave or refresh this page.</p>
                                            @elseif ($review->status === \App\Enums\ParserDiagnosticReviewStatus::Running)
                                                <p class="text-sm text-gray-700">The AI review is in progress.</p>
                                            @endif
                                            <p class="text-sm text-gray-700">
                                                {{ $review->classification ? str($review->classification->value)->headline() : 'No classification' }}
                                                @if ($review->confidence !== null)
                                                    · {{ Number::percentage((float) $review->confidence * 100, precision: 1) }} confidence
                                                @endif
                                            </p>
                                            @if ($reviewTarget)
                                                <p class="mt-2 text-sm text-gray-700">
                                                    Candidate: {{ $reviewTarget->name }}
                                                    @if (! $reviewTarget->is_active)
                                                        <span class="text-red-700">(inactive)</span>
                                                    @endif
                                                </p>
                                            @endif
                                            @if ($review->failure_message)
                                                <p class="mt-2 text-sm text-red-700">{{ $review->failure_message }}</p>
                                            @endif
                                        @else
                                            <p class="mt-1 text-sm text-gray-600">No AI review is available.</p>
                                            @if ($reviewRun?->isStale())
                                                <p class="mt-2 text-sm text-amber-800">The last AI review request appears stalled. It is safe to start a new review.</p>
                                            @elseif ($reviewRun?->status === \App\Enums\ParserDiagnosticReviewRunStatus::Failed)
                                                <p class="mt-2 text-sm text-red-700">The last AI review request failed: {{ $reviewRun->failure_message }}</p>
                                            @elseif ($reviewRun?->status === \App\Enums\ParserDiagnosticReviewRunStatus::Completed)
                                                <p class="mt-2 text-sm text-gray-700">The last request completed, but it did not produce a current AI review.</p>
                                            @endif
                                            @if ($manualAiReviewEnabled && ! $error->resolved_at && $error->resolution_type === null && $error->raw_scrape_payload_id !== null)
                                                <form
                                                    method="POST"
                                                    action="{{ route('admin.parser-errors.reviews.store', $error) }}"
                                                    class="mt-3"
                                                    x-data="{ submitting: false }"
                                                    x-on:submit="submitting ? $event.preventDefault() : submitting = true"
                                                    x-bind:aria-busy="submitting"
                                                >
                                                    @csrf
                                                    <x-secondary-button type="submit" x-bind:disabled="submitting">
                                                        <span x-text="submitting ? 'Starting AI review…' : 'Run AI review'">Run AI review</span>
                                                    </x-secondary-button>
                                                    <template x-if="submitting">
                                                        <p class="mt-2 text-xs font-medium text-indigo-700" aria-live="polite">Starting the review. Please keep this page open until the request is confirmed.</p>
                                                    </template>
                                                </form>
                                                @if (blank($error->diagnostic_fingerprint))
                                                    <p class="mt-2 text-xs text-gray-600">This legacy error will be reparsed first to prepare it for AI review.</p>
                                                @endif
                                            @endif
                                        @endif
                                    </div>

                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">Rationale and source</p>
                                        <p class="mt-1 whitespace-pre-wrap text-sm text-gray-800">{{ $review?->rationale ?? 'No rationale available.' }}</p>
                                        @if (filled(data_get($error->context, 'sanitized_paragraph')))
                                            <details class="mt-3 text-sm">
                                                <summary class="cursor-pointer font-medium text-gray-700">Sanitized paragraph</summary>
                                                <p class="mt-2 whitespace-pre-wrap text-gray-700">{{ data_get($error->context, 'sanitized_paragraph') }}</p>
                                            </details>
                                        @endif
                                    </div>

                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">Versions and usage</p>
                                        @if ($review)
                                            <dl class="mt-1 grid grid-cols-2 gap-x-3 text-xs text-gray-700">
                                                <dt>Model</dt><dd>{{ $review->model }}</dd>
                                                <dt>Prompt</dt><dd>{{ $review->prompt_version }}</dd>
                                                <dt>Schema</dt><dd>{{ $review->schema_version }}</dd>
                                                <dt>Parser</dt><dd>{{ data_get($error->context, 'parser_version', $error->rawScrapePayload?->parser_version ?? 'unknown') }}</dd>
                                                <dt>Input tokens</dt><dd>{{ $review->input_tokens ?? 'n/a' }}</dd>
                                                <dt>Cached tokens</dt><dd>{{ $review->cached_input_tokens }}</dd>
                                                <dt>Output tokens</dt><dd>{{ $review->output_tokens ?? 'n/a' }}</dd>
                                                <dt>Reasoning tokens</dt><dd>{{ $review->reasoning_tokens }}</dd>
                                                <dt>Total tokens</dt><dd>{{ $review->total_tokens ?? 'n/a' }}</dd>
                                                <dt>Estimated cost</dt><dd>{{ $review->estimated_cost_micros === null ? 'n/a' : Number::currency($review->estimated_cost_micros / 1_000_000) }}</dd>
                                            </dl>
                                        @else
                                            <p class="mt-1 text-sm text-gray-600">No usage data available.</p>
                                        @endif
                                    </div>
                                </div>

                                @if ($review && $review->humanActions->isNotEmpty())
                                    <div class="mt-4 border-t border-indigo-100 pt-3">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">Review audit history</p>
                                        <ul class="mt-1 space-y-1 text-xs text-gray-700">
                                            @foreach ($review->humanActions->sortByDesc('created_at') as $humanAction)
                                                <li>
                                                    {{ str($humanAction->action->value)->headline() }} by {{ $humanAction->actor_name }}
                                                    · {{ $humanAction->created_at->format('n/j/Y g:i A') }}

                                                    @if ($humanAction->action === \App\Enums\ParserDiagnosticReviewActionType::AutomaticallyAccepted
                                                        && ! $review->humanActions->contains(fn ($action) => $action->action === \App\Enums\ParserDiagnosticReviewActionType::AutomationReversed
                                                            && $action->review_attempt === $humanAction->review_attempt))
                                                        <form class="mt-1" method="POST" action="{{ route('admin.parser-errors.reviews.reverse-automation', [$error, $review, $humanAction]) }}">
                                                            @csrf
                                                            <x-secondary-button type="submit">Reverse automatic alias</x-secondary-button>
                                                        </form>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif

                                @if ($githubIssuesEnabled && $review)
                                    <div class="mt-4 border-t border-indigo-100 pt-3">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">GitHub parser-bug issue</p>

                                        @if ($parserBugReport)
                                            <p class="mt-1 text-sm font-medium text-gray-900">{{ str($parserBugReport->status->value)->headline() }} · {{ $parserBugReport->occurrence_count }} occurrence(s)</p>
                                            <p class="mt-1 text-sm text-gray-800">{{ $parserBugReport->title }}</p>

                                            @if ($parserBugReport->issue_url)
                                                <p class="mt-2 text-sm">
                                                    <a class="text-blue-700" href="{{ $parserBugReport->issue_url }}" rel="noreferrer">View GitHub issue #{{ $parserBugReport->issue_number }}</a>
                                                    · {{ str($parserBugReport->issue_state)->headline() }}
                                                </p>
                                            @else
                                                <details class="mt-2 text-sm">
                                                    <summary class="cursor-pointer font-medium text-gray-700">Review exact issue preview</summary>
                                                    <pre class="mt-2 max-h-96 overflow-auto whitespace-pre-wrap rounded bg-white p-3 text-xs text-gray-800">{{ $parserBugReport->body }}</pre>
                                                </details>

                                                @if ($parserBugReport->status === \App\Enums\ParserBugReportStatus::Invalidated)
                                                    <p class="mt-2 text-sm text-amber-800">This preview was invalidated by a later human action and cannot be published.</p>
                                                @elseif ($parserBugReport->requires_approval && $parserBugReport->approved_at === null)
                                                    <form method="POST" action="{{ route('admin.parser-bug-reports.approve', $parserBugReport) }}" class="mt-3">
                                                        @csrf
                                                        <x-primary-button type="submit">Approve GitHub issue preview</x-primary-button>
                                                    </form>
                                                @elseif (! $parserBugReport->requires_approval && $parserBugReport->approved_at === null)
                                                    <p class="mt-2 text-xs text-gray-600">This candidate is eligible for automatic publication.</p>
                                                    @if (config('fish.github_issues.write_enabled'))
                                                        <form method="POST" action="{{ route('admin.parser-bug-reports.approve', $parserBugReport) }}" class="mt-2">
                                                            @csrf
                                                            <x-secondary-button type="submit">{{ $parserBugReport->status === \App\Enums\ParserBugReportStatus::Failed ? 'Retry GitHub issue' : 'Queue GitHub issue' }}</x-secondary-button>
                                                        </form>
                                                    @endif
                                                @elseif ($parserBugReport->approved_at)
                                                    <p class="mt-2 text-xs text-green-700">Approved by {{ $parserBugReport->approved_by_name }}. Publication is {{ config('fish.github_issues.write_enabled') ? 'enabled' : 'waiting for the GitHub write flag' }}.</p>
                                                    @if (config('fish.github_issues.write_enabled'))
                                                        <form method="POST" action="{{ route('admin.parser-bug-reports.approve', $parserBugReport) }}" class="mt-2">
                                                            @csrf
                                                            <x-secondary-button type="submit">Publish approved issue</x-secondary-button>
                                                        </form>
                                                    @endif
                                                @endif
                                            @endif

                                            @if ($parserBugReport->failure_message)
                                                <p class="mt-2 text-sm text-red-700">{{ $parserBugReport->failure_message }}</p>
                                            @endif
                                        @elseif ($review->status === \App\Enums\ParserDiagnosticReviewStatus::Succeeded
                                            && (float) $review->confidence >= (float) config('fish.github_issues.minimum_confidence')
                                            && in_array($review->classification?->value, config('fish.github_issues.eligible_classifications'), true))
                                            <form method="POST" action="{{ route('admin.parser-errors.reviews.prepare-github-issue', [$error, $review]) }}" class="mt-2">
                                                @csrf
                                                <x-secondary-button type="submit">Prepare GitHub issue preview</x-secondary-button>
                                            </form>
                                        @else
                                            <p class="mt-1 text-sm text-gray-600">This review does not meet the validated parser-bug threshold.</p>
                                        @endif
                                    </div>
                                @endif

                                @if ($reportOverridesEnabled && $review && in_array($error->scrapeSource->slug, $reportOverrideSourceSlugs, true))
                                    <div class="mt-4 border-t border-indigo-100 pt-3">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">Report-scoped override</p>

                                        @if ($reportOverride)
                                            <p class="mt-1 text-sm font-medium text-gray-900">
                                                {{ str($reportOverride->status->value)->headline() }} · payload #{{ $reportOverride->raw_scrape_payload_id }} · {{ data_get($reportOverride->affected_scope, 'date') }}
                                            </p>
                                            <p class="mt-1 text-xs text-gray-600">
                                                Parser {{ $reportOverride->parser_version }} · correction schema {{ $reportOverride->correction_schema_version }} · GitHub issue #{{ $reportOverride->parserBugReport->issue_number }}
                                            </p>

                                            <div class="mt-3 grid gap-3 lg:grid-cols-2">
                                                <details class="text-sm">
                                                    <summary class="cursor-pointer font-medium text-gray-700">Original deterministic parse</summary>
                                                    <pre class="mt-2 max-h-72 overflow-auto whitespace-pre-wrap rounded bg-white p-3 text-xs text-gray-800">{{ json_encode($reportOverride->original_parse, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                                </details>
                                                <details class="text-sm">
                                                    <summary class="cursor-pointer font-medium text-gray-700">Corrected parse and affected scope</summary>
                                                    <pre class="mt-2 max-h-72 overflow-auto whitespace-pre-wrap rounded bg-white p-3 text-xs text-gray-800">{{ json_encode(['scope' => $reportOverride->affected_scope, 'corrected_parse' => $reportOverride->corrected_parse], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                                </details>
                                            </div>

                                            @if ($reportOverride->status === \App\Enums\ParserReportOverrideStatus::Pending)
                                                <form method="POST" action="{{ route('admin.parser-report-overrides.approve', $reportOverride) }}" class="mt-3 space-y-2">
                                                    @csrf
                                                    <textarea name="review_notes" rows="2" maxlength="2000" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Optional approval notes"></textarea>
                                                    <x-primary-button type="submit">Approve override and reparse</x-primary-button>
                                                </form>
                                            @elseif ($reportOverride->status === \App\Enums\ParserReportOverrideStatus::Active)
                                                <p class="mt-2 text-xs text-green-700">Approved by {{ $reportOverride->approved_by_name }} at {{ $reportOverride->approved_at?->format('n/j/Y g:i A') }}.</p>
                                                <form method="POST" action="{{ route('admin.parser-report-overrides.disable', $reportOverride) }}" class="mt-3 space-y-2">
                                                    @csrf
                                                    <textarea name="disable_reason" rows="2" maxlength="1000" required class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Reason for rollback"></textarea>
                                                    <x-secondary-button type="submit">Disable override and restore deterministic parse</x-secondary-button>
                                                </form>
                                            @elseif ($reportOverride->status === \App\Enums\ParserReportOverrideStatus::Invalidated)
                                                <p class="mt-2 text-sm text-amber-800">Invalidated: {{ str($reportOverride->invalidation_reason)->headline() }}. Create a fresh reviewed proposal.</p>
                                            @else
                                                <p class="mt-2 text-sm text-gray-700">Disabled by {{ $reportOverride->disabled_by_name }}: {{ $reportOverride->disable_reason }}</p>
                                            @endif
                                        @elseif (! $error->resolved_at
                                            && $review->status === \App\Enums\ParserDiagnosticReviewStatus::Succeeded
                                            && filled(data_get($review->validated_result, 'corrections'))
                                            && $parserBugReport?->issue_number
                                            && $parserBugReport?->issue_url)
                                            <p class="mt-1 text-sm text-gray-700">The correction will affect payload #{{ $error->raw_scrape_payload_id }} and all normalized reports for {{ $error->scrapeSource->name }} on {{ $error->target_date?->format('n/j/Y') }}.</p>
                                            <form method="POST" action="{{ route('admin.parser-errors.reviews.report-overrides.store', [$error, $review]) }}" class="mt-2">
                                                @csrf
                                                <x-secondary-button type="submit">Prepare report override for approval</x-secondary-button>
                                            </form>
                                        @else
                                            <p class="mt-1 text-sm text-gray-600">A successful typed correction and published deduplicated GitHub issue are required.</p>
                                        @endif
                                    </div>
                                @endif

                                @if ($humanReviewEnabled && $review && ! $error->resolved_at)
                                    @if (in_array($review->classification, [\App\Enums\ParserDiagnosticReviewClassification::NewEntityCandidate, \App\Enums\ParserDiagnosticReviewClassification::Uncertain], true))
                                        <p class="mt-4 text-sm font-medium text-amber-800">This outcome stays open for human handling and cannot create a canonical entity.</p>
                                    @endif

                                    <div class="mt-4 flex flex-wrap gap-2 border-t border-indigo-100 pt-4">
                                        @if ($review->status === \App\Enums\ParserDiagnosticReviewStatus::Succeeded && $review->classification === \App\Enums\ParserDiagnosticReviewClassification::LegitimateAlias && $reviewTarget)
                                            <form method="POST" action="{{ route('admin.parser-errors.reviews.accept', [$error, $review]) }}">
                                                @csrf
                                                <x-primary-button type="submit">Accept existing alias</x-primary-button>
                                            </form>
                                        @endif

                                        @if ($review->status === \App\Enums\ParserDiagnosticReviewStatus::Succeeded)
                                            <form method="POST" action="{{ route('admin.parser-errors.reviews.reject', [$error, $review]) }}">
                                                @csrf
                                                <x-secondary-button type="submit">Reject recommendation</x-secondary-button>
                                            </form>
                                        @endif

                                        <form method="POST" action="{{ route('admin.parser-errors.reviews.dismiss', [$error, $review]) }}">
                                            @csrf
                                            <x-secondary-button type="submit">Dismiss parser error</x-secondary-button>
                                        </form>

                                        @if (! $activeReviewRun && ! in_array($review->status, [\App\Enums\ParserDiagnosticReviewStatus::Pending, \App\Enums\ParserDiagnosticReviewStatus::Running], true) && config('fish.ai_review.enabled') && config('fish.ai_review.dispatch_enabled'))
                                            <form method="POST" action="{{ route('admin.parser-errors.reviews.retry', [$error, $review]) }}">
                                                @csrf
                                                <x-secondary-button type="submit">Retry review</x-secondary-button>
                                            </form>
                                        @endif

                                        <form method="POST" action="{{ route('admin.parser-errors.reviews.leave-open', [$error, $review]) }}">
                                            @csrf
                                            <x-secondary-button type="submit">Leave open</x-secondary-button>
                                        </form>
                                    </div>
                                @endif
                            </section>
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">{{ $showAll ? 'No parser errors.' : 'No open parser errors.' }}</p>
            @endforelse

            {{ $errors->links() }}
        </div>
    </div>
</x-app-layout>
