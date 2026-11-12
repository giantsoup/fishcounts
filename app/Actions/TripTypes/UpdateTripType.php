<?php

namespace App\Actions\TripTypes;

use App\Models\TripType;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateTripType
{
    public function __construct(private CreateTripType $createTripType) {}

    /**
     * Update the editable attributes of a trip type. Renaming re-checks the duplicate-name rule and refreshes the slug.
     *
     * @param  array{name?: string, sort_order?: int, is_active?: bool}  $attributes
     *
     * @throws ValidationException when a new name is blank or clashes with another trip type or trip type alias.
     */
    public function handle(TripType $tripType, array $attributes): TripType
    {
        $attributes = Arr::only($attributes, ['name', 'sort_order', 'is_active']);

        if (array_key_exists('name', $attributes)) {
            if (($conflict = $this->createTripType->nameConflict($attributes['name'], $tripType)) !== null) {
                throw ValidationException::withMessages(['name' => $conflict]);
            }

            $attributes['slug'] = Str::slug($attributes['name']);
        }

        $tripType->update($attributes);

        return $tripType;
    }
}
