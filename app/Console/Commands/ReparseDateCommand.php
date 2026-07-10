<?php

namespace App\Console\Commands;

use App\Jobs\DeduplicateTripReportsJob;
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
            ->orderBy('scrape_source_id')
            ->orderByDesc('fetched_at')
            ->orderByDesc('id')
            ->get()
            ->unique('scrape_source_id')
            ->values();

        $synchronous = (bool) $this->option('sync');

        $payloads->each(function (RawScrapePayload $payload) use ($synchronous): void {
            if ($synchronous) {
                ParseRawPayloadJob::dispatchSync($payload->id, false);

                return;
            }

            ParseRawPayloadJob::dispatch($payload->id);
        });

        if ($synchronous) {
            DeduplicateTripReportsJob::dispatchSync($date);
        }

        $action = $synchronous ? 'Reparsed' : 'Queued';
        $this->info("{$action} {$payloads->count()} payload(s) for {$date}.");

        return self::SUCCESS;
    }
}
