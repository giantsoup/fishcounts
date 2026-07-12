<?php

namespace App\Enums;

enum ParserCorrectionOperation: string
{
    case MapAlias = 'map_alias';
    case ReplaceEntity = 'replace_entity';
    case SetAnglerCount = 'set_angler_count';
    case SetSpeciesCount = 'set_species_count';
    case RemoveSpeciesCount = 'remove_species_count';
}
