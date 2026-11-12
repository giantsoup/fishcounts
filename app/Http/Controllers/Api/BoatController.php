<?php

namespace App\Http\Controllers\Api;

use App\Enums\CanonicalEntityType;
use App\Http\Controllers\Api\Concerns\ListsEntitiesWithAliases;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListEntitiesRequest;
use App\Http\Resources\BoatResource;
use App\Models\Boat;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BoatController extends Controller
{
    use ListsEntitiesWithAliases;

    public function index(ListEntitiesRequest $request): AnonymousResourceCollection
    {
        return BoatResource::collection(
            $this->listEntities($request, Boat::query()->with('landing'), CanonicalEntityType::Boat),
        );
    }

    public function show(Boat $boat): BoatResource
    {
        return BoatResource::make($boat->load(['landing', 'aliases' => fn ($query) => $query->orderBy('alias')]));
    }
}
