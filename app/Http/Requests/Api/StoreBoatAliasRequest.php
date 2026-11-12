<?php

namespace App\Http\Requests\Api;

use App\Enums\CanonicalEntityType;
use App\Models\Boat;

class StoreBoatAliasRequest extends StoreAliasRequest
{
    public function entityType(): CanonicalEntityType
    {
        return CanonicalEntityType::Boat;
    }

    public function entity(): Boat
    {
        return $this->route('boat');
    }
}
