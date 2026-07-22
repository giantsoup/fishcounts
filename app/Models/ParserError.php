<?php

namespace App\Models;

use App\Enums\ParserErrorResolutionType;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Guarded(['id'])]
class ParserError extends Model
{
    public const ALIAS_ERROR_TYPES = [
        'unknown_boat_alias',
        'unknown_species_alias',
        'unknown_trip_type_alias',
    ];

    protected function casts(): array
    {
        return [
            'target_date' => 'date',
            'context' => 'array',
            'resolved_at' => 'datetime',
            'resolution_type' => ParserErrorResolutionType::class,
        ];
    }

    /** @param Builder<ParserError> $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at')->whereNull('resolution_type');
    }

    /** @param Builder<ParserError> $query */
    public function scopeAliases(Builder $query): Builder
    {
        return $query->whereIn('error_type', self::ALIAS_ERROR_TYPES);
    }

    /** @param Builder<ParserError> $query */
    public function scopeStructural(Builder $query): Builder
    {
        return $query->whereNotIn('error_type', self::ALIAS_ERROR_TYPES);
    }

    /** @return BelongsTo<ScrapeSource, $this> */
    public function scrapeSource(): BelongsTo
    {
        return $this->belongsTo(ScrapeSource::class);
    }

    /** @return BelongsTo<RawScrapePayload, $this> */
    public function rawScrapePayload(): BelongsTo
    {
        return $this->belongsTo(RawScrapePayload::class);
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    /** @return HasMany<ParserDiagnosticReview, $this> */
    public function diagnosticReviews(): HasMany
    {
        return $this->hasMany(ParserDiagnosticReview::class);
    }

    /** @return HasOne<ParserDiagnosticReview, $this> */
    public function latestDiagnosticReview(): HasOne
    {
        return $this->hasOne(ParserDiagnosticReview::class)->latestOfMany();
    }

    /** @return HasMany<ParserDiagnosticReviewAction, $this> */
    public function humanReviewActions(): HasMany
    {
        return $this->hasMany(ParserDiagnosticReviewAction::class);
    }
}
