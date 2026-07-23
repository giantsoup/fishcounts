<?php

namespace App\Jobs;

use App\Services\Parsing\TripReportNormalizer;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class DeduplicateTripReportsJob implements ShouldBeUnique, ShouldQueue
{
    use FoundationQueueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public string $date)
    {
        $this->onConnection((string) config('fish.queues.application_connection'));
        $this->onQueue('parsing');
    }

    public function uniqueVia(): Repository
    {
        return Cache::store('database');
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
