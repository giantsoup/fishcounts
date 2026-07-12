<?php

namespace App\Console\Commands;

use App\Models\ParserDiagnosticReview;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('ai-reviews:prune')]
#[Description('Prune AI parser diagnostic reviews outside the configured rolling retention window')]
class PruneParserDiagnosticReviewsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $retainedCompleteMonths = (int) config('fish.ai_review.retention.complete_months');
        $cutoff = CarbonImmutable::now()->startOfMonth()->subMonths($retainedCompleteMonths);
        $deleted = 0;

        ParserDiagnosticReview::query()
            ->where('created_at', '<', $cutoff)
            ->select('id')
            ->chunkById(500, function (Collection $reviews) use (&$deleted): void {
                $deleted += ParserDiagnosticReview::query()->whereKey($reviews->pluck('id'))->delete();
            });

        $this->info("Pruned {$deleted} parser diagnostic review records.");

        return self::SUCCESS;
    }
}
