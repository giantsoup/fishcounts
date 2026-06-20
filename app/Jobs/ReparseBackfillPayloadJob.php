<?php

namespace App\Jobs;

use App\Enums\BackfillReparseRunStatus;
use App\Models\BackfillReparseRun;
use App\Models\RawScrapePayload;
use App\Services\Parsing\TripReportNormalizer;
use App\Services\Scraping\SourceAdapterRegistry;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReparseBackfillPayloadJob implements ShouldBeUnique, ShouldQueue
{
    use FoundationQueueable;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $backfillReparseRunId, public int $rawScrapePayloadId)
    {
        $this->onQueue('parsing');
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

    /**
     * Execute the job.
     */
    public function handle(SourceAdapterRegistry $registry, TripReportNormalizer $normalizer): void
    {
        $reparseRun = BackfillReparseRun::query()->findOrFail($this->backfillReparseRunId);

        if ($reparseRun->status !== BackfillReparseRunStatus::Running) {
            return;
        }

        (new ParseRawPayloadJob($this->rawScrapePayloadId))->handle($registry, $normalizer);

        BackfillReparseRun::query()
            ->whereKey($this->backfillReparseRunId)
            ->increment('completed_payloads');

        $this->finalizeIfComplete();
    }

    public function failed(Throwable $throwable): void
    {
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
