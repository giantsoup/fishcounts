<?php

namespace App\Jobs;

use App\Actions\Parsing\ParseRawPayloadAction;
use App\Enums\ParserDiagnosticReviewRunStatus;
use App\Models\ParserDiagnosticReviewRun;
use App\Models\RawScrapePayload;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Throwable;

class ParseRawPayloadJob implements ShouldBeUnique, ShouldQueue
{
    use FoundationQueueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public int $rawScrapePayloadId,
        public bool $shouldDispatchDeduplication = true,
        public ?int $parserDiagnosticReviewRunId = null,
    ) {
        $this->onQueue('parsing');
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

        $parseRawPayload->handle(
            $this->rawScrapePayloadId,
            $this->shouldDispatchDeduplication,
            $this->parserDiagnosticReviewRunId,
        );
    }

    public function failed(Throwable $throwable): void
    {
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
