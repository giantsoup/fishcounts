<?php

namespace App\Http\Controllers\Api;

use App\Actions\Boats\ConsolidateBoatAlias;
use App\Enums\ParserErrorResolutionType;
use App\Exceptions\BoatAliasWouldMergeBoat;
use App\Http\Controllers\Api\Concerns\ManagesAliases;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListAliasesRequest;
use App\Http\Requests\Api\StoreBoatAliasRequest;
use App\Models\Boat;
use App\Models\BoatAlias;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class BoatAliasController extends Controller
{
    use ManagesAliases;

    public function index(ListAliasesRequest $request, Boat $boat): AnonymousResourceCollection
    {
        return $this->listAliases($request, $boat->aliases());
    }

    /**
     * Same consolidation as the admin form for trip reports with no boat whose raw name matches: they
     * are re-pointed. Unlike the admin form, an alias that already names another boat is refused with
     * a 409 instead of merging that boat in; merging needs the merge endpoint and the entities ability.
     */
    public function store(StoreBoatAliasRequest $request, Boat $boat, ConsolidateBoatAlias $consolidateBoatAlias): JsonResponse
    {
        try {
            return $this->storeAlias(
                $request,
                'boat-alias',
                fn (string $alias, string $normalizedAlias, int $userId, ParserErrorResolutionType $resolutionType): BoatAlias => $consolidateBoatAlias
                    ->handle($boat, $alias, $normalizedAlias, $userId, $resolutionType, allowBoatMerge: false),
            );
        } catch (BoatAliasWouldMergeBoat $exception) {
            $otherBoat = $exception->otherBoat;

            abort(Response::HTTP_CONFLICT, "This alias already names boat \"{$otherBoat->name}\" (id {$otherBoat->id}), so adding it would merge that boat into this one. "
                ."To merge them, use POST /api/boats/{$boat->id}/merge with {\"duplicate_boat_id\": {$otherBoat->id}}.");
        }
    }

    public function destroy(Request $request, BoatAlias $boatAlias): Response
    {
        return $this->destroyAlias($request, 'boat-alias', $boatAlias);
    }
}
