<?php

namespace App\Console\Commands;

use App\Models\AiBudgetReservation;
use App\Models\ParserExecution;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ai-parsing:prune')]
#[Description('Remove expired full parser snapshots while retaining compact execution metadata.')]
class PruneParserExecutionsCommand extends Command
{
    public function handle(): int
    {
        $cutoff = now()->subMonths(max(1, (int) config('fish.ai_parsing.retention.snapshot_months')));
        $executionIds = ParserExecution::query()
            ->where('created_at', '<', $cutoff)
            ->where(function ($query): void {
                $query->whereNotNull('deterministic_snapshot')
                    ->orWhereNotNull('ai_snapshot')
                    ->orWhereNotNull('comparison')
                    ->orWhereNotNull('provider_output_excerpt')
                    ->orWhereNotNull('fallback_message')
                    ->orWhereNotNull('failure_message');
            })
            ->pluck('id');

        AiBudgetReservation::query()
            ->whereIn('parser_execution_id', $executionIds)
            ->update([
                'provider_output_excerpt' => null,
                'failure_message' => null,
            ]);

        $count = ParserExecution::query()
            ->whereIn('id', $executionIds)
            ->update([
                'deterministic_snapshot' => null,
                'ai_snapshot' => null,
                'comparison' => null,
                'provider_output_excerpt' => null,
                'fallback_message' => null,
                'failure_message' => null,
            ]);

        $this->info("Pruned full snapshots from {$count} parser execution(s).");

        return self::SUCCESS;
    }
}
