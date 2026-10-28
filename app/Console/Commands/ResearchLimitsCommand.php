<?php

namespace App\Console\Commands;

use App\Contracts\Parsing\RegulationResearcher;
use App\Jobs\ResearchLimitsRegulationJob;
use App\Models\RegulationRuleSnapshot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fish:limits-research {jurisdiction?} {taxon?} {date?} {--refresh : Queue current-date research for existing rule identities}')]
#[Description('Research official rules without changing catches; retain unreviewed candidates for applicability review')]
class ResearchLimitsCommand extends Command
{
    public function handle(RegulationResearcher $researcher): int
    {
        if (! config('limits.research_enabled')) {
            $this->error('Limits research is disabled.');

            return self::FAILURE;
        }
        if ($this->option('refresh')) {
            RegulationRuleSnapshot::query()->select(['jurisdiction', 'taxon'])->distinct()->get()->each(fn (RegulationRuleSnapshot $rule) => ResearchLimitsRegulationJob::dispatch($rule->jurisdiction, $rule->taxon, now()->toDateString())->onConnection('database'));

            return self::SUCCESS;
        }
        if (! $this->argument('jurisdiction') || ! $this->argument('taxon') || ! $this->argument('date')) {
            $this->error('Supply jurisdiction, taxon, and trip date.');

            return self::FAILURE;
        }
        $researcher->research($this->argument('jurisdiction'), $this->argument('taxon'), $this->argument('date'));
        $this->info('Research stored. Unverified candidates do not change catches. Replay is a separate scoped action.');

        return self::SUCCESS;
    }
}
