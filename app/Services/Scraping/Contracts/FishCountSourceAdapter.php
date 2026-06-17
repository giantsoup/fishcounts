<?php

namespace App\Services\Scraping\Contracts;

use App\DTOs\FetchResult;
use App\DTOs\ParsedFishCountCollection;
use App\DTOs\RawPayloadData;
use App\Models\ScrapeSource;
use Carbon\CarbonImmutable;

interface FishCountSourceAdapter
{
    public function sourceKey(): string;

    public function supportsDate(CarbonImmutable $date): bool;

    public function fetchForDate(ScrapeSource $source, CarbonImmutable $date): FetchResult;

    public function parse(RawPayloadData $payload): ParsedFishCountCollection;
}
