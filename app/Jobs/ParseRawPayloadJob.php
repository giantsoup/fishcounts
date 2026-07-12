<?php

namespace App\Jobs;

use App\Actions\Parsing\ParseRawPayloadAction;
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
    ) {
        $this->onQueue('parsing');
    }

    public function uniqueId(): string
    {
        return (string) $this->rawScrapePayloadId;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(ParseRawPayloadAction $parseRawPayload): void
    {
        $parseRawPayload->handle($this->rawScrapePayloadId, $this->shouldDispatchDeduplication);
    }

    public function failed(Throwable $throwable): void
    {
        RawScrapePayload::query()
            ->whereKey($this->rawScrapePayloadId)
            ->update(['error_message' => str($throwable->getMessage())->limit(1000)->toString()]);
    }
}
