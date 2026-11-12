<?php

namespace App\Http\Controllers\Api;

use App\Enums\CanonicalEntityType;
use App\Http\Controllers\Api\Concerns\ListsEntitiesWithAliases;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListEntitiesRequest;
use App\Http\Resources\TripTypeResource;
use App\Models\TripType;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TripTypeController extends Controller
{
    use ListsEntitiesWithAliases;

    public function index(ListEntitiesRequest $request): AnonymousResourceCollection
    {
        return TripTypeResource::collection(
            $this->listEntities($request, TripType::query(), CanonicalEntityType::TripType),
        );
    }

    public function show(TripType $tripType): TripTypeResource
    {
        return TripTypeResource::make($tripType->load(['aliases' => fn ($query) => $query->orderBy('alias')]));
    }
}
