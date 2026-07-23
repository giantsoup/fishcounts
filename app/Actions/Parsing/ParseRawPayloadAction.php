<?php

namespace App\Actions\Parsing;

use App\DTOs\ParsedFishCountCollection;
use App\DTOs\ParsedTripReportData;
use App\DTOs\ParseRawPayloadOptions;
use App\DTOs\ParseRawPayloadResult;
use App\Enums\ParserEngine;
use App\Exceptions\AiParserProviderResponseException;
use App\Exceptions\AiParserRateLimitExceededException;
use App\Jobs\DeduplicateTripReportsJob;
use App\Jobs\DispatchParserDiagnosticReviewBatchesJob;
use App\Models\AiBudgetReservation;
use App\Models\ParserDiagnosticReviewRun;
use App\Models\ParserError;
use App\Models\ParserExecution;
use App\Models\RawScrapePayload;
use App\Services\Parsing\AiPrimaryParser;
use App\Services\Parsing\ParsedCollectionSnapshot;
use App\Services\Parsing\ParsedReportValidator;
use App\Services\Parsing\ParserReportOverrideApplier;
use App\Services\Parsing\RawPayloadEvaluator;
use App\Services\Parsing\TripReportNormalizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ParseRawPayloadAction
{
    public function __construct(
        private readonly RawPayloadEvaluator $evaluator,
        private readonly TripReportNormalizer $normalizer,
        private readonly ParsedReportValidator $validator,
        private readonly ParserReportOverrideApplier $overrideApplier,
        private readonly AiPrimaryParser $aiParser,
        private readonly ParsedCollectionSnapshot $snapshot,
    ) {}

    public function handle(
        int $rawScrapePayloadId,
        bool $shouldDispatchDeduplication = true,
        ?int $parserDiagnosticReviewRunId = null,
    ): ParseRawPayloadResult {
        $parserEngine = RawScrapePayload::query()
            ->with('scrapeSource:id,parser_engine')
            ->findOrFail($rawScrapePayloadId)
            ->scrapeSource
            ->parser_engine;

        return $this->handleWithOptions(
            $rawScrapePayloadId,
            new ParseRawPayloadOptions(
                dispatchDeduplication: $shouldDispatchDeduplication,
                parserDiagnosticReviewRunId: $parserDiagnosticReviewRunId,
                parserEngine: $parserEngine,
            ),
        );
    }

    public function handleWithOptions(int $rawScrapePayloadId, ParseRawPayloadOptions $options): ParseRawPayloadResult
    {
        $payload = RawScrapePayload::query()->findOrFail($rawScrapePayloadId);

        return Cache::store('database')->lock(
            "parser-source-date:{$payload->scrape_source_id}:{$payload->target_date->toDateString()}",
            (int) config('fish.ai_parsing.lock_seconds'),
        )->block(5, fn (): ParseRawPayloadResult => $this->performWithOptions($rawScrapePayloadId, $options));
    }

    private function performWithOptions(int $rawScrapePayloadId, ParseRawPayloadOptions $options): ParseRawPayloadResult
    {
        [$payload, $rawPayload] = $this->evaluator->load($rawScrapePayloadId);
        $this->assertNewestPayload($payload);
        $execution = $this->execution($payload, $options);

        if ($execution->status === 'completed' && $payload->authoritative_parser_execution_id === $execution->id) {
            $diagnosticCount = $payload->parserErrors()->whereNull('resolution_type')->count();
            $this->dispatchDownstream(
                $payload,
                $options,
                $diagnosticCount,
                $execution->selected_engine ?? ParserEngine::Deterministic,
                $execution,
            );

            return new ParseRawPayloadResult(
                rawScrapePayloadId: $payload->id,
                parserVersion: $payload->parser_version ?? 'unknown',
                parsedReportCount: $payload->tripReports()->count(),
                diagnosticCount: $diagnosticCount,
                shouldDispatchDeduplication: $options->dispatchDeduplication,
            );
        }

        $deterministic = null;
        $deterministicFailure = null;
        try {
            $deterministic = $this->evaluator->parseDeterministically($payload, $rawPayload);
            $execution->update(['deterministic_snapshot' => $this->snapshot->make($deterministic)]);
        } catch (Throwable $throwable) {
            $deterministicFailure = $throwable;
        }

        $selectedEngine = ParserEngine::Deterministic;
        $parsed = $deterministic;
        $aiResult = null;
        $fallbackCategory = null;

        if ($options->parserEngine === ParserEngine::Ai
            && $execution->status === 'ready'
            && is_array($execution->ai_snapshot)) {
            $parsed = $this->snapshot->restore($execution->ai_snapshot);
            $selectedEngine = ParserEngine::Ai;
        } elseif ($options->parserEngine === ParserEngine::Ai && $execution->status !== 'failed') {
            try {
                $aiResult = $this->aiParser->parse($execution, $payload, $rawPayload, $deterministic);
                $parsed = $aiResult->parsed;
                $selectedEngine = ParserEngine::Ai;
                $execution->update([
                    'selected_engine' => ParserEngine::Ai,
                    'status' => 'ready',
                    'sanitized_input_hash' => $aiResult->sanitizedInputHash,
                    'catalog_version' => $aiResult->catalogVersion,
                    'ai_snapshot' => $this->snapshot->make($parsed),
                    'comparison' => $aiResult->comparison,
                    'comparison_status' => $aiResult->comparison['status'],
                ]);
            } catch (AiParserRateLimitExceededException $exception) {
                throw $exception;
            } catch (Throwable $throwable) {
                $fallbackCategory = $this->failureCategory($throwable);
                $fallbackStage = $this->failureStage($fallbackCategory);
                $fallbackMessage = $this->safeFailureMessage($throwable);
                $execution->update([
                    'selected_engine' => $deterministic === null ? null : ParserEngine::Deterministic,
                    'fallback_category' => $fallbackCategory,
                    'fallback_stage' => $fallbackStage,
                    'fallback_message' => $fallbackMessage,
                    'failure_category' => null,
                    'failure_stage' => null,
                    'failure_message' => null,
                    'attempts' => AiBudgetReservation::query()->where('parser_execution_id', $execution->id)->count(),
                    'cost_micros' => (int) AiBudgetReservation::query()->where('parser_execution_id', $execution->id)->sum('actual_micros'),
                    ...$this->providerFailureMetadata($throwable),
                ]);
                AiBudgetReservation::query()
                    ->where('parser_execution_id', $execution->id)
                    ->latest('attempt_number')
                    ->first()
                    ?->update([
                        'failure_stage' => $fallbackStage,
                        'failure_category' => $fallbackCategory,
                        'failure_message' => $fallbackMessage,
                    ]);
            }
        } elseif ($options->parserEngine === ParserEngine::Ai) {
            $fallbackCategory = $execution->failure_category ?? $execution->fallback_category ?? 'attempts_exhausted';
        }

        if (! $parsed instanceof ParsedFishCountCollection) {
            $failure = $deterministicFailure ?? new RuntimeException('Both parser engines failed.');
            $execution->update([
                'status' => 'failed',
                'failure_category' => $fallbackCategory ?? 'deterministic_failure',
                'failure_stage' => $this->failureStage($fallbackCategory ?? 'deterministic_failure'),
                'failure_message' => $this->safeFailureMessage($failure),
                'failed_at' => now(),
            ]);

            throw $failure;
        }

        if ($selectedEngine === ParserEngine::Deterministic) {
            $parsed = $this->overrideApplier->apply($payload, $rawPayload, $parsed);
            $execution->update(['deterministic_snapshot' => $this->snapshot->make($parsed)]);
        }
        $parsed = $this->withExecutionMetadata($parsed, $execution, $selectedEngine);

        try {
            [$payload, $parsed, $parsedReportCount, $diagnosticCount] = DB::transaction(function () use ($payload, $rawPayload, $parsed, $execution, $selectedEngine, $fallbackCategory): array {
                $lockedPayload = RawScrapePayload::query()->with('scrapeSource')->lockForUpdate()->findOrFail($payload->id);
                if (! hash_equals($payload->payload_hash, $lockedPayload->payload_hash)) {
                    throw new RuntimeException('The raw payload changed while it was being parsed.');
                }

                $this->assertNewestPayload($lockedPayload);

                $diagnostics = $this->validator->validate($lockedPayload, $rawPayload, $parsed);
                $parsedReportCount = $this->normalizer->replaceForPayload($lockedPayload, $parsed, $diagnostics);
                $lockedPayload->update(['authoritative_parser_execution_id' => $execution->id]);
                $diagnosticCount = ParserError::query()
                    ->where('raw_scrape_payload_id', $lockedPayload->id)
                    ->whereNull('resolution_type')
                    ->count();
                $execution->update([
                    'selected_engine' => $selectedEngine,
                    'status' => 'completed',
                    'parser_version' => $parsed->parserVersion,
                    'fallback_category' => $fallbackCategory,
                    'failure_category' => null,
                    'failure_stage' => null,
                    'failure_message' => null,
                    'completed_at' => now(),
                ]);

                return [$lockedPayload, $parsed, $parsedReportCount, $diagnosticCount];
            }, attempts: 3);
        } catch (Throwable $throwable) {
            $execution->update([
                'status' => $selectedEngine === ParserEngine::Ai ? 'ready' : 'failed',
                'failure_category' => $selectedEngine === ParserEngine::Ai ? 'persistence_failure' : $this->failureCategory($throwable),
                'failure_stage' => 'persistence',
                'failure_message' => $this->safeFailureMessage($throwable),
                'failed_at' => now(),
            ]);

            throw $throwable;
        }
        $parserVersion = $parsed->tripReports->first()?->metadata['parser'] ?? $parsed->parserVersion ?? 'unknown';

        $this->dispatchDownstream($payload, $options, $diagnosticCount, $selectedEngine, $execution);

        return new ParseRawPayloadResult(
            rawScrapePayloadId: $payload->id,
            parserVersion: $parserVersion,
            parsedReportCount: $parsedReportCount,
            diagnosticCount: $diagnosticCount,
            shouldDispatchDeduplication: $options->dispatchDeduplication,
        );
    }

    private function execution(RawScrapePayload $payload, ParseRawPayloadOptions $options): ParserExecution
    {
        $idempotencySource = $options->executionKey ?? (string) Str::uuid();
        $idempotencyKey = hash('sha256', implode('|', [
            $idempotencySource,
            $payload->id,
            $payload->payload_hash,
            $options->parserEngine->value,
            config('fish.ai_parsing.model'),
            config('fish.ai_parsing.service_tier'),
            config('fish.ai_parsing.reasoning_effort'),
            config('fish.ai_parsing.prompt_version'),
            config('fish.ai_parsing.schema_version'),
            config('fish.ai_parsing.sanitizer_version'),
            config('fish.ai_parsing.catalog_version'),
        ]));

        return ParserExecution::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'raw_scrape_payload_id' => $payload->id,
                'scrape_source_id' => $payload->scrape_source_id,
                'requested_engine' => $options->parserEngine,
                'payload_hash' => $payload->payload_hash,
                'provider' => $options->parserEngine === ParserEngine::Ai ? config('fish.ai_parsing.provider') : null,
                'model' => $options->parserEngine === ParserEngine::Ai ? config('fish.ai_parsing.model') : null,
                'service_tier' => $options->parserEngine === ParserEngine::Ai ? config('fish.ai_parsing.service_tier') : null,
                'prompt_version' => (string) config('fish.ai_parsing.prompt_version'),
                'schema_version' => (string) config('fish.ai_parsing.schema_version'),
                'sanitizer_version' => (string) config('fish.ai_parsing.sanitizer_version'),
                'catalog_version' => (string) config('fish.ai_parsing.catalog_version'),
                'started_at' => now(),
            ],
        );
    }

    private function withExecutionMetadata(
        ParsedFishCountCollection $parsed,
        ParserExecution $execution,
        ParserEngine $engine,
    ): ParsedFishCountCollection {
        return new ParsedFishCountCollection(
            tripReports: $parsed->tripReports->map(fn (ParsedTripReportData $report): ParsedTripReportData => new ParsedTripReportData(
                sourceKey: $report->sourceKey,
                tripDate: $report->tripDate,
                regionName: $report->regionName,
                landingName: $report->landingName,
                boatName: $report->boatName,
                tripTypeName: $report->tripTypeName,
                anglers: $report->anglers,
                rawFishCountText: $report->rawFishCountText,
                speciesCounts: $report->speciesCounts,
                metadata: array_merge($report->metadata, [
                    'parser_execution_id' => $execution->id,
                    'parser_engine' => $engine->value,
                ]),
                canonicalBoatId: $report->canonicalBoatId,
                canonicalTripTypeId: $report->canonicalTripTypeId,
            )),
            parserVersion: $parsed->parserVersion,
            format: $parsed->format,
        );
    }

    private function failureCategory(Throwable $throwable): string
    {
        if ($throwable instanceof AiParserProviderResponseException
            && $throwable->providerResponse->httpStatus >= 400) {
            return match ($throwable->providerResponse->httpStatus) {
                401 => 'authentication_failure',
                403 => 'permission_failure',
                429 => 'rate_limit_exhausted',
                default => $throwable->providerResponse->httpStatus >= 500
                    ? 'provider_failure'
                    : 'provider_rejection',
            };
        }
        if ($this->isTimeout($throwable)) {
            return 'timeout';
        }
        if ($throwable instanceof ConnectionException) {
            return 'connection_failure';
        }
        if ($throwable instanceof RequestException) {
            return match ($throwable->response->status()) {
                401 => 'authentication_failure',
                403 => 'permission_failure',
                429 => 'rate_limit_exhausted',
                default => $throwable->response->serverError() ? 'provider_failure' : 'provider_rejection',
            };
        }

        $message = Str::lower($throwable->getMessage());

        return match (true) {
            str_contains($message, 'budget') => 'budget_exhausted',
            str_contains($message, 'rate-limit capacity') => 'rate_limit_guardrail',
            str_contains($message, 'disabled') => 'disabled',
            str_contains($message, 'credential') => 'missing_credentials',
            str_contains($message, 'input limit') => 'input_limit',
            str_contains($message, 'no public fish-count text')
                || str_contains($message, 'could not be sanitized safely') => 'input_validation',
            str_contains($message, 'refused') => 'refusal',
            str_contains($message, 'incomplete') => 'incomplete_output',
            str_contains($message, 'timeout') => 'timeout',
            str_contains($message, 'schema')
                || str_contains($message, 'field')
                || str_contains($message, 'evidence')
                || str_contains($message, 'canonical')
                || str_contains($message, 'duplicate')
                || str_contains($message, 'inactive')
                || str_contains($message, 'unknown')
                || str_contains($message, 'outside')
                || str_contains($message, 'count')
                || str_contains($message, 'report')
                || str_contains($message, 'entity') => 'domain_validation',
            default => 'ai_validation_failure',
        };
    }

    private function failureStage(string $category): string
    {
        return match ($category) {
            'disabled', 'missing_credentials' => 'configuration',
            'budget_exhausted', 'rate_limit_guardrail' => 'guardrail',
            'input_limit', 'input_validation' => 'sanitization',
            'connection_failure', 'timeout' => 'provider_transport',
            'authentication_failure', 'permission_failure', 'rate_limit_exhausted',
            'provider_failure', 'provider_rejection' => 'provider_request',
            'refusal', 'incomplete_output', 'ai_validation_failure' => 'provider_response',
            'domain_validation' => 'domain_validation',
            'persistence_failure' => 'persistence',
            'job_failure' => 'job',
            default => 'application',
        };
    }

    private function assertNewestPayload(RawScrapePayload $payload): void
    {
        $newestPayloadId = RawScrapePayload::query()
            ->where('scrape_source_id', $payload->scrape_source_id)
            ->whereDate('target_date', $payload->target_date)
            ->latest('fetched_at')
            ->latest('id')
            ->value('id');

        if ((int) $newestPayloadId !== $payload->id) {
            throw new RuntimeException('A newer raw payload is authoritative for this source and date.');
        }
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
            $message = preg_replace(
                '/(?:sk-[A-Za-z0-9_-]+|Bearer\s+\S+)/i',
                '[redacted]',
                "{$prefix} {$throwable->getMessage()}",
            ) ?? $prefix;

            return Str::limit($message, 1000, '');
        }

        $message = preg_replace('/(?:sk-[A-Za-z0-9_-]+|Bearer\s+\S+)/i', '[redacted]', $throwable->getMessage())
            ?? 'AI primary parsing failed.';

        return Str::limit($message, 1000, '');
    }

    /** @return array<string, int|string|null> */
    private function providerFailureMetadata(Throwable $throwable): array
    {
        if (! $throwable instanceof RequestException) {
            return [];
        }

        $requestId = $throwable->response->header('x-request-id');
        $errorCode = $throwable->response->json('error.code');
        $errorType = $throwable->response->json('error.type');

        return [
            'provider_http_status' => $throwable->response->status(),
            'provider_request_id' => is_string($requestId) ? Str::limit($requestId, 100, '') : null,
            'provider_error_code' => is_string($errorCode) ? Str::limit($errorCode, 100, '') : null,
            'provider_error_type' => is_string($errorType) ? Str::limit($errorType, 100, '') : null,
        ];
    }

    private function isTimeout(Throwable $throwable): bool
    {
        return $throwable instanceof ConnectionException
            && preg_match('/(?:cURL error 28|timed?\s*out|timeout)/iu', $throwable->getMessage()) === 1;
    }

    private function dispatchDownstream(
        RawScrapePayload $payload,
        ParseRawPayloadOptions $options,
        int $diagnosticCount,
        ParserEngine $selectedEngine,
        ParserExecution $execution,
    ): void {
        if ($execution->downstream_dispatched_at !== null) {
            return;
        }

        if ($options->dispatchDeduplication) {
            DeduplicateTripReportsJob::dispatch($payload->target_date->toDateString())
                ->onConnection((string) config('fish.queues.application_connection'))
                ->afterCommit();
        }

        $reviewRun = $options->parserDiagnosticReviewRunId === null
            ? null
            : ParserDiagnosticReviewRun::query()
                ->whereKey($options->parserDiagnosticReviewRunId)
                ->where('raw_scrape_payload_id', $payload->id)
                ->first();

        if ($selectedEngine !== ParserEngine::Ai && $options->dispatchDiagnosticReviews && $diagnosticCount > 0 && (bool) config('fish.ai_review.dispatch_enabled')) {
            try {
                $reviewRun?->markQueued();
                DispatchParserDiagnosticReviewBatchesJob::dispatch($payload->id, $options->parserDiagnosticReviewRunId)->afterCommit();
            } catch (Throwable $throwable) {
                $reviewRun?->markFailed($throwable);
                report($throwable);
            }
        } elseif ($options->dispatchDiagnosticReviews && $reviewRun !== null && $diagnosticCount === 0) {
            $reviewRun->markCompleted();
        } elseif ($options->dispatchDiagnosticReviews && $reviewRun !== null) {
            $reviewRun->markFailed('AI review dispatch became unavailable after the payload was reparsed.');
        }

        $execution->update(['downstream_dispatched_at' => now()]);
    }
}
