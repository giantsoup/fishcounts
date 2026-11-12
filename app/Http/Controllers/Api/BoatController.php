<?php

namespace App\Http\Controllers\Api;

use App\Actions\Boats\ConsolidateBoatAlias;
use App\Actions\Boats\CreateBoat;
use App\Actions\Boats\UpdateBoat;
use App\Enums\CanonicalEntityType;
use App\Enums\ParserErrorResolutionType;
use App\Http\Controllers\Api\Concerns\DeletesUnreferencedEntities;
use App\Http\Controllers\Api\Concerns\ListsEntitiesWithAliases;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListEntitiesRequest;
use App\Http\Requests\Api\MergeBoatRequest;
use App\Http\Requests\Api\StoreBoatRequest;
use App\Http\Requests\Api\UpdateBoatRequest;
use App\Http\Resources\BoatResource;
use App\Models\Boat;
use App\Models\TripReport;
use App\Support\ApiAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class BoatController extends Controller
{
    use DeletesUnreferencedEntities;
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

    public function update(UpdateBoatRequest $request, Boat $boat, UpdateBoat $updateBoat): BoatResource
    {
        $updateBoat->handle($boat, $request->validated());

        ApiAudit::write($request, 'boat.update', $boat->id);

        return BoatResource::make($this->withRelations($boat));
    }

    public function destroy(Request $request, Boat $boat): Response
    {
        return $this->destroyUnlessReferenced($request, $boat, 'boat', [
            'trip reports' => fn () => TripReport::query()->where('boat_id', $boat->id),
            'aliases' => fn () => $boat->aliases()->getQuery(),
            'alert rules' => fn () => DB::table('alert_rule_boat')->where('boat_id', $boat->id),
        ], "Deactivate it with PATCH /api/boats/{$boat->id} and {\"is_active\": false}, or merge it into the canonical boat with POST /api/boats/{canonical_id}/merge and {\"duplicate_boat_id\": {$boat->id}}.");
    }

    /**
     * Merge the duplicate boat into this one with the same consolidation the alias form uses: the
     * duplicate's trip reports, aliases, and alert rules move here, and the duplicate is deactivated.
     */
    public function merge(MergeBoatRequest $request, Boat $boat, ConsolidateBoatAlias $consolidateBoatAlias): BoatResource
    {
        $mergedBoat = $consolidateBoatAlias->merge(
            $boat,
            $request->duplicateBoat(),
            $request->user()->getKey(),
            ParserErrorResolutionType::AgentAlias,
        );

        ApiAudit::write($request, 'boat.merge', $boat->id);

        return BoatResource::make($this->withRelations($boat->refresh()))
            ->additional(['meta' => ['merged_boat' => [
                'id' => $mergedBoat->id,
                'name' => $mergedBoat->name,
                'is_active' => $mergedBoat->is_active,
            ]]]);
    }

    private function withRelations(Boat $boat): Boat
    {
        return $boat->load(['landing', 'aliases' => fn ($query) => $query->orderBy('alias')]);
    }
}
