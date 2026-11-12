<?php

namespace App\Http\Controllers\Api;

use App\Enums\CanonicalEntityType;
use App\Http\Controllers\Api\Concerns\ListsEntitiesWithAliases;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListEntitiesRequest;
use App\Http\Resources\SpeciesResource;
use App\Models\Species;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SpeciesController extends Controller
{
    use ListsEntitiesWithAliases;

    public function index(ListEntitiesRequest $request): AnonymousResourceCollection
    {
        return SpeciesResource::collection(
            $this->listEntities($request, Species::query(), CanonicalEntityType::Species),
        );
    }

    public function show(Species $species): SpeciesResource
    {
        return SpeciesResource::make($species->load(['aliases' => fn ($query) => $query->orderBy('alias')]));
    }
}
