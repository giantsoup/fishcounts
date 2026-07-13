<?php

namespace App\Services\AI;

use App\Enums\HistoricalAiReviewRunStatus;
use App\Enums\ParserDiagnosticReviewStatus;
use App\Jobs\ProcessHistoricalAiReviewRunItemJob;
use App\Models\HistoricalAiReviewRun;
use App\Models\RawScrapePayload;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class HistoricalAiReviewDispatcher
{
    /** @return array{eligible_count: int, planned_count: int, estimated_max_cost_micros: int} */
    public function preview(string $scope, CarbonImmutable $from, CarbonImmutable $to, int $maxItems, int $budgetMicros): array
    {
        $estimatedItemCost = (int) config('fish.ai_review.budgets.estimated_request_cost_micros');
        $eligibleCount = $this->query($scope, $from, $to)->count();
        $budgetBound = intdiv($budgetMicros, max(1, $estimatedItemCost));
        $plannedCount = min($eligibleCount, $maxItems, $budgetBound);

        return [
            'eligible_count' => $eligibleCount,
            'planned_count' => $plannedCount,
            'estimated_max_cost_micros' => $plannedCount * $estimatedItemCost,
        ];
    }

    public function create(
        string $scope,
        CarbonImmutable $from,
        CarbonImmutable $to,
        int $maxItems,
        int $budgetMicros,
        string $authorizationReference,
    ): HistoricalAiReviewRun {
        $preview = $this->preview($scope, $from, $to, $maxItems, $budgetMicros);
        $payloads = $this->query($scope, $from, $to)
            ->limit($preview['planned_count'])
            ->get(['id', 'payload_hash']);
        $selectedCount = $payloads->count();
        $selectionFingerprint = hash('sha256', implode('|', [
            $scope,
            $from->toDateString(),
            $to->toDateString(),
            $maxItems,
            $budgetMicros,
            $authorizationReference,
            $payloads->map(fn (RawScrapePayload $payload): string => $payload->id.':'.$payload->payload_hash)->implode(','),
        ]));

        $run = DB::transaction(function () use ($scope, $from, $to, $maxItems, $budgetMicros, $authorizationReference, $selectedCount, $payloads, $selectionFingerprint): HistoricalAiReviewRun {
            $existingRun = HistoricalAiReviewRun::query()
                ->where('authorization_reference', $authorizationReference)
                ->lockForUpdate()
                ->first();

            if ($existingRun !== null) {
                $matchesAuthorization = $existingRun->scope === $scope
                    && $existingRun->date_from->toDateString() === $from->toDateString()
                    && $existingRun->date_to->toDateString() === $to->toDateString()
                    && $existingRun->max_items === $maxItems
                    && $existingRun->budget_micros === $budgetMicros;

                if (! $matchesAuthorization) {
                    throw new InvalidArgumentException('The authorization reference is already assigned to a run with different bounds.');
                }

                return $existingRun;
            }

            $run = HistoricalAiReviewRun::query()->firstOrCreate([
                'selection_fingerprint' => $selectionFingerprint,
            ], [
                'scope' => $scope,
                'status' => HistoricalAiReviewRunStatus::Running,
                'date_from' => $from,
                'date_to' => $to,
                'max_items' => $maxItems,
                'budget_micros' => $budgetMicros,
                'estimated_item_cost_micros' => (int) config('fish.ai_review.budgets.estimated_request_cost_micros'),
                'authorization_reference' => $authorizationReference,
                'selected_count' => $selectedCount,
                'started_at' => now(),
            ]);

            if (! $run->wasRecentlyCreated) {
                return $run;
            }

            foreach ($payloads as $payload) {
                $run->items()->create([
                    'raw_scrape_payload_id' => $payload->id,
                    'payload_hash' => $payload->payload_hash,
                    'item_fingerprint' => hash('sha256', $payload->id.'|'.$payload->payload_hash),
                ]);
            }

            return $run;
        }, attempts: 3);

        $this->dispatchPending($run);

        return $run->refresh();
    }

    public function dispatchPending(HistoricalAiReviewRun $run): int
    {
        if ($run->refresh()->status !== HistoricalAiReviewRunStatus::Running) {
            return 0;
        }

        $dispatched = 0;

        $run->items()
            ->where('status', 'pending')
            ->whereNull('dispatched_at')
            ->select('id')
            ->chunkById(100, function ($items) use (&$dispatched): void {
                foreach ($items as $item) {
                    ProcessHistoricalAiReviewRunItemJob::dispatch($item->id);
                    $item->forceFill(['dispatched_at' => now()])->save();
                    $dispatched++;
                }
            });

        if ($dispatched > 0) {
            $run->increment('dispatched_count', $dispatched);
        }

        return $dispatched;
    }

    private function query(string $scope, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        $query = RawScrapePayload::query()
            ->whereBetween('target_date', [$from->toDateString(), $to->toDateString()])
            ->whereHas('parserErrors', fn (Builder $query): Builder => $query
                ->whereNull('resolution_type')
                ->whereNotNull('diagnostic_fingerprint'));

        if ($scope === 'new') {
            $query->whereHas('parserErrors', fn (Builder $query): Builder => $query
                ->whereNull('resolution_type')
                ->whereNotNull('diagnostic_fingerprint')
                ->whereDoesntHave('diagnosticReviews'));
        }

        if (in_array($scope, ['unresolved', 'historical'], true)) {
            $retryableStatuses = [
                ParserDiagnosticReviewStatus::Failed,
                ParserDiagnosticReviewStatus::Refused,
                ParserDiagnosticReviewStatus::Stale,
                ParserDiagnosticReviewStatus::Skipped,
            ];
            $query->whereHas('parserErrors', fn (Builder $query): Builder => $query
                ->whereNull('resolution_type')
                ->whereNotNull('diagnostic_fingerprint')
                ->where(function (Builder $query) use ($retryableStatuses): void {
                    $query->whereDoesntHave('diagnosticReviews')
                        ->orWhereHas('diagnosticReviews', fn (Builder $query): Builder => $query->whereIn('status', $retryableStatuses));
                }));
        }

        return $query->orderBy('target_date')->orderBy('id');
    }
}
