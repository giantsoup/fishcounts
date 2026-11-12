<?php

namespace App\Http\Requests\Api;

use App\Enums\CanonicalEntityType;
use App\Models\Species;

class StoreSpeciesAliasRequest extends StoreAliasRequest
{
    public function entityType(): CanonicalEntityType
    {
        return CanonicalEntityType::Species;
    }

    public function entity(): Species
    {
        return $this->route('species');
    }
}
