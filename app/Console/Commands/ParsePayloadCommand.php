<?php

namespace App\Console\Commands;

use App\Jobs\ParseRawPayloadJob;
use App\Models\RawScrapePayload;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fish:parse-payload {payloadId : The raw scrape payload ID to parse} {--sync : Parse immediately instead of queueing}')]
#[Description('Parse one raw scrape payload into normalized trip reports.')]
class ParsePayloadCommand extends Command
{
    public function handle(): int
    {
        $payloadId = (int) $this->argument('payloadId');
        $payload = RawScrapePayload::query()->with('scrapeSource')->findOrFail($payloadId);

        if ($this->option('sync')) {
            ParseRawPayloadJob::dispatchSync(rawScrapePayloadId: $payloadId, parserEngine: $payload->scrapeSource->parser_engine);
            $this->info("Payload {$payloadId} parsed.");
        } else {
            ParseRawPayloadJob::dispatch(rawScrapePayloadId: $payloadId, parserEngine: $payload->scrapeSource->parser_engine);
            $this->info("Payload {$payloadId} queued for parsing.");
        }

        return self::SUCCESS;
    }
}
