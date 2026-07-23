<?php

namespace App\Jobs;

use App\Enums\ScrapeRunStatus;
use App\Enums\ScrapeRunType;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Services\Scraping\RawPayloadStore;
use App\Services\Scraping\SourceAdapterRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Throwable;

class ScrapeSourceForDateJob implements ShouldBeUnique, ShouldQueue
{
    use FoundationQueueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public int $sourceId,
        public string $date,
        public ScrapeRunType $runType = ScrapeRunType::Daily,
    ) {
        $this->onQueue('scraping');
    }

    public function uniqueId(): string
    {
        return "{$this->sourceId}:{$this->date}:{$this->runType->value}";
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(SourceAdapterRegistry $registry, RawPayloadStore $payloadStore): void
    {
        $source = ScrapeSource::query()->findOrFail($this->sourceId);
        $date = CarbonImmutable::parse($this->date);

        $scrapeRun = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => $this->runType,
            'target_date' => $date,
            'status' => ScrapeRunStatus::Running,
            'started_at' => now(),
        ]);

        try {
            $adapter = $registry->forSource($source);

            if (! $source->is_enabled || ! $adapter->supportsDate($date)) {
                $scrapeRun->update([
                    'status' => ScrapeRunStatus::Cancelled,
                    'finished_at' => now(),
                    'metadata' => ['reason' => 'Source disabled or unsupported date.'],
                ]);

                return;
            }

            $fetchResult = $adapter->fetchForDate($source, $date);
            $payload = $payloadStore->store($scrapeRun, $source, $fetchResult);

            $unavailableReason = $this->unavailableReason($fetchResult->statusCode, $fetchResult->body);

            if ($unavailableReason !== null) {
                $scrapeRun->update([
                    'status' => ScrapeRunStatus::Unavailable,
                    'finished_at' => now(),
                    'metadata' => [
                        'raw_scrape_payload_id' => $payload->id,
                        'reason' => $unavailableReason,
                    ],
                ]);

                return;
            }

            $scrapeRun->update([
                'status' => ScrapeRunStatus::Succeeded,
                'finished_at' => now(),
                'metadata' => ['raw_scrape_payload_id' => $payload->id],
            ]);

            $source->update(['last_success_at' => now()]);

            ParseRawPayloadJob::dispatch(
                rawScrapePayloadId: $payload->id,
                parserEngine: $source->parser_engine,
            );
        } catch (Throwable $throwable) {
            $scrapeRun->update([
                'status' => ScrapeRunStatus::Failed,
                'finished_at' => now(),
                'error_message' => $this->maskedError($throwable),
            ]);

            $source->update(['last_failure_at' => now()]);

            throw $throwable;
        }
    }

    public function failed(Throwable $throwable): void
    {
        ScrapeSource::query()->whereKey($this->sourceId)->update(['last_failure_at' => now()]);
    }

    private function maskedError(Throwable $throwable): string
    {
        return str($throwable->getMessage())->replaceMatches('/https:\/\/discord\.com\/api\/webhooks\/[^\s]+/', 'https://discord.com/api/webhooks/[masked]')->limit(1000)->toString();
    }

    private function unavailableReason(?int $statusCode, string $body): ?string
    {
        if (in_array($statusCode, [204, 404, 410], true)) {
            return "Source returned HTTP {$statusCode}.";
        }

        if (trim($body) === '') {
            return 'Source returned an empty payload.';
        }

        return null;
    }
}
