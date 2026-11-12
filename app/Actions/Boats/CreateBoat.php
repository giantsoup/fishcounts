<?php

namespace App\Actions\Boats;

use App\Models\Boat;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateBoat
{
    /**
     * @throws ValidationException when the name is blank or clashes with another boat or boat alias.
     */
    public function handle(string $name, ?int $landingId = null): Boat
    {
        $name = Str::squish($name);

        if (($conflict = Boat::nameConflict($name)) !== null) {
            throw ValidationException::withMessages(['name' => $conflict]);
        }

        return Boat::query()->create([
            'name' => $name,
            'slug' => Str::slug($name),
            'landing_id' => $landingId,
            'is_active' => true,
        ]);
    }
}
