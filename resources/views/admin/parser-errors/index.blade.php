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

                        @if ($humanReviewEnabled)
                            @php
                                $review = $error->latestDiagnosticReview;
                                $reviewTarget = $review ? $reviewTargets->get($review->id) : null;
                                $parserBugReport = $review?->parserBugReport;
                            @endphp

                            <section class="rounded-lg border border-indigo-100 bg-indigo-50 p-4 lg:col-span-5" aria-label="AI diagnostic review">
                                <div class="grid gap-4 lg:grid-cols-3">
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">AI review</p>
                                        @if ($review)
                                            <p class="mt-1 text-sm font-medium text-gray-900">{{ str($review->status->value)->headline() }}</p>
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
                                        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">Human audit history</p>
                                        <ul class="mt-1 space-y-1 text-xs text-gray-700">
                                            @foreach ($review->humanActions->sortByDesc('created_at') as $humanAction)
                                                <li>
                                                    {{ str($humanAction->action->value)->headline() }} by {{ $humanAction->actor_name }}
                                                    · {{ $humanAction->created_at->format('n/j/Y g:i A') }}
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

                                @if ($review && ! $error->resolved_at)
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

                                        @if (! in_array($review->status, [\App\Enums\ParserDiagnosticReviewStatus::Pending, \App\Enums\ParserDiagnosticReviewStatus::Running], true) && config('fish.ai_review.enabled') && config('fish.ai_review.dispatch_enabled'))
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
