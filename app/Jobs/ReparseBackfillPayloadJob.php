<?php

namespace App\Jobs;

use App\Actions\Parsing\ParseRawPayloadAction;
use App\DTOs\ParseRawPayloadOptions;
use App\Enums\BackfillReparseRunStatus;
use App\Enums\ParserEngine;
use App\Exceptions\AiParserRateLimitExceededException;
use App\Models\BackfillReparseRun;
use App\Models\ParserExecution;
use App\Models\RawScrapePayload;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReparseBackfillPayloadJob implements ShouldBeUnique, ShouldQueue
{
    use FoundationQueueable;

    public int $tries = 3;

    public int $timeout = 90;

    public int $uniqueFor = 86_400;

    public int $maxExceptions = 3;

    public bool $failOnTimeout = false;

    public ?ParserEngine $parserEngine = null;

    public function __construct(
        public int $backfillReparseRunId,
        public int $rawScrapePayloadId,
        ?ParserEngine $parserEngine = null,
    ) {
        $this->parserEngine = $parserEngine;
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
        return $this->backfillReparseRunId.':'.$this->rawScrapePayloadId;
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

    /**
     * Execute the job.
     */
    public function handle(ParseRawPayloadAction $parseRawPayload): void
    {
        $reparseRun = BackfillReparseRun::query()->findOrFail($this->backfillReparseRunId);

        if ($reparseRun->status !== BackfillReparseRunStatus::Running) {
            return;
        }

        try {
            $parseRawPayload->handleWithOptions(
                $this->rawScrapePayloadId,
                new ParseRawPayloadOptions(
                    parserEngine: isset($this->parserEngine) && $this->parserEngine instanceof ParserEngine
                        ? $this->parserEngine
                        : ParserEngine::Deterministic,
                    executionKey: "backfill:{$this->backfillReparseRunId}:{$this->rawScrapePayloadId}",
                ),
            );
        } catch (AiParserRateLimitExceededException $exception) {
            if (isset($this->job) && $this->job instanceof SyncJob) {
                throw $exception;
            }

            $this->release(max(1, $exception->retryAfterSeconds));

            return;
        }

        BackfillReparseRun::query()
            ->whereKey($this->backfillReparseRunId)
            ->increment('completed_payloads');

        $this->finalizeIfComplete();
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
            'failure_message' => str($throwable->getMessage())->limit(1000, '')->toString(),
            'failed_at' => now(),
        ]);

        RawScrapePayload::query()
            ->whereKey($this->rawScrapePayloadId)
            ->update(['error_message' => str($throwable->getMessage())->limit(1000)->toString()]);

        BackfillReparseRun::query()
            ->whereKey($this->backfillReparseRunId)
            ->increment('failed_payloads');

        BackfillReparseRun::query()
            ->whereKey($this->backfillReparseRunId)
            ->update(['error_message' => str($throwable->getMessage())->limit(1000)->toString()]);

        $this->finalizeIfComplete();
    }

    private function finalizeIfComplete(): void
    {
        DB::transaction(function (): void {
            $reparseRun = BackfillReparseRun::query()
                ->lockForUpdate()
                ->findOrFail($this->backfillReparseRunId);

            if ($reparseRun->status !== BackfillReparseRunStatus::Running) {
                return;
            }

            if (($reparseRun->completed_payloads + $reparseRun->failed_payloads) < $reparseRun->queued_payloads) {
                return;
            }

            $reparseRun->update([
                'status' => $reparseRun->failed_payloads > 0 ? BackfillReparseRunStatus::Failed : BackfillReparseRunStatus::Succeeded,
                'finished_at' => now(),
                'error_message' => $reparseRun->failed_payloads > 0 ? "{$reparseRun->failed_payloads} payload(s) failed to reparse." : null,
            ]);
        });
    }
}
