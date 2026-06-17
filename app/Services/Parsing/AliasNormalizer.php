<?php

namespace App\Services\Parsing;

use App\Models\Boat;
use App\Models\Landing;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use App\Models\Region;
use App\Models\Species;
use App\Models\SpeciesAlias;
use App\Models\TripType;
use App\Models\TripTypeAlias;
use Illuminate\Support\Str;

class AliasNormalizer
{
    public function species(string $rawValue, RawScrapePayload $payload): ?Species
    {
        $normalized = $this->normalize($rawValue);
        $alias = SpeciesAlias::query()
            ->whereIn('normalized_alias', [$normalized, Str::of($rawValue)->lower()->squish()->toString()])
            ->first();
        $species = $alias?->species ?? Species::query()->where('slug', Str::slug($rawValue))->first();

        if ($species === null) {
            $this->recordUnknown($payload, 'unknown_species_alias', 'species', $rawValue);
        }

        return $species;
    }

    public function tripType(?string $rawValue, RawScrapePayload $payload): ?TripType
    {
        if ($rawValue === null || trim($rawValue) === '') {
            return null;
        }

        $normalized = $this->normalize($rawValue);
        $alias = TripTypeAlias::query()
            ->whereIn('normalized_alias', [$normalized, Str::of($rawValue)->lower()->squish()->toString()])
            ->first();
        $tripType = $alias?->tripType ?? TripType::query()->where('slug', Str::slug($rawValue))->first();

        if ($tripType === null) {
            $this->recordUnknown($payload, 'unknown_trip_type_alias', 'trip_type', $rawValue);
        }

        return $tripType;
    }

    public function region(?string $rawValue): ?Region
    {
        if ($rawValue === null || trim($rawValue) === '') {
            return null;
        }

        return Region::query()->firstOrCreate(
            ['slug' => Str::slug($rawValue)],
            ['name' => Str::title($rawValue)],
        );
    }

    public function landing(?string $rawValue, ?Region $region): ?Landing
    {
        if ($rawValue === null || trim($rawValue) === '') {
            return null;
        }

        return Landing::query()->firstOrCreate(
            ['slug' => Str::slug($rawValue)],
            ['name' => Str::title($rawValue), 'region_id' => $region?->id],
        );
    }

    public function boat(?string $rawValue, ?Landing $landing): ?Boat
    {
        if ($rawValue === null || trim($rawValue) === '') {
            return null;
        }

        return Boat::query()->firstOrCreate(
            ['slug' => Str::slug($rawValue)],
            ['name' => Str::title($rawValue), 'landing_id' => $landing?->id],
        );
    }

    private function recordUnknown(RawScrapePayload $payload, string $type, string $field, string $rawValue): void
    {
        ParserError::query()->firstOrCreate(
            [
                'raw_scrape_payload_id' => $payload->id,
                'scrape_source_id' => $payload->scrape_source_id,
                'target_date' => $payload->target_date,
                'error_type' => $type,
                'raw_field' => $field,
                'raw_value' => $rawValue,
            ],
            ['message' => "Unknown {$field} alias [{$rawValue}]."],
        );
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }
}
