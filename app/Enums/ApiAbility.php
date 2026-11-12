<?php

namespace App\Enums;

/**
 * Agent API token abilities. A route requires one with ->middleware(ApiAbility::<Case>->middleware()).
 */
enum ApiAbility: string
{
    /** Every GET endpoint. */
    case Read = 'read';

    /** Creating and deleting aliases. */
    case Aliases = 'aliases';

    /** Creating, updating, deactivating, merging, and deleting boats, species, and trip types. */
    case Entities = 'entities';

    /** Reparse previews, runs, retries, and re-processing a single payload. */
    case Reparse = 'reparse';

    /** Dismissing parser errors. */
    case Errors = 'errors';

    /**
     * The route middleware that requires a token with this ability.
     */
    public function middleware(): string
    {
        return 'abilities:'.$this->value;
    }
}
