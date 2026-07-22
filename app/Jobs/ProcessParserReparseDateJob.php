<?php

namespace App\Jobs;

use App\Actions\Parsing\ParseRawPayloadAction;
use App\Actions\Parsing\RefreshParserReparseRunProgress;
use App\Actions\Parsing\RefreshRawPayloadDiagnostics;
use App\DTOs\ParseRawPayloadOptions;
use App\Enums\ParserReparseItemMode;
use App\Enums\ParserReparseItemStatus;
use App\Enums\ParserReparseRunStatus;
use App\Models\ParserReparseItem;
use App\Models\ParserReparseRun;
use App\Models\RawScrapePayload;
use App\Services\Parsing\TripReportNormalizer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ProcessParserReparseDateJob implements ShouldBeUnique, ShouldQueue
{
    use FoundationQueueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $parserReparseRunId, public string $targetDate)
    {
        $this->onQueue('parsing');
    }

    public function uniqueId(): string
    {
        return $this->parserReparseRunId.':'.$this->targetDate;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(
        RefreshRawPayloadDiagnostics $refreshDiagnostics,
        ParseRawPayloadAction $parseRawPayload,
        TripReportNormalizer $normalizer,
        RefreshParserReparseRunProgress $refreshProgress,
    ): void {
        $run = ParserReparseRun::query()->findOrFail($this->parserReparseRunId);

        if ($run->status !== ParserReparseRunStatus::Running) {
            return;
        }

        ParserReparseItem::query()
            ->where('parser_reparse_run_id', $this->parserReparseRunId)
            ->whereDate('target_date', $this->targetDate)
            ->where('status', ParserReparseItemStatus::Running)
            ->update(['status' => ParserReparseItemStatus::Pending]);

        $this->appendNewAuthoritativeItems();
        $this->processPendingItems($refreshDiagnostics, $parseRawPayload, $refreshProgress);

        for ($newestPayloadChecks = 0; $newestPayloadChecks < 3; $newestPayloadChecks++) {
            if (! $this->appendNewAuthoritativeItems()) {
                break;
            }

            $this->processPendingItems($refreshDiagnostics, $parseRawPayload, $refreshProgress);
        }

        if ($this->appendNewAuthoritativeItems()) {
            throw new RuntimeException('New raw payloads kept arriving while the maintenance run was finalizing.');
        }

        $normalizer->refreshPrimaryReports($this->targetDate);
        ParserReparseItem::query()
            ->where('parser_reparse_run_id', $this->parserReparseRunId)
            ->whereDate('target_date', $this->targetDate)
            ->where('mode', ParserReparseItemMode::Authoritative)
            ->where('status', ParserReparseItemStatus::Succeeded)
            ->update(['date_deduplicated_at' => now()]);
        $refreshProgress->handle($this->parserReparseRunId);
    }

    public function failed(Throwable $throwable): void
    {
        DB::transaction(function () use ($throwable): void {
            $failedCount = ParserReparseItem::query()
                ->where('parser_reparse_run_id', $this->parserReparseRunId)
                ->whereDate('target_date', $this->targetDate)
                ->whereNot('status', ParserReparseItemStatus::Succeeded)
                ->update([
                    'status' => ParserReparseItemStatus::Failed,
                    'finished_at' => now(),
                    'error_message' => str($throwable->getMessage())->limit(1000, '')->toString(),
                ]);

            if ($failedCount === 0) {
                $authoritativeItemId = ParserReparseItem::query()
                    ->where('parser_reparse_run_id', $this->parserReparseRunId)
                    ->whereDate('target_date', $this->targetDate)
                    ->where('mode', ParserReparseItemMode::Authoritative)
                    ->latest('sequence')
                    ->value('id');

                ParserReparseItem::query()
                    ->whereKey($authoritativeItemId)
                    ->update([
                        'status' => ParserReparseItemStatus::Failed,
                        'finished_at' => now(),
                        'date_deduplicated_at' => null,
                        'error_message' => str($throwable->getMessage())->limit(1000, '')->toString(),
                    ]);
            }
        }, attempts: 3);

        app(RefreshParserReparseRunProgress::class)->handle($this->parserReparseRunId);
    }

    private function processPendingItems(
        RefreshRawPayloadDiagnostics $refreshDiagnostics,
        ParseRawPayloadAction $parseRawPayload,
        RefreshParserReparseRunProgress $refreshProgress,
    ): void {
        while ($item = $this->nextProcessableItem()) {
            $item = $this->claim($item->id);

            if ($item === null) {
                continue;
            }

            try {
                if ($item->mode === ParserReparseItemMode::DiagnosticsOnly) {
                    $refreshDiagnostics->handle($item->raw_scrape_payload_id);
                } else {
                    $parseRawPayload->handleWithOptions($item->raw_scrape_payload_id, ParseRawPayloadOptions::maintenance());
                }
            } catch (Throwable $throwable) {
                ParserReparseItem::query()->whereKey($item->id)->update([
                    'status' => ParserReparseItemStatus::Pending,
                    'error_message' => str($throwable->getMessage())->limit(1000, '')->toString(),
                ]);

                throw $throwable;
            }

            ParserReparseItem::query()->whereKey($item->id)->update([
                'status' => ParserReparseItemStatus::Succeeded,
                'finished_at' => now(),
                'error_message' => null,
            ]);
            $refreshProgress->handle($this->parserReparseRunId);
        }
    }

    private function nextProcessableItem(): ?ParserReparseItem
    {
        return ParserReparseItem::query()
            ->where('parser_reparse_run_id', $this->parserReparseRunId)
            ->whereDate('target_date', $this->targetDate)
            ->where('status', ParserReparseItemStatus::Pending)
            ->orderBy('scrape_source_id')
            ->orderBy('sequence')
            ->first();
    }

    private function claim(int $itemId): ?ParserReparseItem
    {
        return DB::transaction(function () use ($itemId): ?ParserReparseItem {
            $item = ParserReparseItem::query()->lockForUpdate()->findOrFail($itemId);

            if ($item->status !== ParserReparseItemStatus::Pending) {
                return null;
            }

            $item->update([
                'status' => ParserReparseItemStatus::Running,
                'attempts' => $item->attempts + 1,
                'started_at' => $item->started_at ?? now(),
                'error_message' => null,
            ]);

            return $item->fresh();
        }, attempts: 3);
    }

    private function appendNewAuthoritativeItems(): bool
    {
        return DB::transaction(function (): bool {
            $sourceIds = ParserReparseItem::query()
                ->where('parser_reparse_run_id', $this->parserReparseRunId)
                ->whereDate('target_date', $this->targetDate)
                ->distinct()
                ->pluck('scrape_source_id');
            $added = false;

            foreach ($sourceIds as $sourceId) {
                $latestPayload = RawScrapePayload::query()
                    ->where('scrape_source_id', $sourceId)
                    ->whereDate('target_date', $this->targetDate)
                    ->latest('fetched_at')
                    ->latest('id')
                    ->first(['id', 'scrape_source_id', 'target_date']);
                $currentAuthoritative = ParserReparseItem::query()
                    ->where('parser_reparse_run_id', $this->parserReparseRunId)
                    ->where('scrape_source_id', $sourceId)
                    ->whereDate('target_date', $this->targetDate)
                    ->where('mode', ParserReparseItemMode::Authoritative)
                    ->latest('sequence')
                    ->lockForUpdate()
                    ->first();

                if ($latestPayload === null || $currentAuthoritative?->raw_scrape_payload_id === $latestPayload->id) {
                    continue;
                }

                ParserReparseItem::query()->firstOrCreate(
                    [
                        'parser_reparse_run_id' => $this->parserReparseRunId,
                        'raw_scrape_payload_id' => $latestPayload->id,
                        'mode' => ParserReparseItemMode::Authoritative,
                    ],
                    [
                        'scrape_source_id' => $sourceId,
                        'target_date' => $this->targetDate,
                        'sequence' => (int) ParserReparseItem::query()
                            ->where('parser_reparse_run_id', $this->parserReparseRunId)
                            ->max('sequence') + 1,
                    ],
                );
                $added = true;
            }

            return $added;
        }, attempts: 3);
    }
}
