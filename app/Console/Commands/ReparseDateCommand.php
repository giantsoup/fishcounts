<?php

namespace App\Console\Commands;

use App\Jobs\ParseRawPayloadJob;
use App\Models\RawScrapePayload;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fish:reparse-date {date : Date to reparse, YYYY-MM-DD} {--source= : Optional scrape source slug} {--sync : Parse immediately instead of queueing}')]
#[Description('Reparse all raw scrape payloads for a date.')]
class ReparseDateCommand extends Command
{
    public function handle(): int
    {
        $date = CarbonImmutable::parse((string) $this->argument('date'))->toDateString();
        $sourceSlug = $this->option('source');
        $payloads = RawScrapePayload::query()
            ->with('scrapeSource')
            ->whereDate('target_date', $date)
            ->when(is_string($sourceSlug) && $sourceSlug !== '', fn ($query) => $query->whereHas('scrapeSource', fn ($sourceQuery) => $sourceQuery->where('slug', $sourceSlug)))
            ->get();

        $payloads->each(function (RawScrapePayload $payload): void {
            if ($this->option('sync')) {
                ParseRawPayloadJob::dispatchSync($payload->id);

                return;
            }

            ParseRawPayloadJob::dispatch($payload->id);
        });

        $this->info("Queued {$payloads->count()} payload(s) for {$date}.");

        return self::SUCCESS;
    }
}
