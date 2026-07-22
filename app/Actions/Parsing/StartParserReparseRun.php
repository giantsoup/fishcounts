<?php

namespace App\Actions\Parsing;

use App\DTOs\StartParserReparseRunResult;
use App\Enums\ParserReparseItemMode;
use App\Enums\ParserReparseRunStatus;
use App\Jobs\DispatchParserReparseRunJob;
use App\Models\ParserError;
use App\Models\ParserReparseRun;
use App\Models\RawScrapePayload;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StartParserReparseRun
{
    public const LOCK_KEY = 'parser-reparse-run:start';

    public function handle(User $requester): StartParserReparseRunResult
    {
        $result = Cache::lock(self::LOCK_KEY, 300)->block(15, function () use ($requester): StartParserReparseRunResult {
            return DB::transaction(function () use ($requester): StartParserReparseRunResult {
                $activeRun = ParserReparseRun::query()
                    ->whereIn('status', [ParserReparseRunStatus::Pending, ParserReparseRunStatus::Running])
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                if ($activeRun !== null) {
                    return new StartParserReparseRunResult($activeRun, false);
                }

                $openErrors = ParserError::query()->open();
                $initialOpenErrors = (clone $openErrors)->count();
                $initialAliasErrors = (clone $openErrors)->aliases()->count();
                $affectedPayloadIds = (clone $openErrors)
                    ->whereNotNull('raw_scrape_payload_id')
                    ->distinct()
                    ->pluck('raw_scrape_payload_id');
                $affectedPayloads = RawScrapePayload::query()
                    ->whereKey($affectedPayloadIds)
                    ->orderBy('target_date')
                    ->orderBy('scrape_source_id')
                    ->orderBy('fetched_at')
                    ->orderBy('id')
                    ->get(['id', 'scrape_source_id', 'target_date', 'fetched_at']);

                $run = ParserReparseRun::query()->create([
                    'requested_by_user_id' => $requester->getKey(),
                    'initial_open_errors' => $initialOpenErrors,
                    'initial_alias_errors' => $initialAliasErrors,
                    'initial_structural_errors' => $initialOpenErrors - $initialAliasErrors,
                    'initial_payloads' => $affectedPayloads->count(),
                    'affected_dates' => $affectedPayloads->pluck('target_date')->map->toDateString()->unique()->count(),
                ]);

                $this->createManifest($run, $affectedPayloads);
                $totalItems = $run->items()->count();

                $run->update([
                    'status' => $totalItems === 0 ? ParserReparseRunStatus::Succeeded : ParserReparseRunStatus::Pending,
                    'total_items' => $totalItems,
                    'finished_at' => $totalItems === 0 ? now() : null,
                    'remaining_open_errors' => $totalItems === 0 ? $initialOpenErrors : null,
                    'remaining_alias_errors' => $totalItems === 0 ? $initialAliasErrors : null,
                    'remaining_structural_errors' => $totalItems === 0 ? $initialOpenErrors - $initialAliasErrors : null,
                ]);

                return new StartParserReparseRunResult($run->fresh(), true);
            }, attempts: 3);
        });

        if ($result->created && $result->run->status->isActive()) {
            DispatchParserReparseRunJob::dispatch($result->run->id)->afterCommit();
        }

        return $result;
    }

    /** @param Collection<int, RawScrapePayload> $affectedPayloads */
    private function createManifest(ParserReparseRun $run, Collection $affectedPayloads): void
    {
        $sequence = 0;

        foreach ($affectedPayloads->groupBy(fn (RawScrapePayload $payload): string => $payload->scrape_source_id.'|'.$payload->target_date->toDateString()) as $group) {
            $affectedPayload = $group->last();
            $newestPayload = RawScrapePayload::query()
                ->where('scrape_source_id', $affectedPayload->scrape_source_id)
                ->whereDate('target_date', $affectedPayload->target_date)
                ->latest('fetched_at')
                ->latest('id')
                ->first(['id', 'scrape_source_id', 'target_date']);

            if ($newestPayload === null) {
                continue;
            }

            foreach ($group->where('id', '!=', $newestPayload->id) as $supersededPayload) {
                $run->items()->create([
                    'raw_scrape_payload_id' => $supersededPayload->id,
                    'scrape_source_id' => $supersededPayload->scrape_source_id,
                    'target_date' => $supersededPayload->target_date,
                    'mode' => ParserReparseItemMode::DiagnosticsOnly,
                    'sequence' => ++$sequence,
                ]);
            }

            $run->items()->create([
                'raw_scrape_payload_id' => $newestPayload->id,
                'scrape_source_id' => $newestPayload->scrape_source_id,
                'target_date' => $newestPayload->target_date,
                'mode' => ParserReparseItemMode::Authoritative,
                'sequence' => ++$sequence,
            ]);
        }
    }
}
