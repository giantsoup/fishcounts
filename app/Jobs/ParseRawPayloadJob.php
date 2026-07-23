<?php

namespace App\Jobs;

use App\Actions\Parsing\ParseRawPayloadAction;
use App\DTOs\ParseRawPayloadOptions;
use App\Enums\ParserDiagnosticReviewRunStatus;
use App\Enums\ParserEngine;
use App\Exceptions\AiParserRateLimitExceededException;
use App\Models\ParserDiagnosticReviewRun;
use App\Models\ParserExecution;
use App\Models\RawScrapePayload;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ParseRawPayloadJob implements ShouldBeUnique, ShouldQueue
{
    use FoundationQueueable;

    public int $tries = 3;

    public int $timeout = 90;

    public int $uniqueFor = 86_400;

    public int $maxExceptions = 3;

    public bool $failOnTimeout = false;

    public ?ParserEngine $parserEngine = null;

    public ?string $executionKey = null;

    public function __construct(
        public int $rawScrapePayloadId,
        public bool $shouldDispatchDeduplication = true,
        public ?int $parserDiagnosticReviewRunId = null,
        ?ParserEngine $parserEngine = null,
        ?string $executionKey = null,
    ) {
        $this->parserEngine = $parserEngine;
        $this->executionKey = $executionKey ?? (string) str()->uuid();
        if ($parserEngine === ParserEngine::Ai) {
            $this->timeout = (int) config('fish.ai_parsing.job_timeout_seconds');
            $this->maxExceptions = 1;
            $this->failOnTimeout = true;
            $this->onConnection('database');
        }
        $this->onQueue($parserEngine === ParserEngine::Ai ? 'ai-primary-parsing' : 'parsing');
    }

    public function uniqueVia(): Repository
    {
        return Cache::store('database');
    }

    public function uniqueId(): string
    {
        return $this->parserDiagnosticReviewRunId === null
            ? (string) $this->rawScrapePayloadId
            : "{$this->rawScrapePayloadId}:review-run:{$this->parserDiagnosticReviewRunId}";
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function tries(): int
    {
        return isset($this->parserEngine) && $this->parserEngine === ParserEngine::Ai ? 0 : $this->tries;
    }

    public function handle(ParseRawPayloadAction $parseRawPayload): void
    {
        if ($this->parserDiagnosticReviewRunId !== null) {
            $reviewRun = ParserDiagnosticReviewRun::query()
                ->whereKey($this->parserDiagnosticReviewRunId)
                ->where('raw_scrape_payload_id', $this->rawScrapePayloadId)
                ->first();

            if ($reviewRun?->status !== ParserDiagnosticReviewRunStatus::Preparing) {
                return;
            }
        }

        try {
            $parseRawPayload->handleWithOptions(
                $this->rawScrapePayloadId,
                new ParseRawPayloadOptions(
                    dispatchDeduplication: $this->shouldDispatchDeduplication,
                    parserDiagnosticReviewRunId: $this->parserDiagnosticReviewRunId,
                    parserEngine: isset($this->parserEngine) && $this->parserEngine instanceof ParserEngine
                        ? $this->parserEngine
                        : ParserEngine::Deterministic,
                    executionKey: isset($this->executionKey) ? $this->executionKey : null,
                ),
            );
        } catch (AiParserRateLimitExceededException $exception) {
            if (isset($this->job) && $this->job instanceof SyncJob) {
                throw $exception;
            }

            $this->release(max(1, $exception->retryAfterSeconds));
        }
    }

    public function failed(Throwable $throwable): void
    {
        $executionId = ParserExecution::query()
            ->where('raw_scrape_payload_id', $this->rawScrapePayloadId)
            ->whereIn('status', ['running', 'ready'])
            ->latest('id')
            ->value('id');
        ParserExecution::query()->whereKey($executionId)->update([
            'status' => 'failed',
            'failure_category' => 'job_failure',
            'failure_stage' => 'job',
            'failure_message' => str($throwable->getMessage())->limit(1000, '')->toString(),
            'failed_at' => now(),
        ]);

        if ($this->parserDiagnosticReviewRunId !== null) {
            ParserDiagnosticReviewRun::query()
                ->whereKey($this->parserDiagnosticReviewRunId)
                ->where('raw_scrape_payload_id', $this->rawScrapePayloadId)
                ->first()
                ?->markFailed($throwable);
        }

        RawScrapePayload::query()
            ->whereKey($this->rawScrapePayloadId)
            ->update(['error_message' => str($throwable->getMessage())->limit(1000)->toString()]);
    }
}
