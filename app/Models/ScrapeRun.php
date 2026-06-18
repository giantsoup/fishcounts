<?php

namespace App\Models;

use App\Enums\ScrapeRunStatus;
use App\Enums\ScrapeRunType;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['id'])]
class ScrapeRun extends Model
{
    protected $attributes = [
        'status' => ScrapeRunStatus::Pending->value,
    ];

    protected function casts(): array
    {
        return [
            'run_type' => ScrapeRunType::class,
            'status' => ScrapeRunStatus::class,
            'target_date' => 'date',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<ScrapeSource, $this> */
    public function scrapeSource(): BelongsTo
    {
        return $this->belongsTo(ScrapeSource::class);
    }

    /** @return HasMany<RawScrapePayload, $this> */
    public function rawScrapePayloads(): HasMany
    {
        return $this->hasMany(RawScrapePayload::class);
    }
}
