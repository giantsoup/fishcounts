<?php

namespace App\Services\Scoring;

use App\Enums\ScrapeRunStatus;
use App\Enums\ScrapeRunType;
use App\Models\RawScrapePayload;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use Carbon\CarbonImmutable;

final class DailyScoreReadiness
{
    /** @return list<string> */
    public function incompleteSourceNames(CarbonImmutable $date): array
    {
        $sources = ScrapeSource::query()
            ->where('is_enabled', true)
            ->orderBy('priority')
            ->get(['id', 'name']);
        if ($sources->isEmpty()) {
            return [];
        }

        $runs = ScrapeRun::query()
            ->whereIn('scrape_source_id', $sources->modelKeys())
            ->where('run_type', ScrapeRunType::Daily)
            ->whereDate('target_date', $date->toDateString())
            ->latest('id')
            ->get(['id', 'scrape_source_id', 'status', 'metadata'])
            ->unique('scrape_source_id')
            ->keyBy('scrape_source_id');
        $payloadIds = $runs
            ->where('status', ScrapeRunStatus::Succeeded)
            ->map(fn (ScrapeRun $run): ?int => is_numeric(data_get($run->metadata, 'raw_scrape_payload_id'))
                ? (int) data_get($run->metadata, 'raw_scrape_payload_id')
                : null)
            ->filter()
            ->values();
        $payloads = RawScrapePayload::query()
            ->with('authoritativeParserExecution:id,status')
            ->whereKey($payloadIds)
            ->get(['id', 'authoritative_parser_execution_id'])
            ->keyBy('id');

        return $sources
            ->filter(function (ScrapeSource $source) use ($runs, $payloads): bool {
                $run = $runs->get($source->id);
                if (! $run instanceof ScrapeRun) {
                    return true;
                }
                if (in_array($run->status, [ScrapeRunStatus::Unavailable, ScrapeRunStatus::Cancelled], true)) {
                    return false;
                }
                if ($run->status !== ScrapeRunStatus::Succeeded) {
                    return true;
                }

                $payloadId = data_get($run->metadata, 'raw_scrape_payload_id');
                $payload = is_numeric($payloadId) ? $payloads->get((int) $payloadId) : null;

                return ! $payload instanceof RawScrapePayload
                    || $payload->authoritativeParserExecution?->status !== 'completed';
            })
            ->pluck('name')
            ->values()
            ->all();
    }
}
