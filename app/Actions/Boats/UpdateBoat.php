<?php

namespace App\Actions\Boats;

use App\Models\Boat;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateBoat
{
    /**
     * Update the editable attributes of a boat. Renaming re-checks the duplicate-name rule and refreshes the slug.
     *
     * @param  array{name?: string, landing_id?: int|null, booking_url?: string|null, is_active?: bool}  $attributes
     *
     * @throws ValidationException when a new name is blank or clashes with another boat or boat alias.
     */
    public function handle(Boat $boat, array $attributes): Boat
    {
        $attributes = Arr::only($attributes, ['name', 'landing_id', 'booking_url', 'is_active']);

        if (array_key_exists('name', $attributes)) {
            $attributes['name'] = Str::squish($attributes['name']);

            if (($conflict = Boat::nameConflict($attributes['name'], $boat)) !== null) {
                throw ValidationException::withMessages(['name' => $conflict]);
            }

            $attributes['slug'] = Str::slug($attributes['name']);
        }

        $boat->update($attributes);

        return $boat;
    }
}
