<?php

namespace App\Http\Controllers\Api;

use App\Actions\Boats\CreateBoat;
use App\Enums\CanonicalEntityType;
use App\Http\Controllers\Api\Concerns\ListsEntitiesWithAliases;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListEntitiesRequest;
use App\Http\Requests\Api\StoreBoatRequest;
use App\Http\Resources\BoatResource;
use App\Models\Boat;
use App\Support\ApiAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

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
        return BoatResource::make($this->withRelations($boat));
    }

    public function store(StoreBoatRequest $request, CreateBoat $createBoat): JsonResponse
    {
        $boat = $createBoat->handle($request->validated('name'), $request->validated('landing_id'));

        ApiAudit::write($request, 'boat.create', $boat->id);

        return BoatResource::make($this->withRelations($boat))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    private function withRelations(Boat $boat): Boat
    {
        return $boat->load(['landing', 'aliases' => fn ($query) => $query->orderBy('alias')]);
    }
}
