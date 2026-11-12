<?php

namespace App\Http\Controllers\Api;

use App\Actions\TripTypes\CreateTripTypeAlias;
use App\Enums\ParserErrorResolutionType;
use App\Http\Controllers\Api\Concerns\ManagesAliases;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListAliasesRequest;
use App\Http\Requests\Api\StoreTripTypeAliasRequest;
use App\Models\TripType;
use App\Models\TripTypeAlias;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class TripTypeAliasController extends Controller
{
    use ManagesAliases;

    public function index(ListAliasesRequest $request, TripType $tripType): AnonymousResourceCollection
    {
        return $this->listAliases($request, $tripType->aliases());
    }

    public function store(StoreTripTypeAliasRequest $request, TripType $tripType, CreateTripTypeAlias $createTripTypeAlias): JsonResponse
    {
        return $this->storeAlias(
            $request,
            'trip-type-alias',
            fn (string $alias, string $normalizedAlias, int $userId, ParserErrorResolutionType $resolutionType): TripTypeAlias => $createTripTypeAlias
                ->handle($tripType, $alias, $normalizedAlias, $userId, $resolutionType),
        );
    }

    public function destroy(Request $request, TripTypeAlias $tripTypeAlias): Response
    {
        return $this->destroyAlias($request, 'trip-type-alias', $tripTypeAlias);
    }
}
