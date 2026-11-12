<?php

namespace App\Actions\Species;

use App\Models\Species;
use App\Models\SpeciesAlias;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateSpecies
{
    /**
     * Create a species, defaulting its condition profile to the configured location profile.
     *
     * @throws ValidationException when the name is blank or clashes with another species or species alias.
     */
    public function handle(string $name, ?string $environmentalLocationProfile = null): Species
    {
        if (($conflict = $this->nameConflict($name)) !== null) {
            throw ValidationException::withMessages(['name' => $conflict]);
        }

        return Species::query()->create([
            'environmental_location_profile' => $environmentalLocationProfile
                ?? config('fish.conditions.location_profile', 'san_diego_bight'),
            'name' => $name,
            'slug' => Str::slug($name),
            'is_active' => true,
        ]);
    }

    /**
     * The duplicate-name rule shared by creating and renaming species, or null when the name is free.
     */
    public function nameConflict(string $name, ?Species $except = null): ?string
    {
        $slug = Str::slug($name);

        if ($slug === '') {
            return 'The name must contain letters or numbers.';
        }

        $speciesExists = Species::query()
            ->where('slug', $slug)
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();

        if ($speciesExists) {
            return 'This species already exists.';
        }

        $normalizedNames = [
            Str::of($name)->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString(),
            Str::of($name)->lower()->squish()->toString(),
        ];
        $aliasExists = SpeciesAlias::query()
            ->whereIn('normalized_alias', $normalizedNames)
            ->when($except, fn ($query) => $query->where('species_id', '!=', $except->getKey()))
            ->exists();

        return $aliasExists ? 'This name is already used as a species alias.' : null;
    }
}
