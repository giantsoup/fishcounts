<?php

namespace App\Models;

use App\Enums\BackfillRunStatus;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['id'])]
class BackfillRunItem extends Model
{
    protected $attributes = [
        'status' => BackfillRunStatus::Pending->value,
    ];

    protected function casts(): array
    {
        return [
            'target_date' => 'date',
            'status' => BackfillRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BackfillRun, $this> */
    public function backfillRun(): BelongsTo
    {
        return $this->belongsTo(BackfillRun::class);
    }

    /** @return BelongsTo<ScrapeSource, $this> */
    public function scrapeSource(): BelongsTo
    {
        return $this->belongsTo(ScrapeSource::class);
    }

    /** @return BelongsTo<ScrapeRun, $this> */
    public function scrapeRun(): BelongsTo
    {
        return $this->belongsTo(ScrapeRun::class);
    }

    /** @return BelongsTo<RawScrapePayload, $this> */
    public function rawScrapePayload(): BelongsTo
    {
        return $this->belongsTo(RawScrapePayload::class);
    }
}
