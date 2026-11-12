<?php

namespace App\Actions\TripTypes;

use App\Models\TripType;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateTripType
{
    /**
     * Create a trip type, placing it last in display order when no sort order is given.
     *
     * @throws ValidationException when the name is blank or clashes with another trip type or trip type alias.
     */
    public function handle(string $name, ?int $sortOrder = null): TripType
    {
        if (($conflict = TripType::nameConflict($name)) !== null) {
            throw ValidationException::withMessages(['name' => $conflict]);
        }

        return TripType::query()->create([
            'name' => $name,
            'slug' => Str::slug($name),
            'sort_order' => $sortOrder
                ?? min((int) TripType::query()->max('sort_order') + 1, TripType::MAX_SORT_ORDER),
            'is_active' => true,
        ]);
    }
}
