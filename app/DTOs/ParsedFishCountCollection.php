<?php

namespace App\DTOs;

use Illuminate\Support\Collection;

final readonly class ParsedFishCountCollection
{
    /** @param Collection<int, ParsedTripReportData> $tripReports */
    public function __construct(
        public Collection $tripReports,
        public ?string $parserVersion = null,
        public ?string $format = null,
    ) {}
}
