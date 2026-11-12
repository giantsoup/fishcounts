<?php

namespace App\Exceptions;

use App\Models\Boat;
use RuntimeException;

/**
 * An alias would have merged another boat into the target, but the caller asked that merges happen
 * only explicitly.
 */
class BoatAliasWouldMergeBoat extends RuntimeException
{
    public function __construct(public readonly Boat $otherBoat)
    {
        parent::__construct("This alias already names boat \"{$otherBoat->name}\" (id {$otherBoat->id}).");
    }
}
