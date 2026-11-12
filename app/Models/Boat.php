<?php

namespace App\Models;

use App\Enums\CanonicalEntityType;
use Database\Factories\BoatFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Guarded(['id'])]
class Boat extends Model
{
    /** @use HasFactory<BoatFactory> */
    use HasFactory;

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<Landing, $this> */
    public function landing(): BelongsTo
    {
        return $this->belongsTo(Landing::class);
    }

    /** @return HasMany<BoatAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(BoatAlias::class);
    }

    /** @return HasMany<TripReport, $this> */
    public function tripReports(): HasMany
    {
        return $this->hasMany(TripReport::class);
    }

    /**
     * The duplicate-name rule for creating and renaming boats: the reason the name is taken, or
     * null when it is free. A name clashes with another boat's slug or another boat's alias.
     */
    public static function nameConflict(string $name, ?self $except = null): ?string
    {
        $slug = Str::slug($name);

        if ($slug === '') {
            return 'The boat name must contain letters or numbers.';
        }

        $boatExists = self::query()
            ->where('slug', $slug)
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();

        if ($boatExists) {
            return 'A boat with this name already exists.';
        }

        $aliasExists = BoatAlias::query()
            ->where('normalized_alias', CanonicalEntityType::Boat->normalizeAlias($name))
            ->when($except, fn ($query) => $query->where('boat_id', '!=', $except->getKey()))
            ->exists();

        return $aliasExists ? 'This name is already an alias for another boat.' : null;
    }
}
