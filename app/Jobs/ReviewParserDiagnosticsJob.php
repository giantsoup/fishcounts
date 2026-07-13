<?php

namespace App\Jobs;

use App\Actions\Parsing\AutomateParserDiagnosticReviews;
use App\Contracts\AI\ParserDiagnosticReviewer;
use App\Enums\ParserDiagnosticReviewStatus;
use App\Exceptions\AiBudgetExceededException;
use App\Models\AiBudgetReservation;
use App\Models\ParserDiagnosticReview;
use App\Models\ParserDiagnosticReviewRun;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use App\Services\AI\AiBudgetManager;
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
use Throwable;
use UnexpectedValueException;
use ValueError;

class ReviewParserDiagnosticsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $timeout = 210;

    public function __construct(
        public int $rawScrapePayloadId,
        public ?int $parserDiagnosticReviewRunId = null,
    ) {
        $this->onConnection('database');
        $this->onQueue('ai-parsing');
    }

    public function uniqueId(): string
    {
        return $this->parserDiagnosticReviewRunId === null
            ? (string) $this->rawScrapePayloadId
            : "{$this->rawScrapePayloadId}:review-run:{$this->parserDiagnosticReviewRunId}";
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
        AutomateParserDiagnosticReviews $automateParserDiagnosticReviews,
    ): void {
        $reviewRun = $this->reviewRun();

        if ($this->parserDiagnosticReviewRunId !== null && ($reviewRun === null || ! $reviewRun->status->isActive())) {
            return;
        }

        if (! $this->enabled()) {
            $this->skip(ParserDiagnosticReview::query()
                ->where('raw_scrape_payload_id', $this->rawScrapePayloadId)
                ->whereIn('status', [ParserDiagnosticReviewStatus::Pending, ParserDiagnosticReviewStatus::Running])
                ->get());
            $reviewRun?->markFailed('AI review dispatch is no longer available.');

            return;
        }

        $reviewRun?->markRunning();

        $payload = RawScrapePayload::query()->find($this->rawScrapePayloadId);
        if ($payload === null) {
            return;
        }

        $parserErrors = ParserError::query()
            ->whereBelongsTo($payload, 'rawScrapePayload')
            ->whereNull('resolution_type')
            ->whereNotNull('diagnostic_fingerprint')
            ->orderBy('id')
            ->get();

        if ($parserErrors->isEmpty()) {
            $reviewRun?->markCompleted();

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
                $review->transitionTo(ParserDiagnosticReviewStatus::Pending);
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
            $reviewRun?->markCompleted();

            return;
        }

        if (! $this->isCurrent($payload, $reviews)) {
            $this->stale($reviews);
            $reviewRun?->markCompleted();

            return;
        }

        $reservation = null;
        try {
            if (! $this->enabled()) {
                $this->skip($reviews);
                $reviewRun?->markFailed('AI review dispatch is no longer available.');

                return;
            }

            $estimatedCost = (int) config('fish.ai_review.budgets.estimated_request_cost_micros');
            $reservation = $budgetManager->reserve(
                provider: (string) config('fish.ai_review.provider'),
                reservationKey: 'parser-review-'.hash('sha256', $payload->id.'|'.$payload->payload_hash.'|'.($reviews->first()->attempts + 1)),
                estimatedCostMicros: $estimatedCost,
                review: $reviews->first(),
            );

            if (! $this->enabled()) {
                $budgetManager->release($reservation);
                $this->skip($reviews);
                $reviewRun?->markFailed('AI review dispatch is no longer available.');

                return;
            }

            if (! $this->isCurrent($payload, $reviews)) {
                $budgetManager->release($reservation);
                $this->stale($reviews);
                $reviewRun?->markCompleted();

                return;
            }

            foreach ($reviews as $review) {
                $review->transitionTo(ParserDiagnosticReviewStatus::Running);
            }

            $providerResponse = $reviewer->review(array_values(array_intersect_key($requests, array_flip($reviews->pluck('diagnostic_fingerprint')->all()))));

            if (! $this->isCurrent($payload, $reviews)) {
                $budgetManager->settle($reservation, $estimatedCost);
                $this->stale($reviews);
                $reviewRun?->markCompleted();

                return;
            }

            if ($providerResponse->refused) {
                $this->recordRefusal($reviews, $providerResponse, $estimatedCost);
                $budgetManager->settle($reservation, $estimatedCost);
                $reviewRun?->markCompleted();

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

                $review->forceFill($this->resultAttributes($result->toArray(), $providerResponse, $index, $reviews->count(), $estimatedCost))->save();
                $review->transitionTo(ParserDiagnosticReviewStatus::Succeeded);
                $automatableReviewIds[] = $review->id;
                $this->dispatchParserBugIssue($review);
            }

            $budgetManager->settle($reservation, $estimatedCost);
            rescue(
                fn (): int => $automateParserDiagnosticReviews->handle($payload->id, $automatableReviewIds),
                report: true,
            );
            $reviewRun?->markCompleted();
        } catch (AiBudgetExceededException) {
            $this->skip($reviews, 'budget_exhausted');
            $reviewRun?->markCompleted();
        } catch (Throwable $throwable) {
            if ($reservation instanceof AiBudgetReservation) {
                if ($throwable instanceof ConnectionException) {
                    $budgetManager->release($reservation);
                } else {
                    $budgetManager->settle($reservation, (int) config('fish.ai_review.budgets.estimated_request_cost_micros'));
                }
            }
            $this->failReviews($reviews, $throwable);

            $shouldRetry = $throwable instanceof ConnectionException
                || ($throwable instanceof RequestException
                    && ($throwable->response->status() === 429 || $throwable->response->serverError()));

            if (! $shouldRetry) {
                $reviewRun?->markFailed($throwable);

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

    private function recordRefusal(Collection $reviews, object $response, int $estimatedCost): void
    {
        foreach ($reviews as $index => $review) {
            $review->forceFill($this->usageAttributes($response, $index, $reviews->count()) + [
                'response_id' => $response->responseId,
                'model' => $response->model,
                'estimated_cost_micros' => $this->share($estimatedCost, $index, $reviews->count()),
                'failure_message' => str($response->refusal)->limit((int) config('fish.ai_review.limits.max_failure_message_length'))->toString(),
                'failure_category' => 'provider_refusal',
            ])->save();
            $review->transitionTo(ParserDiagnosticReviewStatus::Refused);
        }
    }

    private function resultAttributes(array $result, object $response, int $index, int $count, int $estimatedCost): array
    {
        return $this->usageAttributes($response, $index, $count) + [
            'classification' => $result['classification'],
            'confidence' => $result['confidence'],
            'validated_result' => $result,
            'rationale' => $result['rationale'],
            'response_id' => $response->responseId,
            'model' => $response->model,
            'estimated_cost_micros' => $this->share($estimatedCost, $index, $count),
        ];
    }

    private function usageAttributes(object $response, int $index, int $count): array
    {
        return [
            'input_tokens' => $this->share($response->inputTokens, $index, $count),
            'cached_input_tokens' => $this->share($response->cachedInputTokens, $index, $count),
            'output_tokens' => $this->share($response->outputTokens, $index, $count),
            'reasoning_tokens' => $this->share($response->reasoningTokens, $index, $count),
            'total_tokens' => $this->share($response->totalTokens, $index, $count),
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

    private function safeFailureMessage(Throwable $throwable): string
    {
        $message = preg_replace('/(?:sk-[A-Za-z0-9_-]+|Bearer\s+\S+)/i', '[redacted]', $throwable->getMessage()) ?? 'AI review failed.';

        return str($message)->limit((int) config('fish.ai_review.limits.max_failure_message_length'))->toString();
    }

    private function failureCategory(Throwable $throwable): string
    {
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
