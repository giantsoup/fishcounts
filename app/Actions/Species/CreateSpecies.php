<?php

namespace App\Actions\Species;

use App\Models\Species;
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
        if (($conflict = Species::nameConflict($name)) !== null) {
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
}
