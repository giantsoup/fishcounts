<?php

namespace App\Models;

use App\Enums\CanonicalEntityType;
use Database\Factories\TripTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Guarded(['id'])]
class TripType extends Model
{
    /** @use HasFactory<TripTypeFactory> */
    use HasFactory;

    public const int MAX_SORT_ORDER = 65_535;

    protected $attributes = [
        'sort_order' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<TripTypeAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(TripTypeAlias::class);
    }

    /** @param  Builder<TripType>  $query */
    #[Scope]
    protected function orderedForDisplay(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name')->orderBy('id');
    }

    /**
     * The duplicate-name rule for creating and renaming trip types: the reason the name is taken,
     * or null when it is free. A name clashes with another trip type's slug or another trip type's
     * alias, including aliases stored under the older lowercase-and-squish normalization.
     */
    public static function nameConflict(string $name, ?self $except = null): ?string
    {
        $slug = Str::slug($name);

        if ($slug === '') {
            return 'The name must contain letters or numbers.';
        }

        $tripTypeExists = self::query()
            ->where('slug', $slug)
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();

        if ($tripTypeExists) {
            return 'This trip type already exists.';
        }

        $aliasExists = TripTypeAlias::query()
            ->whereIn('normalized_alias', [
                CanonicalEntityType::TripType->normalizeAlias($name),
                Str::of($name)->lower()->squish()->toString(),
            ])
            ->when($except, fn ($query) => $query->where('trip_type_id', '!=', $except->getKey()))
            ->exists();

        return $aliasExists ? 'This name is already used as a trip type alias.' : null;
    }
}
