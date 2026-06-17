<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;

class NormalizeTripReportsJob implements ShouldQueue
{
    use FoundationQueueable;

    public int $tries = 1;

    public function __construct(public int $rawScrapePayloadId)
    {
        $this->onQueue('parsing');
    }

    public function handle(): void
    {
        ParseRawPayloadJob::dispatchSync($this->rawScrapePayloadId);
    }
}
