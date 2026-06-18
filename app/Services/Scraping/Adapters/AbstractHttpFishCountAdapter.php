<?php

namespace App\Services\Scraping\Adapters;

use App\DTOs\FetchResult;
use App\DTOs\ParsedFishCountCollection;
use App\DTOs\RawPayloadData;
use App\Models\ScrapeSource;
use App\Services\Parsing\SourceSpecificFishCountParser;
use App\Services\Scraping\Contracts\FishCountSourceAdapter;
use App\Services\Scraping\HttpSourceFetcher;
use Carbon\CarbonImmutable;

abstract class AbstractHttpFishCountAdapter implements FishCountSourceAdapter
{
    public function __construct(
        private readonly HttpSourceFetcher $fetcher,
        private readonly SourceSpecificFishCountParser $parser,
    ) {}

    public function supportsDate(CarbonImmutable $date): bool
    {
        return $date->lte(CarbonImmutable::today());
    }

    public function fetchForDate(ScrapeSource $source, CarbonImmutable $date): FetchResult
    {
        return $this->fetcher->fetch($source, $date, $this->pathForDate($date));
    }

    public function parse(RawPayloadData $payload): ParsedFishCountCollection
    {
        return $this->parser->parse($payload);
    }

    abstract protected function pathForDate(CarbonImmutable $date): string;
}
