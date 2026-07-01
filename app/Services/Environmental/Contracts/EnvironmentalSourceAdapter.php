<?php

namespace App\Services\Environmental\Contracts;

use App\DTOs\EnvironmentalFetchResult;
use App\Models\EnvironmentalPayload;
use App\Models\EnvironmentalSource;
use Carbon\CarbonImmutable;

interface EnvironmentalSourceAdapter
{
    public function sourceKey(): string;

    public function fetchForDate(EnvironmentalSource $source, CarbonImmutable $date): EnvironmentalFetchResult;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function observations(EnvironmentalSource $source, EnvironmentalPayload $payload): array;
}
