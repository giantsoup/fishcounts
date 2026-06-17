<?php

namespace App\Models;

use App\Enums\SourceType;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['id'])]
class ScrapeSource extends Model
{
    protected $attributes = [
        'priority' => 100,
        'is_enabled' => true,
        'supports_historical_dates' => false,
        'supports_landing_filter' => false,
        'rate_limit_seconds' => 10,
    ];

    protected function casts(): array
    {
        return [
            'source_type' => SourceType::class,
            'is_enabled' => 'boolean',
            'supports_historical_dates' => 'boolean',
            'supports_landing_filter' => 'boolean',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    /** @return HasMany<ScrapeRun, $this> */
    public function scrapeRuns(): HasMany
    {
        return $this->hasMany(ScrapeRun::class);
    }
}
