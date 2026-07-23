<?php

namespace App\Services\Parsing;

use App\Models\Boat;
use App\Models\Landing;
use App\Models\Species;
use App\Models\TripType;

final class AiParserCatalog
{
    /** @return array{landings: list<array<string, mixed>>, boats: list<array<string, mixed>>, trip_types: list<array<string, mixed>>, species: list<array<string, mixed>>} */
    public function active(): array
    {
        return [
            'landings' => Landing::query()
                ->where('is_active', true)
                ->with('region:id,name')
                ->orderBy('id')
                ->get(['id', 'region_id', 'name'])
                ->map(fn (Landing $landing): array => [
                    'id' => $landing->id,
                    'name' => $landing->name,
                    'region' => $landing->region?->name,
                ])->all(),
            'boats' => Boat::query()
                ->where('is_active', true)
                ->with(['aliases:id,boat_id,alias', 'landing:id,name'])
                ->orderBy('id')
                ->get(['id', 'landing_id', 'name'])
                ->map(fn (Boat $boat): array => [
                    'id' => $boat->id,
                    'name' => $boat->name,
                    'landing_id' => $boat->landing_id,
                    'landing' => $boat->landing?->name,
                    'aliases' => $boat->aliases->pluck('alias')->values()->all(),
                ])->all(),
            'trip_types' => TripType::query()
                ->where('is_active', true)
                ->with('aliases:id,trip_type_id,alias')
                ->orderBy('id')
                ->get(['id', 'name'])
                ->map(fn (TripType $tripType): array => [
                    'id' => $tripType->id,
                    'name' => $tripType->name,
                    'aliases' => $tripType->aliases->pluck('alias')->values()->all(),
                ])->all(),
            'species' => Species::query()
                ->where('is_active', true)
                ->with('aliases:id,species_id,alias')
                ->orderBy('id')
                ->get(['id', 'name'])
                ->map(fn (Species $species): array => [
                    'id' => $species->id,
                    'name' => $species->name,
                    'aliases' => $species->aliases->pluck('alias')->values()->all(),
                ])->all(),
        ];
    }

    /** @param array<string, mixed> $catalog */
    public function version(array $catalog): string
    {
        return (string) config('fish.ai_parsing.catalog_version').':'.hash('sha256', json_encode($catalog, JSON_THROW_ON_ERROR));
    }
}
