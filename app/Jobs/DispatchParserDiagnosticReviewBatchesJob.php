<?php

namespace App\Jobs;

use App\Enums\ParserDiagnosticReviewRunStatus;
use App\Models\ParserDiagnosticReviewRun;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Throwable;

class DispatchParserDiagnosticReviewBatchesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public int $rawScrapePayloadId,
        public ?int $parserDiagnosticReviewRunId = null,
    ) {
        $this->onConnection('database');
        $this->onQueue('ai-parsing');
    }

    public function uniqueId(): string
    {
        return $this->parserDiagnosticReviewRunId === null
            ? (string) $this->rawScrapePayloadId
            : "{$this->rawScrapePayloadId}:review-run:{$this->parserDiagnosticReviewRunId}";
    }

    public function uniqueFor(): int
    {
        return max(300, (int) config('fish.ai_review.retry_window_minutes') * 60);
    }

    public function uniqueVia(): Repository
    {
        return cache()->store('database');
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(): void
    {
        $reviewRun = $this->reviewRun();

        if ($this->parserDiagnosticReviewRunId !== null && ($reviewRun === null || ! $reviewRun->status->isActive())) {
            return;
        }

        if (! $this->enabled()) {
            $reviewRun?->markFailed('AI review dispatch is no longer available.');

            return;
        }

        if (! RawScrapePayload::query()->whereKey($this->rawScrapePayloadId)->exists()) {
            $reviewRun?->markFailed('The raw payload is no longer available for AI review.');

            return;
        }

        $fingerprints = ParserError::query()
            ->where('raw_scrape_payload_id', $this->rawScrapePayloadId)
            ->whereNull('resolution_type')
            ->whereNotNull('diagnostic_fingerprint')
            ->orderBy('id')
            ->pluck('diagnostic_fingerprint')
            ->unique()
            ->values();

        if ($fingerprints->isEmpty()) {
            $reviewRun?->markCompleted();

            return;
        }

        $reviewRun?->markRunning();
        $chunks = $fingerprints
            ->chunk(max(1, (int) config('fish.ai_review.limits.max_diagnostics_per_request')))
            ->values();
        $jobs = $chunks->map(fn ($chunk, int $index): ReviewParserDiagnosticsJob => new ReviewParserDiagnosticsJob(
            rawScrapePayloadId: $this->rawScrapePayloadId,
            parserDiagnosticReviewRunId: $this->parserDiagnosticReviewRunId,
            diagnosticFingerprints: $chunk->values()->all(),
            finalizeParserDiagnosticReviewRun: $index === $chunks->count() - 1,
        ));

        Bus::chain($jobs->all())
            ->onConnection('database')
            ->onQueue('ai-parsing')
            ->dispatch();
    }

    public function failed(?Throwable $throwable): void
    {
        $this->reviewRun()?->markFailed($throwable ?? 'The AI review batches could not be queued.');
    }

    private function reviewRun(): ?ParserDiagnosticReviewRun
    {
        if ($this->parserDiagnosticReviewRunId === null) {
            return null;
        }

        return ParserDiagnosticReviewRun::query()
            ->whereKey($this->parserDiagnosticReviewRunId)
            ->where('raw_scrape_payload_id', $this->rawScrapePayloadId)
            ->whereIn('status', ParserDiagnosticReviewRunStatus::activeValues())
            ->first();
    }

    private function enabled(): bool
    {
        return (bool) config('fish.ai_review.enabled')
            && (bool) config('fish.ai_review.dispatch_enabled')
            && filled(config('services.openai.api_key'));
    }
}
