<?php

namespace App\Actions\Species;

use App\Models\Species;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateSpecies
{
    /**
     * Update the editable attributes of a species. Renaming re-checks the duplicate-name rule and refreshes the slug.
     *
     * @param  array{name?: string, environmental_location_profile?: string, is_active?: bool}  $attributes
     *
     * @throws ValidationException when a new name is blank or clashes with another species or species alias.
     */
    public function handle(Species $species, array $attributes): Species
    {
        $attributes = Arr::only($attributes, ['name', 'environmental_location_profile', 'is_active']);

        if (array_key_exists('name', $attributes)) {
            if (($conflict = Species::nameConflict($attributes['name'], $species)) !== null) {
                throw ValidationException::withMessages(['name' => $conflict]);
            }

            $attributes['slug'] = Str::slug($attributes['name']);
        }

        $species->update($attributes);

        return $species;
    }
}
