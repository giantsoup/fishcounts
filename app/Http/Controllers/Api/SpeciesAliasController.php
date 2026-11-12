<?php

namespace App\Http\Controllers\Api;

use App\Actions\Species\CreateSpeciesAlias;
use App\Enums\ParserErrorResolutionType;
use App\Http\Controllers\Api\Concerns\ManagesAliases;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListAliasesRequest;
use App\Http\Requests\Api\StoreSpeciesAliasRequest;
use App\Models\Species;
use App\Models\SpeciesAlias;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SpeciesAliasController extends Controller
{
    use ManagesAliases;

    public function index(ListAliasesRequest $request, Species $species): AnonymousResourceCollection
    {
        return $this->listAliases($request, $species->aliases());
    }

    public function store(StoreSpeciesAliasRequest $request, Species $species, CreateSpeciesAlias $createSpeciesAlias): JsonResponse
    {
        return $this->storeAlias(
            $request,
            'species-alias',
            fn (string $alias, string $normalizedAlias, int $userId, ParserErrorResolutionType $resolutionType): SpeciesAlias => $createSpeciesAlias
                ->handle($species, $alias, $normalizedAlias, $userId, $resolutionType),
        );
    }

    public function destroy(Request $request, SpeciesAlias $speciesAlias): Response
    {
        return $this->destroyAlias($request, 'species-alias', $speciesAlias);
    }
}
