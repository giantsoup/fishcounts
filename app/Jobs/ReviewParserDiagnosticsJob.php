<?php

namespace App\Jobs;

use App\Contracts\AI\ParserDiagnosticReviewer;
use App\Enums\ParserDiagnosticReviewStatus;
use App\Exceptions\AiBudgetExceededException;
use App\Models\AiBudgetReservation;
use App\Models\ParserDiagnosticReview;
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

    public function __construct(public int $rawScrapePayloadId)
    {
        $this->onConnection('database');
        $this->onQueue('ai-parsing');
    }

    public function uniqueId(): string
    {
        return (string) $this->rawScrapePayloadId;
    }

    public function uniqueVia(): Repository
    {
        return cache()->store('database');
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new RateLimited('ai-parser-reviews'))->releaseAfter(60)];
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
    ): void {
        if (! $this->enabled()) {
            return;
        }

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
                $review->fail($this->safeFailureMessage($exception));

                continue;
            }

            $requests[$request->diagnosticFingerprint] = $request;
            $reviews->push($review);
        }

        if ($reviews->isEmpty()) {
            return;
        }

        if (! $this->isCurrent($payload, $reviews)) {
            $this->stale($reviews);

            return;
        }

        $reservation = null;
        try {
            if (! $this->enabled()) {
                $this->skip($reviews);

                return;
            }

            $estimatedCost = (int) config('fish.ai_review.budgets.estimated_request_cost_micros');
            $reservation = $budgetManager->reserveMonthly(
                provider: (string) config('fish.ai_review.provider'),
                reservationKey: 'parser-review-'.hash('sha256', $payload->id.'|'.$payload->payload_hash.'|'.($reviews->first()->attempts + 1)),
                estimatedCostMicros: $estimatedCost,
                review: $reviews->first(),
            );

            if (! $this->enabled()) {
                $budgetManager->release($reservation);
                $this->skip($reviews);

                return;
            }

            if (! $this->isCurrent($payload, $reviews)) {
                $budgetManager->release($reservation);
                $this->stale($reviews);

                return;
            }

            foreach ($reviews as $review) {
                $review->transitionTo(ParserDiagnosticReviewStatus::Running);
            }

            $providerResponse = $reviewer->review(array_values(array_intersect_key($requests, array_flip($reviews->pluck('diagnostic_fingerprint')->all()))));

            if (! $this->isCurrent($payload, $reviews)) {
                $budgetManager->settle($reservation, $estimatedCost);
                $this->stale($reviews);

                return;
            }

            if ($providerResponse->refused) {
                $this->recordRefusal($reviews, $providerResponse, $estimatedCost);
                $budgetManager->settle($reservation, $estimatedCost);

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

            foreach ($reviews as $index => $review) {
                $request = $requests[$review->diagnostic_fingerprint];
                $result = $resultValidator->validate($providerResponse->results[$review->diagnostic_fingerprint], $request);
                $review->forceFill($this->resultAttributes($result->toArray(), $providerResponse, $index, $reviews->count(), $estimatedCost))->save();
                $review->transitionTo(ParserDiagnosticReviewStatus::Succeeded);
                $this->dispatchParserBugIssue($review);
            }

            $budgetManager->settle($reservation, $estimatedCost);
        } catch (AiBudgetExceededException) {
            $this->skip($reviews);
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
                return;
            }

            throw $throwable;
        }
    }

    public function failed(Throwable $throwable): void
    {
        $reviews = ParserDiagnosticReview::query()
            ->where('raw_scrape_payload_id', $this->rawScrapePayloadId)
            ->whereIn('status', [ParserDiagnosticReviewStatus::Pending, ParserDiagnosticReviewStatus::Running])
            ->get();
        $this->failReviews($reviews, $throwable);
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
            'provider' => config('fish.ai_review.provider'),
            'model' => config('fish.ai_review.model'),
            'prompt_version' => config('fish.ai_review.prompt_version'),
            'schema_version' => config('fish.ai_review.schema_version'),
        ]);

        if (in_array($review->status, [ParserDiagnosticReviewStatus::Pending, ParserDiagnosticReviewStatus::Failed], true)
            && $review->parser_error_id !== $parserError->id) {
            $review->forceFill(['parser_error_id' => $parserError->id])->save();
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

    private function skip(Collection $reviews): void
    {
        foreach ($reviews as $review) {
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
                $review->fail($message);
            }
        }
    }

    private function safeFailureMessage(Throwable $throwable): string
    {
        $message = preg_replace('/(?:sk-[A-Za-z0-9_-]+|Bearer\s+\S+)/i', '[redacted]', $throwable->getMessage()) ?? 'AI review failed.';

        return str($message)->limit((int) config('fish.ai_review.limits.max_failure_message_length'))->toString();
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
