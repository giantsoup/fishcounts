<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ParserErrorResolutionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSpeciesAliasRequest;
use App\Http\Requests\StoreSpeciesRequest;
use App\Http\Requests\UpdateSpeciesEnvironmentalProfileRequest;
use App\Models\ParserError;
use App\Models\Species;
use App\Models\SpeciesAlias;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class SpeciesAliasController extends Controller
{
    public function index(): View
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        return view('admin.species-aliases.index', [
            'selectedSpeciesId' => old('species_id', session('selected_species_id')),
            'environmentalLocationProfiles' => $this->environmentalLocationProfiles(),
            'species' => Species::query()
                ->where('is_active', true)
                ->with(['aliases' => fn ($query) => $query->orderBy('alias')])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function storeSpecies(StoreSpeciesRequest $request): RedirectResponse
    {
        Species::query()->create([
            'environmental_location_profile' => $request->validated('environmental_location_profile')
                ?? config('fish.conditions.location_profile', 'san_diego_bight'),
            'name' => $request->validated('name'),
            'slug' => $request->slug(),
            'is_active' => true,
        ]);

        return redirect()->route('admin.species-aliases.index')->with('status', 'Species saved.');
    }

    public function updateSpecies(
        UpdateSpeciesEnvironmentalProfileRequest $request,
        Species $species,
    ): RedirectResponse {
        $species->update([
            'environmental_location_profile' => $request->validated('species_environmental_location_profile'),
        ]);

        return redirect()
            ->route('admin.species-aliases.index')
            ->with('status', 'Species condition profile updated.')
            ->with('selected_species_id', $species->id);
    }

    public function store(StoreSpeciesAliasRequest $request): RedirectResponse
    {
        $alias = SpeciesAlias::query()->create([
            'species_id' => $request->validated('species_id'),
            'alias' => $request->validated('alias'),
            'normalized_alias' => $request->normalizedAlias(),
        ]);

        $this->resolveParserError($request, $alias->alias);

        return redirect()
            ->back()
            ->with('status', 'Species alias saved.')
            ->with('selected_species_id', $request->validated('species_id'));
    }

    private function resolveParserError(StoreSpeciesAliasRequest $request, string $alias): void
    {
        $parserErrorId = $request->validated('parser_error_id');

        if ($parserErrorId === null) {
            return;
        }

        ParserError::query()
            ->whereKey($parserErrorId)
            ->where('raw_field', 'species')
            ->where('raw_value', $alias)
            ->update([
                'resolved_at' => now(),
                'resolved_by_user_id' => $request->user()->id,
                'resolution_type' => ParserErrorResolutionType::Alias->value,
            ]);
    }

    /** @return array<string, string> */
    private function environmentalLocationProfiles(): array
    {
        return collect(config('fish.conditions.profiles', []))
            ->mapWithKeys(function (array $profile, string $slug): array {
                $locationType = ($profile['location_type'] ?? 'local') === 'local' ? 'Local' : 'Offshore';

                return [$slug => ($profile['label'] ?? str($slug)->replace('_', ' ')->headline())." — {$locationType}"];
            })
            ->all();
    }
}
