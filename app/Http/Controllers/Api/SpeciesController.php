<?php

namespace App\Http\Controllers\Api;

use App\Actions\Species\CreateSpecies;
use App\Actions\Species\UpdateSpecies;
use App\Enums\CanonicalEntityType;
use App\Http\Controllers\Api\Concerns\ListsEntitiesWithAliases;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListEntitiesRequest;
use App\Http\Requests\Api\StoreSpeciesRequest;
use App\Http\Requests\Api\UpdateSpeciesRequest;
use App\Http\Resources\SpeciesResource;
use App\Models\Species;
use App\Support\ApiAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

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
        return SpeciesResource::make($this->withAliases($species));
    }

    public function store(StoreSpeciesRequest $request, CreateSpecies $createSpecies): JsonResponse
    {
        $species = $createSpecies->handle($request->validated('name'), $request->validated('environmental_location_profile'));

        ApiAudit::write($request, 'species.create', $species->id);

        return SpeciesResource::make($this->withAliases($species))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateSpeciesRequest $request, Species $species, UpdateSpecies $updateSpecies): SpeciesResource
    {
        $updateSpecies->handle($species, $request->validated());

        ApiAudit::write($request, 'species.update', $species->id);

        return SpeciesResource::make($this->withAliases($species));
    }

    private function withAliases(Species $species): Species
    {
        return $species->load(['aliases' => fn ($query) => $query->orderBy('alias')]);
    }
}
