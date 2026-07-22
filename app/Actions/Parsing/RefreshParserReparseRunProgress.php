<?php

namespace App\Actions\Parsing;

use App\Enums\ParserReparseItemStatus;
use App\Enums\ParserReparseRunStatus;
use App\Models\ParserError;
use App\Models\ParserReparseRun;
use Illuminate\Support\Facades\DB;

class RefreshParserReparseRunProgress
{
    public function handle(int $parserReparseRunId): ParserReparseRun
    {
        return DB::transaction(function () use ($parserReparseRunId): ParserReparseRun {
            $run = ParserReparseRun::query()->lockForUpdate()->findOrFail($parserReparseRunId);
            $counts = $run->items()
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status');
            $total = (int) $counts->sum();
            $pending = (int) ($counts[ParserReparseItemStatus::Pending->value] ?? 0);
            $running = (int) ($counts[ParserReparseItemStatus::Running->value] ?? 0);
            $completed = (int) ($counts[ParserReparseItemStatus::Succeeded->value] ?? 0);
            $failed = (int) ($counts[ParserReparseItemStatus::Failed->value] ?? 0);
            $dateCount = $run->items()->distinct()->count('target_date');
            $deduplicatedDateCount = $run->items()
                ->whereNotNull('date_deduplicated_at')
                ->distinct()
                ->count('target_date');
            $isFinished = $total === 0 || ($pending === 0 && $running === 0 && ($failed > 0 || $deduplicatedDateCount === $dateCount));
            $attributes = [
                'total_items' => $total,
                'queued_items' => $total,
                'completed_items' => $completed,
                'failed_items' => $failed,
            ];

            if ($isFinished) {
                $remaining = ParserError::query()->open();
                $remainingOpen = (clone $remaining)->count();
                $remainingAliases = (clone $remaining)->aliases()->count();
                $attributes += [
                    'status' => $failed > 0 ? ParserReparseRunStatus::Failed : ParserReparseRunStatus::Succeeded,
                    'remaining_open_errors' => $remainingOpen,
                    'remaining_alias_errors' => $remainingAliases,
                    'remaining_structural_errors' => $remainingOpen - $remainingAliases,
                    'finished_at' => now(),
                    'error_message' => $failed > 0 ? "{$failed} item(s) failed to reparse." : null,
                ];
            }

            $run->update($attributes);

            return $run->fresh();
        }, attempts: 3);
    }
}
