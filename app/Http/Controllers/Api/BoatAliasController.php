<?php

namespace App\Http\Controllers\Api;

use App\Actions\Boats\ConsolidateBoatAlias;
use App\Enums\ParserErrorResolutionType;
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
     * Same consolidation as the admin form: trip reports with no boat whose raw name matches are
     * re-pointed, and a variant boat already known by this name is merged in and deactivated.
     */
    public function store(StoreBoatAliasRequest $request, Boat $boat, ConsolidateBoatAlias $consolidateBoatAlias): JsonResponse
    {
        return $this->storeAlias(
            $request,
            'boat-alias',
            fn (string $alias, string $normalizedAlias, int $userId, ParserErrorResolutionType $resolutionType): BoatAlias => $consolidateBoatAlias
                ->handle($boat, $alias, $normalizedAlias, $userId, $resolutionType),
        );
    }

    public function destroy(Request $request, BoatAlias $boatAlias): Response
    {
        return $this->destroyAlias($request, 'boat-alias', $boatAlias);
    }
}
