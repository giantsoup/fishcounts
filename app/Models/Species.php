<?php

namespace App\Models;

use App\Enums\CanonicalEntityType;
use Database\Factories\SpeciesFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Guarded(['id'])]
class Species extends Model
{
    /** @use HasFactory<SpeciesFactory> */
    use HasFactory;

    protected $attributes = [
        'environmental_location_profile' => 'san_diego_bight',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<SpeciesAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(SpeciesAlias::class);
    }

    /**
     * The duplicate-name rule for creating and renaming species: the reason the name is taken, or
     * null when it is free. A name clashes with another species' slug or another species' alias,
     * including aliases stored under the older lowercase-and-squish normalization.
     */
    public static function nameConflict(string $name, ?self $except = null): ?string
    {
        $slug = Str::slug($name);

        if ($slug === '') {
            return 'The name must contain letters or numbers.';
        }

        $speciesExists = self::query()
            ->where('slug', $slug)
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();

        if ($speciesExists) {
            return 'This species already exists.';
        }

        $aliasExists = SpeciesAlias::query()
            ->whereIn('normalized_alias', [
                CanonicalEntityType::Species->normalizeAlias($name),
                Str::of($name)->lower()->squish()->toString(),
            ])
            ->when($except, fn ($query) => $query->where('species_id', '!=', $except->getKey()))
            ->exists();

        return $aliasExists ? 'This name is already used as a species alias.' : null;
    }
}
