<?php

namespace App\Jobs;

use App\Contracts\Parsing\RegulationResearcher;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ResearchLimitsRegulationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 170;

    public int $uniqueFor = 86400;

    public function __construct(public string $jurisdiction, public string $taxon, public string $date)
    {
        $this->onQueue('parsing');
    }

    public function uniqueVia(): Repository
    {
        return Cache::store('database');
    }

    public function uniqueId(): string
    {
        return implode(':', [$this->jurisdiction, $this->taxon, $this->date, config('limits.research_version')]);
    }

    public function middleware(): array
    {
        return [new RateLimited('limits-research')];
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(RegulationResearcher $researcher): void
    {
        $researcher->research($this->jurisdiction, $this->taxon, $this->date);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Limits research failed; qualitative claim retained.', ['jurisdiction' => $this->jurisdiction, 'taxon' => $this->taxon, 'date' => $this->date, 'failure_class' => $exception === null ? null : $exception::class]);
    }
}
