<?php

namespace App\Services\AI;

use App\Enums\HistoricalAiReviewRunItemStatus;
use App\Enums\HistoricalAiReviewRunStatus;
use App\Enums\ParserDiagnosticReviewStatus;
use App\Models\HistoricalAiReviewRun;
use App\Models\HistoricalAiReviewRunItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class HistoricalAiReviewRunItemFinalizer
{
    public function complete(int $historicalAiReviewRunItemId): void
    {
        $item = HistoricalAiReviewRunItem::query()
            ->with('rawScrapePayload')
            ->find($historicalAiReviewRunItemId);

        if ($item === null || in_array($item->status, [HistoricalAiReviewRunItemStatus::Completed, HistoricalAiReviewRunItemStatus::Failed], true)) {
            return;
        }

        if ($item->rawScrapePayload === null) {
            $this->fail($item->id, 'The selected payload is no longer available.');

            return;
        }

        $terminalFailure = $item->rawScrapePayload->parserDiagnosticReviews()
            ->whereHas('parserError', fn ($query) => $query->whereNull('resolution_type'))
            ->whereIn('status', [
                ParserDiagnosticReviewStatus::Failed,
                ParserDiagnosticReviewStatus::Stale,
                ParserDiagnosticReviewStatus::Skipped,
            ])
            ->exists();

        if ($terminalFailure) {
            $this->fail($item->id, 'The AI review item ended without a reviewable result.');

            return;
        }

        DB::transaction(function () use ($item): void {
            $lockedItem = HistoricalAiReviewRunItem::query()->lockForUpdate()->findOrFail($item->id);

            if (in_array($lockedItem->status, [HistoricalAiReviewRunItemStatus::Completed, HistoricalAiReviewRunItemStatus::Failed], true)) {
                return;
            }

            $lockedItem->forceFill([
                'status' => HistoricalAiReviewRunItemStatus::Completed,
                'completed_at' => now(),
            ])->save();

            $run = HistoricalAiReviewRun::query()->lockForUpdate()->findOrFail($lockedItem->historical_ai_review_run_id);
            $run->completed_count++;
            $this->completeRunIfFinished($run);
            $run->save();
        }, attempts: 3);
    }

    public function fail(int $historicalAiReviewRunItemId, Throwable|string $failure): void
    {
        $message = $failure instanceof Throwable ? $failure->getMessage() : $failure;
        $message = preg_replace('/(?:sk-[A-Za-z0-9_-]+|Bearer\s+\S+)/i', '[redacted]', $message) ?? 'Historical AI review item failed.';

        DB::transaction(function () use ($historicalAiReviewRunItemId, $message): void {
            $item = HistoricalAiReviewRunItem::query()->lockForUpdate()->find($historicalAiReviewRunItemId);

            if ($item === null || in_array($item->status, [HistoricalAiReviewRunItemStatus::Completed, HistoricalAiReviewRunItemStatus::Failed], true)) {
                return;
            }

            $item->forceFill([
                'status' => HistoricalAiReviewRunItemStatus::Failed,
                'failure_message' => Str::limit($message, 1000, ''),
                'completed_at' => now(),
            ])->save();

            $run = HistoricalAiReviewRun::query()->lockForUpdate()->findOrFail($item->historical_ai_review_run_id);
            $run->failed_count++;
            $this->completeRunIfFinished($run);
            $run->save();
        }, attempts: 3);
    }

    private function completeRunIfFinished(HistoricalAiReviewRun $run): void
    {
        if ($run->completed_count + $run->failed_count >= $run->selected_count
            && $run->status === HistoricalAiReviewRunStatus::Running) {
            $run->status = $run->failed_count > 0
                ? HistoricalAiReviewRunStatus::Failed
                : HistoricalAiReviewRunStatus::Completed;
            $run->completed_at = now();
        }
    }
}
