<?php

namespace App\Actions\TripTypes;

use App\Models\TripType;
use App\Models\TripTypeAlias;
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
        if (($conflict = $this->nameConflict($name)) !== null) {
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

    /**
     * The duplicate-name rule shared by creating and renaming trip types, or null when the name is free.
     */
    public function nameConflict(string $name, ?TripType $except = null): ?string
    {
        $slug = Str::slug($name);

        if ($slug === '') {
            return 'The name must contain letters or numbers.';
        }

        $tripTypeExists = TripType::query()
            ->where('slug', $slug)
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();

        if ($tripTypeExists) {
            return 'This trip type already exists.';
        }

        $normalizedNames = [
            Str::of($name)->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString(),
            Str::of($name)->lower()->squish()->toString(),
        ];
        $aliasExists = TripTypeAlias::query()
            ->whereIn('normalized_alias', $normalizedNames)
            ->when($except, fn ($query) => $query->where('trip_type_id', '!=', $except->getKey()))
            ->exists();

        return $aliasExists ? 'This name is already used as a trip type alias.' : null;
    }
}
