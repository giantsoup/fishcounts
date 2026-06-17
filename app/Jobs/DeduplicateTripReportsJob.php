<?php

namespace App\Jobs;

use App\Services\Parsing\TripReportNormalizer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Throwable;

class DeduplicateTripReportsJob implements ShouldBeUnique, ShouldQueue
{
    use FoundationQueueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public string $date)
    {
        $this->onQueue('parsing');
    }

    public function uniqueId(): string
    {
        return $this->date;
    }

    public function handle(TripReportNormalizer $normalizer): void
    {
        $normalizer->refreshPrimaryReports($this->date);
    }

    public function failed(Throwable $throwable): void
    {
        report($throwable);
    }
}
