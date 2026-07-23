<?php

namespace App\Services\Parsing;

use App\DTOs\AiParserProviderResponseData;
use App\DTOs\AiPrimaryParseResult;
use App\DTOs\ParsedFishCountCollection;
use App\DTOs\RawPayloadData;
use App\Enums\AiBudgetReservationStatus;
use App\Enums\AiParserAttemptCostBasis;
use App\Exceptions\AiParserProviderResponseException;
use App\Exceptions\AiParserRateLimitExceededException;
use App\Models\AiBudgetReservation;
use App\Models\ParserExecution;
use App\Models\RawScrapePayload;
use App\Services\AI\AiParserBudgetManager;
use App\Services\AI\AiParserUsageCostCalculator;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

final class AiPrimaryParser
{
    public function __construct(
        private readonly AiParserDocumentSanitizer $sanitizer,
        private readonly AiParserCatalog $catalog,
        private readonly OpenAiFishCountParser $provider,
        private readonly AiParsedCollectionFactory $collectionFactory,
        private readonly ParsedCollectionComparator $comparator,
        private readonly AiParserBudgetManager $budget,
        private readonly AiParserUsageCostCalculator $costCalculator,
    ) {}

    public function parse(
        ParserExecution $execution,
        RawScrapePayload $payload,
        RawPayloadData $rawPayload,
        ?ParsedFishCountCollection $deterministic,
    ): AiPrimaryParseResult {
        if (! (bool) config('fish.ai_parsing.enabled')) {
            throw new UnexpectedValueException('AI primary parsing is disabled.');
        }
        if (blank(config('services.openai.api_key'))) {
            throw new UnexpectedValueException('AI primary parsing credentials are unavailable.');
        }

        $catalog = $this->catalog->active();
        $document = $this->sanitizer->sanitize($rawPayload, $catalog);
        $catalogVersion = $this->catalog->version($catalog);
        $targetDate = $payload->target_date->toDateString();
        $this->provider->assertWithinInputLimit($document, $catalog, $targetDate);
        $execution->update([
            'sanitized_input_hash' => hash('sha256', $document),
            'catalog_version' => $catalogVersion,
        ]);

        $response = null;
        $successfulAttempt = 0;
        $attempts = AiBudgetReservation::query()
            ->where('parser_execution_id', $execution->id)
            ->orderBy('attempt_number')
            ->get();
        $providerReached = $attempts->first(
            fn (AiBudgetReservation $attempt): bool => $attempt->response_received_at !== null
                || $attempt->status === AiBudgetReservationStatus::Settled,
        );
        if ($providerReached instanceof AiBudgetReservation) {
            if ($providerReached->status === AiBudgetReservationStatus::Reserved) {
                $costBasis = $providerReached->cost_basis === AiParserAttemptCostBasis::None
                    ? AiParserAttemptCostBasis::EstimatedConservative
                    : $providerReached->cost_basis;
                $this->budget->settle(
                    $providerReached,
                    $providerReached->actual_micros ?? $providerReached->reserved_micros,
                    $costBasis,
                );
            }
            $this->syncExecutionTotals($execution);

            throw new UnexpectedValueException('A previous AI provider attempt completed without a validated parser snapshot.');
        }

        $existingAttempts = $attempts->count();
        for ($attempt = $existingAttempts + 1; $attempt <= 2; $attempt++) {
            $reservation = null;
            try {
                $this->acquireRateLimit();
                $reservation = $this->budget->reserve($execution, $attempt);
                $clientRequestId = "fish-parser-{$execution->id}-{$attempt}-".Str::uuid();
                $reservation->forceFill(['client_request_id' => $clientRequestId])->save();
                $response = $this->provider->parse($document, $catalog, $targetDate, $clientRequestId);
                [$cost, $costBasis, $pricingFailure] = $this->price($response, $reservation);
                $this->recordProviderResponse($reservation, $response, $cost, $costBasis);
                $this->budget->settle($reservation, $cost, $costBasis);
                $this->syncExecutionTotals($execution);
                if ($pricingFailure instanceof Throwable) {
                    throw $pricingFailure;
                }
                $successfulAttempt = $attempt;

                break;
            } catch (AiParserProviderResponseException $exception) {
                $response = $exception->providerResponse;
                [$cost, $costBasis] = $this->price($response, $reservation);
                $httpFailure = $response->httpStatus >= 400;
                $this->recordProviderResponse(
                    $reservation,
                    $response,
                    $cost,
                    $costBasis,
                    $httpFailure ? 'provider_request' : 'provider_response',
                    $httpFailure ? 'provider_request_failure' : 'provider_response_validation',
                    $exception->getMessage(),
                );
                $this->budget->settle($reservation, $cost, $costBasis);
                $this->syncExecutionTotals($execution);

                if ($attempt < 2 && $this->isRetryableHttpStatus($response->httpStatus)) {
                    Sleep::usleep(500_000);

                    continue;
                }

                throw $exception;
            } catch (Throwable $throwable) {
                if ($reservation instanceof AiBudgetReservation) {
                    if ($response instanceof AiParserProviderResponseData || $throwable instanceof UnexpectedValueException) {
                        $reservation->forceFill([
                            'failure_stage' => 'provider_response',
                            'failure_category' => 'provider_response_validation',
                            'failure_message' => $this->safeFailureMessage($throwable),
                            'actual_micros' => $reservation->reserved_micros,
                            'cost_basis' => AiParserAttemptCostBasis::EstimatedConservative,
                            'cost_calculation_version' => 'reservation-upper-bound-v1',
                            'pricing_snapshot' => $this->costCalculator->pricingSnapshot(),
                            'response_received_at' => now(),
                        ])->save();
                        $this->budget->settle(
                            $reservation,
                            $reservation->reserved_micros,
                            AiParserAttemptCostBasis::EstimatedConservative,
                        );
                    } else {
                        $this->recordTransportFailure($reservation, $throwable);
                        $this->budget->release($reservation);
                    }
                    $this->syncExecutionTotals($execution);
                }

                if ($attempt === 2 || ! $this->isRetryable($throwable)) {
                    throw $throwable;
                }

                Sleep::usleep(500_000);
            }
        }

        if (! $response instanceof AiParserProviderResponseData) {
            throw new UnexpectedValueException('AI primary parsing exhausted its attempts.');
        }

        $response = new AiParserProviderResponseData(
            responseId: $response->responseId,
            requestId: $response->requestId,
            httpStatus: $response->httpStatus,
            status: $response->status,
            incompleteReason: $response->incompleteReason,
            model: $response->model,
            serviceTier: $response->serviceTier,
            responseBodyHash: $response->responseBodyHash,
            outputExcerpt: $response->outputExcerpt,
            result: $response->result,
            usageAvailable: $response->usageAvailable,
            inputTokens: $response->inputTokens,
            cachedInputTokens: $response->cachedInputTokens,
            cacheWriteTokens: $response->cacheWriteTokens,
            outputTokens: $response->outputTokens,
            reasoningTokens: $response->reasoningTokens,
            totalTokens: $response->totalTokens,
            attempts: $successfulAttempt,
            latencyMs: $response->latencyMs,
            errorCode: $response->errorCode,
            errorType: $response->errorType,
        );
        if (! is_array($response->result)) {
            throw new UnexpectedValueException('The AI parser response omitted a validated result.');
        }
        $parsed = $this->collectionFactory->make($payload, $rawPayload, $document, $response->result, $catalog);
        if ($parsed->tripReports->isEmpty() && $deterministic?->tripReports->isNotEmpty()) {
            throw new UnexpectedValueException('The AI parser returned no reports while the deterministic parser found reports.');
        }
        $comparison = $deterministic === null
            ? [
                'status' => 'deterministic_failed',
                'missing_from_ai' => [],
                'extra_in_ai' => [],
                'differences' => [],
                'summary' => ['missing_reports' => 0, 'extra_reports' => 0, 'different_reports' => 0],
            ]
            : $this->comparator->compare($parsed, $deterministic, $catalog);

        return new AiPrimaryParseResult(
            parsed: $parsed,
            comparison: $comparison,
            providerResponse: $response,
            sanitizedInputHash: hash('sha256', $document),
            catalogVersion: $catalogVersion,
            costMicros: $execution->refresh()->cost_micros,
        );
    }

    /**
     * @return array{int, AiParserAttemptCostBasis, ?Throwable}
     */
    private function price(
        AiParserProviderResponseData $response,
        AiBudgetReservation $reservation,
    ): array {
        if (! $response->usageAvailable) {
            if ($response->httpStatus >= 400) {
                return [
                    0,
                    AiParserAttemptCostBasis::None,
                    null,
                ];
            }

            return [
                $reservation->reserved_micros,
                AiParserAttemptCostBasis::EstimatedConservative,
                null,
            ];
        }

        try {
            return [
                $this->costCalculator->calculate($response),
                AiParserAttemptCostBasis::Metered,
                null,
            ];
        } catch (Throwable $throwable) {
            return [
                $reservation->reserved_micros,
                AiParserAttemptCostBasis::EstimatedConservative,
                $throwable,
            ];
        }
    }

    private function recordProviderResponse(
        AiBudgetReservation $reservation,
        AiParserProviderResponseData $response,
        int $costMicros,
        AiParserAttemptCostBasis $costBasis,
        ?string $failureStage = null,
        ?string $failureCategory = null,
        ?string $failureMessage = null,
    ): void {
        $reservation->forceFill([
            'provider_request_id' => $response->requestId,
            'provider_response_id' => $response->responseId,
            'provider_http_status' => $response->httpStatus,
            'provider_status' => $response->status,
            'provider_incomplete_reason' => $response->incompleteReason,
            'model' => $response->model,
            'service_tier' => $response->serviceTier,
            'provider_response_body_hash' => $response->responseBodyHash,
            'provider_output_excerpt' => $response->outputExcerpt,
            'provider_error_code' => $response->errorCode,
            'provider_error_type' => $response->errorType,
            'failure_stage' => $failureStage,
            'failure_category' => $failureCategory,
            'failure_message' => $failureMessage === null ? null : $this->safeFailureMessageText($failureMessage),
            'input_tokens' => $response->inputTokens,
            'cached_input_tokens' => $response->cachedInputTokens,
            'cache_write_tokens' => $response->cacheWriteTokens,
            'output_tokens' => $response->outputTokens,
            'reasoning_tokens' => $response->reasoningTokens,
            'total_tokens' => $response->totalTokens,
            'latency_ms' => $response->latencyMs,
            'actual_micros' => $costMicros,
            'cost_basis' => $costBasis,
            'cost_calculation_version' => $costBasis === AiParserAttemptCostBasis::Metered
                ? AiParserUsageCostCalculator::VERSION
                : 'reservation-upper-bound-v1',
            'pricing_snapshot' => $this->costCalculator->pricingSnapshot(),
            'response_received_at' => now(),
        ])->save();
    }

    private function recordTransportFailure(
        AiBudgetReservation $reservation,
        Throwable $throwable,
    ): void {
        $response = $throwable instanceof RequestException ? $throwable->response : null;
        $requestId = $response?->header('x-request-id');
        $errorCode = $response?->json('error.code');
        $errorType = $response?->json('error.type');
        $timeout = $this->isTimeout($throwable);
        $reservation->forceFill([
            'provider_request_id' => is_string($requestId) ? Str::limit($requestId, 100, '') : null,
            'provider_http_status' => $response?->status(),
            'failure_stage' => $throwable instanceof ConnectionException ? 'provider_transport' : 'provider_request',
            'failure_category' => $timeout
                ? 'timeout'
                : ($throwable instanceof ConnectionException ? 'connection_failure' : 'provider_request_failure'),
            'failure_message' => $this->safeFailureMessage($throwable),
            'provider_error_code' => is_string($errorCode) ? Str::limit($errorCode, 100, '') : null,
            'provider_error_type' => is_string($errorType) ? Str::limit($errorType, 100, '') : null,
        ])->save();
    }

    private function syncExecutionTotals(ParserExecution $execution): void
    {
        $attempts = AiBudgetReservation::query()
            ->where('parser_execution_id', $execution->id)
            ->orderBy('attempt_number')
            ->get();
        $latestResponse = $attempts->whereNotNull('response_received_at')->last();
        $latestAttempt = $attempts->last();

        $execution->update([
            'attempts' => $attempts->count(),
            'provider_response_id' => $latestResponse?->provider_response_id,
            'provider_request_id' => $latestResponse?->provider_request_id ?? $latestAttempt?->provider_request_id,
            'provider_http_status' => $latestResponse?->provider_http_status ?? $latestAttempt?->provider_http_status,
            'provider_status' => $latestResponse?->provider_status,
            'provider_incomplete_reason' => $latestResponse?->provider_incomplete_reason,
            'provider_error_code' => $latestAttempt?->provider_error_code,
            'provider_error_type' => $latestAttempt?->provider_error_type,
            'provider_response_body_hash' => $latestResponse?->provider_response_body_hash,
            'provider_output_excerpt' => $latestResponse?->provider_output_excerpt,
            'model' => $latestResponse?->model ?? $execution->model,
            'service_tier' => $latestResponse?->service_tier ?? $execution->service_tier,
            'input_tokens' => $attempts->sum('input_tokens'),
            'cached_input_tokens' => $attempts->sum('cached_input_tokens'),
            'cache_write_tokens' => $attempts->sum('cache_write_tokens'),
            'output_tokens' => $attempts->sum('output_tokens'),
            'reasoning_tokens' => $attempts->sum('reasoning_tokens'),
            'total_tokens' => $attempts->sum('total_tokens'),
            'cost_micros' => $attempts->sum(fn (AiBudgetReservation $attempt): int => $attempt->actual_micros ?? 0),
            'cost_is_estimated' => $attempts->contains(
                fn (AiBudgetReservation $attempt): bool => in_array(
                    $attempt->cost_basis,
                    [AiParserAttemptCostBasis::EstimatedConservative, AiParserAttemptCostBasis::Unknown],
                    true,
                ),
            ),
            'latency_ms' => $attempts->whereNotNull('latency_ms')->sum('latency_ms') ?: null,
        ]);
    }

    private function safeFailureMessage(Throwable $throwable): string
    {
        if ($throwable instanceof RequestException) {
            return "The AI provider returned HTTP {$throwable->response->status()}.";
        }
        if ($throwable instanceof ConnectionException) {
            $prefix = $this->isTimeout($throwable)
                ? 'The AI provider request timed out.'
                : 'The AI provider connection failed.';

            return $this->safeFailureMessageText("{$prefix} {$throwable->getMessage()}");
        }

        return $this->safeFailureMessageText($throwable->getMessage());
    }

    private function safeFailureMessageText(string $message): string
    {
        $redacted = preg_replace('/(?:sk-[A-Za-z0-9_-]+|Bearer\s+\S+)/i', '[redacted]', $message)
            ?? 'AI primary parsing failed.';

        return Str::limit($redacted, 1000, '');
    }

    private function acquireRateLimit(): void
    {
        $key = 'ai-primary-parsing:openai';
        $rateLimiter = new RateLimiter(Cache::store('database'));
        if ($rateLimiter->tooManyAttempts($key, (int) config('fish.ai_parsing.rate_limit_per_minute'))) {
            throw new AiParserRateLimitExceededException($rateLimiter->availableIn($key));
        }
        $rateLimiter->hit($key, 60);
    }

    private function isRetryable(Throwable $throwable): bool
    {
        if ($throwable instanceof ConnectionException) {
            return true;
        }

        return $throwable instanceof RequestException
            && $this->isRetryableHttpStatus($throwable->response->status());
    }

    private function isRetryableHttpStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    private function isTimeout(Throwable $throwable): bool
    {
        return $throwable instanceof ConnectionException
            && preg_match('/(?:cURL error 28|timed?\s*out|timeout)/iu', $throwable->getMessage()) === 1;
    }
}
