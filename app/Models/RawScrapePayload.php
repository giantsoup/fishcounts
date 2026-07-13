<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['id'])]
class RawScrapePayload extends Model
{
    protected function casts(): array
    {
        return [
            'target_date' => 'date',
            'fetched_at' => 'datetime',
            'parsed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<ScrapeRun, $this> */
    public function scrapeRun(): BelongsTo
    {
        return $this->belongsTo(ScrapeRun::class);
    }

    /** @return BelongsTo<ScrapeSource, $this> */
    public function scrapeSource(): BelongsTo
    {
        return $this->belongsTo(ScrapeSource::class);
    }

    /** @return HasMany<TripReport, $this> */
    public function tripReports(): HasMany
    {
        return $this->hasMany(TripReport::class);
    }

    /** @return HasMany<ParserDiagnosticReview, $this> */
    public function parserDiagnosticReviews(): HasMany
    {
        return $this->hasMany(ParserDiagnosticReview::class);
    }

    /** @return HasMany<ParserReportOverride, $this> */
    public function parserReportOverrides(): HasMany
    {
        return $this->hasMany(ParserReportOverride::class);
    }
}
