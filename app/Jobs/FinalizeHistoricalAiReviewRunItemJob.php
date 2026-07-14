<?php

namespace App\Jobs;

use App\Services\AI\HistoricalAiReviewRunItemFinalizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class FinalizeHistoricalAiReviewRunItemJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $historicalAiReviewRunItemId)
    {
        $this->onConnection('database');
        $this->onQueue('ai-parsing');
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(HistoricalAiReviewRunItemFinalizer $finalizer): void
    {
        $finalizer->complete($this->historicalAiReviewRunItemId);
    }

    public function failed(?Throwable $throwable): void
    {
        app(HistoricalAiReviewRunItemFinalizer::class)->fail(
            $this->historicalAiReviewRunItemId,
            $throwable ?? 'The historical AI review item could not be finalized.',
        );
    }
}
