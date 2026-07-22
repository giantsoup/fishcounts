<?php

namespace App\Models;

use App\Enums\ParserReparseItemMode;
use App\Enums\ParserReparseItemStatus;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['id'])]
class ParserReparseItem extends Model
{
    protected $attributes = [
        'status' => ParserReparseItemStatus::Pending->value,
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'mode' => ParserReparseItemMode::class,
            'status' => ParserReparseItemStatus::class,
            'target_date' => 'date',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'date_deduplicated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ParserReparseRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ParserReparseRun::class, 'parser_reparse_run_id');
    }

    /** @return BelongsTo<RawScrapePayload, $this> */
    public function rawScrapePayload(): BelongsTo
    {
        return $this->belongsTo(RawScrapePayload::class);
    }

    /** @return BelongsTo<ScrapeSource, $this> */
    public function scrapeSource(): BelongsTo
    {
        return $this->belongsTo(ScrapeSource::class);
    }
}
