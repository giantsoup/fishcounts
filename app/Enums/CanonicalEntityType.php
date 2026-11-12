<?php

namespace App\Enums;

use App\Services\Parsing\BoatNameNormalizer;

enum CanonicalEntityType: string
{
    case Boat = 'boat';
    case Species = 'species';
    case TripType = 'trip_type';

    /**
     * The normalized form an alias of this entity type is stored and matched under. The admin
     * alias forms and the agent API both normalize through here.
     */
    public function normalizeAlias(string $alias): string
    {
        return match ($this) {
            self::Boat => BoatNameNormalizer::normalize($alias),
            self::Species, self::TripType => str($alias)->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString(),
        };
    }

    /**
     * The parser error type an unknown name of this entity type is recorded under.
     */
    public function aliasErrorType(): string
    {
        return match ($this) {
            self::Boat => 'unknown_boat_alias',
            self::Species => 'unknown_species_alias',
            self::TripType => 'unknown_trip_type_alias',
        };
    }
}
