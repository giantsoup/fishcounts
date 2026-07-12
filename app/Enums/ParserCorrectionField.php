<?php

namespace App\Enums;

enum ParserCorrectionField: string
{
    case Boat = 'boat';
    case Species = 'species';
    case TripType = 'trip_type';
    case Anglers = 'anglers';
    case SpeciesCount = 'species_count';
}
