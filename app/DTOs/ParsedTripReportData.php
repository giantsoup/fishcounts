<?php

namespace App\DTOs;

use Carbon\CarbonImmutable;

final readonly class ParsedTripReportData
{
    /**
     * @param  array<int, ParsedSpeciesCountData>  $speciesCounts
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $sourceKey,
        public CarbonImmutable $tripDate,
        public ?string $regionName,
        public ?string $landingName,
        public ?string $boatName,
        public ?string $tripTypeName,
        public ?int $anglers,
        public ?string $rawFishCountText,
        public array $speciesCounts,
        public array $metadata = [],
        public ?int $canonicalBoatId = null,
        public ?int $canonicalTripTypeId = null,
    ) {}
}
