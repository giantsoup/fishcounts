<?php

namespace App\Jobs;

use App\Actions\Parsing\AutomateParserDiagnosticReviews;
use App\Contracts\AI\ParserDiagnosticReviewer;
use App\Enums\ParserDiagnosticReviewStatus;
use App\Enums\ParserEngine;
use App\Exceptions\AiBudgetExceededException;
use App\Exceptions\OpenAiIncompleteResponseException;
use App\Exceptions\OpenAiResponseValidationException;
use App\Models\AiBudgetReservation;
use App\Models\ParserDiagnosticReview;
use App\Models\ParserDiagnosticReviewRun;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use App\Services\AI\AiBudgetManager;
use App\Services\AI\OpenAiUsageCostCalculator;
use App\Services\AI\ParserDiagnosticReviewRequestFactory;
use App\Services\AI\ParserDiagnosticReviewRequestValidator;
use App\Services\AI\ParserDiagnosticReviewResultValidator;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;
use UnexpectedValueException;
use ValueError;

class ReviewParserDiagnosticsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const string COST_CALCULATION_VERSION = 'openai-list-price-v1';

    public int $tries = 0;

    public int $timeout = 210;

    /**
     * @param  null|list<string>  $diagnosticFingerprints
     */
    public function __construct(
        public int $rawScrapePayloadId,
        public ?int $parserDiagnosticReviewRunId = null,
        public ?array $diagnosticFingerprints = null,
        public bool $finalizeParserDiagnosticReviewRun = true,
        public ?string $uniqueContext = null,
    ) {
        $this->onConnection('database');
        $this->onQueue('ai-parsing');
    }

    public function uniqueId(): string
    {
        $base = $this->parserDiagnosticReviewRunId === null
            ? (string) $this->rawScrapePayloadId
            : "{$this->rawScrapePayloadId}:review-run:{$this->parserDiagnosticReviewRunId}";

        if ($this->uniqueContext !== null) {
            $base .= ':context:'.hash('sha256', $this->uniqueContext);
        }

        if ($this->diagnosticFingerprints === null) {
            return $base;
        }

        $fingerprints = $this->diagnosticFingerprints;
        sort($fingerprints);

        return $base.':batch:'.hash('sha256', implode('|', $fingerprints));
    }

    public function uniqueFor(): int
    {
        return max(300, ((int) config('fish.ai_review.retry_window_minutes') * 60) + $this->timeout + 60);
    }

    public function uniqueVia(): Repository
    {
        return cache()->store('database');
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new RateLimited('ai-parser-reviews'))->releaseAfter(60),
            (new WithoutOverlapping("parser-diagnostic-review:{$this->rawScrapePayloadId}"))
                ->releaseAfter(15)
                ->expireAfter($this->timeout + 60),
        ];
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes((int) config('fish.ai_review.retry_window_minutes'));
    }

    public function handle(
        ParserDiagnosticReviewer $reviewer,
        ParserDiagnosticReviewRequestFactory $requestFactory,
        ParserDiagnosticReviewRequestValidator $requestValidator,
        ParserDiagnosticReviewResultValidator $resultValidator,
        AiBudgetManager $budgetManager,
        OpenAiUsageCostCalculator $costCalculator,
        AutomateParserDiagnosticReviews $automateParserDiagnosticReviews,
    ): void {
        $reviewRun = $this->reviewRun();

        if ($this->parserDiagnosticReviewRunId !== null && ($reviewRun === null || ! $reviewRun->status->isActive())) {
            return;
        }

        if (! $this->enabled()) {
            $this->skip(ParserDiagnosticReview::query()
                ->where('raw_scrape_payload_id', $this->rawScrapePayloadId)
                ->when(
                    $this->diagnosticFingerprints !== null,
                    fn ($query) => $query->whereIn('diagnostic_fingerprint', $this->diagnosticFingerprints),
                )
                ->whereIn('status', [ParserDiagnosticReviewStatus::Pending, ParserDiagnosticReviewStatus::Running])
                ->get());
            $this->failReviewRun('AI review dispatch is no longer available.');

            return;
        }

        $reviewRun?->markRunning();

        $payload = RawScrapePayload::query()
            ->with('authoritativeParserExecution:id,selected_engine')
            ->find($this->rawScrapePayloadId);
        if ($payload === null) {
            $this->failReviewRun('The raw payload is no longer available for AI review.');

            return;
        }
        if ($payload->authoritativeParserExecution?->selected_engine === ParserEngine::Ai) {
            $this->skip(ParserDiagnosticReview::query()
                ->where('raw_scrape_payload_id', $this->rawScrapePayloadId)
                ->when(
                    $this->diagnosticFingerprints !== null,
                    fn ($query) => $query->whereIn('diagnostic_fingerprint', $this->diagnosticFingerprints),
                )
                ->whereIn('status', [ParserDiagnosticReviewStatus::Pending, ParserDiagnosticReviewStatus::Running])
                ->get());
            $this->failReviewRun('AI-authored primary output is not eligible for diagnostic AI review.');

            return;
        }

        $parserErrors = ParserError::query()
            ->whereBelongsTo($payload, 'rawScrapePayload')
            ->whereNull('resolution_type')
            ->whereNotNull('diagnostic_fingerprint')
            ->when(
                $this->diagnosticFingerprints !== null,
                fn ($query) => $query->whereIn('diagnostic_fingerprint', $this->diagnosticFingerprints),
            )
            ->orderBy('id')
            ->get();

        if ($parserErrors->isEmpty()) {
            $this->finishReviewRun();

            return;
        }

        $requests = [];
        $reviews = collect();
        foreach ($parserErrors as $parserError) {
            $review = $this->reviewFor($payload, $parserError);

            if (! in_array($review->status, [ParserDiagnosticReviewStatus::Pending, ParserDiagnosticReviewStatus::Failed], true)) {
                continue;
            }

            if ($review->status === ParserDiagnosticReviewStatus::Failed) {
                $review->prepareForRetry();
            }

            try {
                $request = $requestFactory->make($payload, $parserError);
                $requestValidator->validate($request);
            } catch (ValidationException|ValueError $exception) {
                $review->transitionTo(ParserDiagnosticReviewStatus::Running);
                $review->fail($this->safeFailureMessage($exception), 'schema_validation');

                continue;
            }

            $requests[$request->diagnosticFingerprint] = $request;
            $reviews->push($review);
        }

        if ($reviews->isEmpty()) {
            $this->finishReviewRun();

            return;
        }

        if (! $this->isCurrent($payload, $reviews)) {
            $this->stale($reviews);
            $this->finishReviewRun();

            return;
        }

        $reservation = null;
        $actualCost = null;
        $estimatedCost = (int) config('fish.ai_review.budgets.estimated_request_cost_micros');
        try {
            if (! $this->enabled()) {
                $this->skip($reviews);
                $this->failReviewRun('AI review dispatch is no longer available.');

                return;
            }

            $reservation = $budgetManager->reserve(
                provider: (string) config('fish.ai_review.provider'),
                reservationKey: 'parser-review-'.hash('sha256', implode('|', [
                    $payload->id,
                    $payload->payload_hash,
                    $reviews->pluck('diagnostic_fingerprint')->sort()->implode(','),
                    $reviews->first()->attempts + 1,
                ])),
                estimatedCostMicros: $estimatedCost,
                review: $reviews->first(),
            );

            if (! $this->enabled()) {
                $budgetManager->release($reservation);
                $this->skip($reviews);
                $this->failReviewRun('AI review dispatch is no longer available.');

                return;
            }

            if (! $this->isCurrent($payload, $reviews)) {
                $budgetManager->release($reservation);
                $this->stale($reviews);
                $this->finishReviewRun();

                return;
            }

            foreach ($reviews as $review) {
                $review->transitionTo(ParserDiagnosticReviewStatus::Running);
            }

            $providerResponse = $reviewer->review(array_values(array_intersect_key($requests, array_flip($reviews->pluck('diagnostic_fingerprint')->all()))));
            $actualCost = $costCalculator->calculate(
                model: $providerResponse->model,
                serviceTier: $providerResponse->serviceTier,
                inputTokens: $providerResponse->inputTokens,
                cachedInputTokens: $providerResponse->cachedInputTokens,
                cacheWriteTokens: $providerResponse->cacheWriteTokens,
                outputTokens: $providerResponse->outputTokens,
            );

            if (! $this->isCurrent($payload, $reviews)) {
                $budgetManager->settle($reservation, $actualCost);
                $this->stale($reviews);
                $this->finishReviewRun();

                return;
            }

            if ($providerResponse->refused) {
                $this->recordRefusal($reviews, $providerResponse, $actualCost);
                $budgetManager->settle($reservation, $actualCost);
                $this->finishReviewRun();

                return;
            }

            if (array_keys($providerResponse->results) !== $reviews->pluck('diagnostic_fingerprint')->sort()->values()->all()) {
                $expected = $reviews->pluck('diagnostic_fingerprint')->sort()->values()->all();
                $actual = array_keys($providerResponse->results);
                sort($actual);
                if ($actual !== $expected) {
                    throw new UnexpectedValueException('The provider did not return exactly one result for every diagnostic.');
                }
            }

            $automatableReviewIds = [];
            foreach ($reviews as $index => $review) {
                $request = $requests[$review->diagnostic_fingerprint];
                try {
                    $result = $resultValidator->validate($providerResponse->results[$review->diagnostic_fingerprint], $request);
                } catch (ValidationException|UnexpectedValueException|ValueError $throwable) {
                    $review->fail($this->safeFailureMessage($throwable), 'schema_validation');

                    continue;
                }

                $review->forceFill($this->resultAttributes($result->toArray(), $providerResponse, $index, $reviews->count(), $actualCost))->save();
                $review->transitionTo(ParserDiagnosticReviewStatus::Succeeded);
                $automatableReviewIds[] = $review->id;
                $this->dispatchParserBugIssue($review);
            }

            $budgetManager->settle($reservation, $actualCost);
            rescue(
                fn (): int => $automateParserDiagnosticReviews->handle($payload->id, $automatableReviewIds),
                report: true,
            );
            $this->finishReviewRun();
        } catch (AiBudgetExceededException) {
            $this->skip($reviews, 'budget_exhausted');
            $this->finishReviewRun();
        } catch (Throwable $throwable) {
            $hasMeteredUsage = $throwable instanceof OpenAiIncompleteResponseException
                || ($throwable instanceof OpenAiResponseValidationException && $throwable->hasValidUsage);

            if ($hasMeteredUsage) {
                try {
                    $actualCost = $costCalculator->calculate(
                        model: $throwable->model,
                        serviceTier: $throwable->serviceTier,
                        inputTokens: $throwable->inputTokens,
                        cachedInputTokens: $throwable->cachedInputTokens,
                        cacheWriteTokens: $throwable->cacheWriteTokens,
                        outputTokens: $throwable->outputTokens,
                    );
                } catch (InvalidArgumentException $costCalculationException) {
                    report($costCalculationException);
                }
            }

            if ($reservation instanceof AiBudgetReservation) {
                if ($throwable instanceof ConnectionException || $throwable instanceof RequestException) {
                    $budgetManager->release($reservation);
                } else {
                    $budgetManager->settle($reservation, $actualCost ?? $estimatedCost);
                }
            }
            if ($throwable instanceof OpenAiIncompleteResponseException) {
                $this->recordIncompleteFailure($reviews, $throwable, $actualCost);
            } elseif ($throwable instanceof OpenAiResponseValidationException) {
                $this->recordResponseValidationFailure($reviews, $throwable, $actualCost);
            } else {
                $this->failReviews($reviews, $throwable);
            }

            $shouldRetry = $throwable instanceof ConnectionException
                || ($throwable instanceof RequestException
                    && ($throwable->response->status() === 429 || $throwable->response->serverError()));

            if (! $shouldRetry) {
                $this->failReviewRun($throwable);

                return;
            }

            throw $throwable;
        }
    }

    public function failed(Throwable $throwable): void
    {
        $reviewRun = $this->reviewRun();

        if ($this->parserDiagnosticReviewRunId !== null && ($reviewRun === null || ! $reviewRun->status->isActive())) {
            return;
        }

        $reviewRun?->markFailed($throwable);

        $reviews = ParserDiagnosticReview::query()
            ->where('raw_scrape_payload_id', $this->rawScrapePayloadId)
            ->when(
                $this->diagnosticFingerprints !== null,
                fn ($query) => $query->whereIn('diagnostic_fingerprint', $this->diagnosticFingerprints),
            )
            ->whereIn('status', [ParserDiagnosticReviewStatus::Pending, ParserDiagnosticReviewStatus::Running])
            ->get();
        $this->failReviews($reviews, $throwable);
    }

    private function reviewRun(): ?ParserDiagnosticReviewRun
    {
        if ($this->parserDiagnosticReviewRunId === null) {
            return null;
        }

        return ParserDiagnosticReviewRun::query()
            ->whereKey($this->parserDiagnosticReviewRunId)
            ->where('raw_scrape_payload_id', $this->rawScrapePayloadId)
            ->first();
    }

    private function enabled(): bool
    {
        return (bool) config('fish.ai_review.enabled')
            && (bool) config('fish.ai_review.dispatch_enabled')
            && filled(config('services.openai.api_key'));
    }

    private function reviewFor(RawScrapePayload $payload, ParserError $parserError): ParserDiagnosticReview
    {
        $review = ParserDiagnosticReview::query()->firstOrCreate([
            'raw_scrape_payload_id' => $payload->id,
            'diagnostic_fingerprint' => $parserError->diagnostic_fingerprint,
        ], [
            'parser_error_id' => $parserError->id,
            'payload_hash' => $payload->payload_hash,
            'provider' => config('fish.ai_review.provider'),
            'model' => config('fish.ai_review.model'),
            'prompt_version' => config('fish.ai_review.prompt_version'),
            'schema_version' => config('fish.ai_review.schema_version'),
        ]);

        $attributes = $review->parser_error_id !== $parserError->id
            ? ['parser_error_id' => $parserError->id]
            : [];

        if (in_array($review->status, [ParserDiagnosticReviewStatus::Pending, ParserDiagnosticReviewStatus::Failed], true)) {
            $attributes += [
                'payload_hash' => $payload->payload_hash,
                'provider' => config('fish.ai_review.provider'),
                'model' => config('fish.ai_review.model'),
                'prompt_version' => config('fish.ai_review.prompt_version'),
                'schema_version' => config('fish.ai_review.schema_version'),
            ];
        }

        if ($attributes !== []) {
            $review->forceFill($attributes)->save();
        }

        return $review;
    }

    private function isCurrent(RawScrapePayload $payload, Collection $reviews): bool
    {
        if (RawScrapePayload::query()->whereKey($payload->id)->value('payload_hash') !== $payload->payload_hash) {
            return false;
        }

        $current = ParserError::query()->where('raw_scrape_payload_id', $payload->id)->whereNull('resolution_type')->pluck('diagnostic_fingerprint')->sort()->values();

        $reviewFingerprints = $reviews->pluck('diagnostic_fingerprint')->sort()->values();

        return $reviewFingerprints->diff($current)->isEmpty();
    }

    private function recordRefusal(Collection $reviews, object $response, int $actualCost): void
    {
        foreach ($reviews as $index => $review) {
            $review->forceFill($this->usageAttributes($response, $index, $reviews->count()) + [
                'response_id' => $response->responseId,
                'model' => $response->model,
                'failure_message' => str($response->refusal)->limit((int) config('fish.ai_review.limits.max_failure_message_length'))->toString(),
                'failure_category' => 'provider_refusal',
            ] + $this->costAttributes($actualCost, $index, $reviews->count()))->save();
            $review->transitionTo(ParserDiagnosticReviewStatus::Refused);
        }
    }

    private function recordIncompleteFailure(
        Collection $reviews,
        OpenAiIncompleteResponseException $exception,
        ?int $actualCost,
    ): void {
        $category = $exception->reason === 'max_output_tokens'
            ? 'output_token_limit'
            : 'provider_incomplete';

        foreach ($reviews as $index => $review) {
            if ($review->status !== ParserDiagnosticReviewStatus::Running) {
                continue;
            }

            $review->forceFill($this->usageAttributes($exception, $index, $reviews->count()) + [
                'response_id' => $exception->responseId !== '' ? $exception->responseId : null,
                'model' => $exception->model,
            ] + $this->costAttributes($actualCost, $index, $reviews->count()))->save();
            $review->fail($this->safeFailureMessage($exception), $category);
        }
    }

    private function recordResponseValidationFailure(
        Collection $reviews,
        OpenAiResponseValidationException $exception,
        ?int $actualCost,
    ): void {
        foreach ($reviews as $index => $review) {
            $attributes = $exception->hasValidUsage
                ? $this->usageAttributes($exception, $index, $reviews->count())
                : [];

            $review->forceFill($attributes + [
                'response_id' => $exception->responseId !== '' ? $exception->responseId : null,
                'model' => $exception->model !== '' ? $exception->model : $review->model,
            ] + $this->costAttributes($actualCost, $index, $reviews->count()))->save();
            $review->fail($this->safeFailureMessage($exception), 'schema_validation');
        }
    }

    private function resultAttributes(array $result, object $response, int $index, int $count, int $actualCost): array
    {
        return $this->usageAttributes($response, $index, $count) + [
            'classification' => $result['classification'],
            'confidence' => $result['confidence'],
            'validated_result' => $result,
            'rationale' => $result['rationale'],
            'response_id' => $response->responseId,
            'model' => $response->model,
        ] + $this->costAttributes($actualCost, $index, $count);
    }

    private function usageAttributes(object $response, int $index, int $count): array
    {
        return [
            'input_tokens' => $this->share($response->inputTokens, $index, $count),
            'cached_input_tokens' => $this->share($response->cachedInputTokens, $index, $count),
            'cache_write_tokens' => $this->share($response->cacheWriteTokens, $index, $count),
            'output_tokens' => $this->share($response->outputTokens, $index, $count),
            'reasoning_tokens' => $this->share($response->reasoningTokens, $index, $count),
            'total_tokens' => $this->share($response->totalTokens, $index, $count),
            'service_tier' => $response->serviceTier,
        ];
    }

    private function costAttributes(?int $actualCost, int $index, int $count): array
    {
        if ($actualCost === null) {
            return [
                'estimated_cost_micros' => null,
                'cost_calculation_version' => null,
                'pricing_snapshot' => null,
            ];
        }

        return [
            'estimated_cost_micros' => $this->share($actualCost, $index, $count),
            'cost_calculation_version' => self::COST_CALCULATION_VERSION,
            'pricing_snapshot' => [
                'model' => (string) config('fish.ai_review.pricing.model'),
                'service_tier' => (string) config('fish.ai_review.pricing.service_tier'),
                'input_cost_per_million_micros' => (int) config('fish.ai_review.pricing.input_cost_per_million_micros'),
                'cached_input_cost_per_million_micros' => (int) config('fish.ai_review.pricing.cached_input_cost_per_million_micros'),
                'cache_write_cost_per_million_micros' => (int) config('fish.ai_review.pricing.cache_write_cost_per_million_micros'),
                'output_cost_per_million_micros' => (int) config('fish.ai_review.pricing.output_cost_per_million_micros'),
            ],
        ];
    }

    private function share(int $total, int $index, int $count): int
    {
        return intdiv($total, $count) + ($index < $total % $count ? 1 : 0);
    }

    private function skip(Collection $reviews, ?string $category = null): void
    {
        foreach ($reviews as $review) {
            if ($category !== null) {
                $review->failure_category = $category;
                $review->save();
            }

            if ($review->status === ParserDiagnosticReviewStatus::Running) {
                $review->transitionTo(ParserDiagnosticReviewStatus::Stale);
            } elseif ($review->status === ParserDiagnosticReviewStatus::Pending) {
                $review->transitionTo(ParserDiagnosticReviewStatus::Skipped);
            }
        }
    }

    private function stale(Collection $reviews): void
    {
        foreach ($reviews as $review) {
            $review->transitionTo(ParserDiagnosticReviewStatus::Stale);
        }
    }

    private function failReviews(Collection $reviews, Throwable $throwable): void
    {
        $message = $this->safeFailureMessage($throwable);
        foreach ($reviews as $review) {
            if ($review->status === ParserDiagnosticReviewStatus::Running) {
                $review->fail($message, $this->failureCategory($throwable));
            }
        }
    }

    private function finishReviewRun(): void
    {
        if (! $this->finalizeParserDiagnosticReviewRun) {
            return;
        }

        $reviewRun = $this->reviewRun();
        if ($reviewRun === null) {
            return;
        }

        $hasFailedReview = ParserDiagnosticReview::query()
            ->where('raw_scrape_payload_id', $this->rawScrapePayloadId)
            ->where('status', ParserDiagnosticReviewStatus::Failed)
            ->whereHas('parserError', fn ($query) => $query->whereNull('resolution_type'))
            ->exists();

        if ($hasFailedReview) {
            $reviewRun->markFailed('One or more AI review batches failed. Review the individual errors before retrying.');

            return;
        }

        $reviewRun->markCompleted();
    }

    private function failReviewRun(Throwable|string $failure): void
    {
        if ($this->finalizeParserDiagnosticReviewRun) {
            $this->reviewRun()?->markFailed($failure);
        }
    }

    private function safeFailureMessage(Throwable $throwable): string
    {
        $message = preg_replace('/(?:sk-[A-Za-z0-9_-]+|Bearer\s+\S+)/i', '[redacted]', $throwable->getMessage()) ?? 'AI review failed.';

        return str($message)->limit((int) config('fish.ai_review.limits.max_failure_message_length'))->toString();
    }

    private function failureCategory(Throwable $throwable): string
    {
        if ($throwable instanceof OpenAiIncompleteResponseException) {
            return $throwable->reason === 'max_output_tokens'
                ? 'output_token_limit'
                : 'provider_incomplete';
        }

        if ($throwable instanceof ValidationException || $throwable instanceof ValueError || $throwable instanceof UnexpectedValueException) {
            return 'schema_validation';
        }

        if ($throwable instanceof ConnectionException) {
            return 'provider_unavailable';
        }

        if ($throwable instanceof RequestException) {
            return match ($throwable->response->status()) {
                401, 403 => 'invalid_credentials',
                429 => 'rate_limited',
                default => $throwable->response->serverError() ? 'provider_unavailable' : 'provider_request',
            };
        }

        return 'application';
    }

    private function dispatchParserBugIssue(ParserDiagnosticReview $review): void
    {
        if (! config('fish.github_issues.enabled')
            || $review->confidence === null
            || (float) $review->confidence < (float) config('fish.github_issues.minimum_confidence')
            || ! in_array($review->classification?->value, config('fish.github_issues.eligible_classifications'), true)) {
            return;
        }

        try {
            CreateParserBugIssueJob::dispatch($review->id);
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }
}
