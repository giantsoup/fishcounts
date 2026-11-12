<?php

namespace App\Http\Controllers\Api;

use App\Actions\TripTypes\CreateTripType;
use App\Actions\TripTypes\UpdateTripType;
use App\Enums\CanonicalEntityType;
use App\Http\Controllers\Api\Concerns\ListsEntitiesWithAliases;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListEntitiesRequest;
use App\Http\Requests\Api\StoreTripTypeRequest;
use App\Http\Requests\Api\UpdateTripTypeRequest;
use App\Http\Resources\TripTypeResource;
use App\Models\TripType;
use App\Support\ApiAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

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
        return TripTypeResource::make($this->withAliases($tripType));
    }

    public function store(StoreTripTypeRequest $request, CreateTripType $createTripType): JsonResponse
    {
        $tripType = $createTripType->handle($request->validated('name'), $request->validated('sort_order'));

        ApiAudit::write($request, 'trip-type.create', $tripType->id);

        return TripTypeResource::make($this->withAliases($tripType))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateTripTypeRequest $request, TripType $tripType, UpdateTripType $updateTripType): TripTypeResource
    {
        $updateTripType->handle($tripType, $request->validated());

        ApiAudit::write($request, 'trip-type.update', $tripType->id);

        return TripTypeResource::make($this->withAliases($tripType));
    }

    private function withAliases(TripType $tripType): TripType
    {
        return $tripType->load(['aliases' => fn ($query) => $query->orderBy('alias')]);
    }
}
