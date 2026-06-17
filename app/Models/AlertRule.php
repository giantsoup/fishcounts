<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['id'])]
class AlertRule extends Model
{
    protected $attributes = [
        'is_enabled' => true,
        'minimum_score' => 70,
        'trend_window_days' => 3,
        'baseline_window_days' => 7,
        'email_enabled' => true,
        'discord_enabled' => false,
        'include_in_weekly_digest' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'minimum_count_per_angler' => 'decimal:2',
            'email_enabled' => 'boolean',
            'discord_enabled' => 'boolean',
            'include_in_weekly_digest' => 'boolean',
            'settings' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Species, $this> */
    public function species(): BelongsTo
    {
        return $this->belongsTo(Species::class);
    }

    /** @return BelongsToMany<TripType, $this> */
    public function tripTypes(): BelongsToMany
    {
        return $this->belongsToMany(TripType::class);
    }

    /** @return BelongsToMany<Region, $this> */
    public function regions(): BelongsToMany
    {
        return $this->belongsToMany(Region::class);
    }

    /** @return BelongsToMany<Landing, $this> */
    public function landings(): BelongsToMany
    {
        return $this->belongsToMany(Landing::class);
    }

    /** @return BelongsToMany<Boat, $this> */
    public function boats(): BelongsToMany
    {
        return $this->belongsToMany(Boat::class);
    }

    /** @return HasMany<ScoreResult, $this> */
    public function scoreResults(): HasMany
    {
        return $this->hasMany(ScoreResult::class);
    }
}
