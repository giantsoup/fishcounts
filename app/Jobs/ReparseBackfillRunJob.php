<?php

namespace App\Jobs;

use App\Enums\BackfillReparseRunStatus;
use App\Models\BackfillReparseRun;
use App\Models\RawScrapePayload;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Throwable;

class ReparseBackfillRunJob implements ShouldBeUnique, ShouldQueue
{
    use FoundationQueueable;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $backfillReparseRunId)
    {
        $this->onQueue('parsing');
    }

    public function uniqueId(): string
    {
        return (string) $this->backfillReparseRunId;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $reparseRun = BackfillReparseRun::query()
            ->with('backfillRun')
            ->findOrFail($this->backfillReparseRunId);

        if ($reparseRun->status === BackfillReparseRunStatus::Failed || $reparseRun->status === BackfillReparseRunStatus::Succeeded) {
            return;
        }

        $payloadIds = $reparseRun->backfillRun->items()
            ->whereNotNull('raw_scrape_payload_id')
            ->orderBy('target_date')
            ->orderBy('scrape_source_id')
            ->pluck('raw_scrape_payload_id')
            ->filter()
            ->unique()
            ->values();

        $payloadCount = $payloadIds->count();

        $reparseRun->update([
            'status' => $payloadCount > 0 ? BackfillReparseRunStatus::Running : BackfillReparseRunStatus::Succeeded,
            'total_payloads' => $payloadCount,
            'queued_payloads' => $payloadCount,
            'started_at' => $reparseRun->started_at ?? now(),
            'finished_at' => $payloadCount === 0 ? now() : null,
            'error_message' => null,
        ]);

        RawScrapePayload::query()
            ->select(['id', 'scrape_source_id'])
            ->with('scrapeSource:id,parser_engine')
            ->whereKey($payloadIds)
            ->lazyById(100)
            ->each(fn (RawScrapePayload $payload): mixed => ReparseBackfillPayloadJob::dispatch(
                $reparseRun->id,
                $payload->id,
                $payload->scrapeSource->parser_engine,
            ));
    }

    public function failed(Throwable $throwable): void
    {
        BackfillReparseRun::query()->whereKey($this->backfillReparseRunId)->update([
            'status' => BackfillReparseRunStatus::Failed,
            'finished_at' => now(),
            'error_message' => str($throwable->getMessage())->limit(1000)->toString(),
        ]);
    }
}
