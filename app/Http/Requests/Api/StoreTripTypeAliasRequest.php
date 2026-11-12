<?php

namespace App\Http\Requests\Api;

use App\Enums\CanonicalEntityType;
use App\Models\TripType;

class StoreTripTypeAliasRequest extends StoreAliasRequest
{
    public function entityType(): CanonicalEntityType
    {
        return CanonicalEntityType::TripType;
    }

    public function entity(): TripType
    {
        return $this->route('tripType');
    }
}
