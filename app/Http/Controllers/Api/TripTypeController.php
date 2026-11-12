<?php

namespace App\Http\Controllers\Api;

use App\Actions\TripTypes\CreateTripType;
use App\Actions\TripTypes\UpdateTripType;
use App\Enums\CanonicalEntityType;
use App\Http\Controllers\Api\Concerns\DeletesUnreferencedEntities;
use App\Http\Controllers\Api\Concerns\ListsEntitiesWithAliases;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListEntitiesRequest;
use App\Http\Requests\Api\StoreTripTypeRequest;
use App\Http\Requests\Api\UpdateTripTypeRequest;
use App\Http\Resources\TripTypeResource;
use App\Models\TripReport;
use App\Models\TripType;
use App\Support\ApiAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class TripTypeController extends Controller
{
    use DeletesUnreferencedEntities;
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

    public function destroy(Request $request, TripType $tripType): Response
    {
        return $this->destroyUnlessReferenced($request, $tripType, 'trip-type', [
            'trip reports' => fn () => TripReport::query()->where('trip_type_id', $tripType->id),
            'aliases' => fn () => $tripType->aliases()->getQuery(),
            'alert rules' => fn () => DB::table('alert_rule_trip_type')->where('trip_type_id', $tripType->id),
        ], 'Deactivate it instead with PATCH /api/trip-types/{id} and {"is_active": false}.');
    }

    private function withAliases(TripType $tripType): TripType
    {
        return $tripType->load(['aliases' => fn ($query) => $query->orderBy('alias')]);
    }
}
