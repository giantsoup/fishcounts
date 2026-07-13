<?php

namespace App\Models;

use Database\Factories\ParserBugReportOccurrenceFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['id'])]
class ParserBugReportOccurrence extends Model
{
    /** @use HasFactory<ParserBugReportOccurrenceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'review_attempt' => 'integer',
            'seen_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ParserBugReport, $this> */
    public function parserBugReport(): BelongsTo
    {
        return $this->belongsTo(ParserBugReport::class);
    }

    /** @return BelongsTo<ParserDiagnosticReview, $this> */
    public function parserDiagnosticReview(): BelongsTo
    {
        return $this->belongsTo(ParserDiagnosticReview::class);
    }

    /** @return BelongsTo<ParserError, $this> */
    public function parserError(): BelongsTo
    {
        return $this->belongsTo(ParserError::class);
    }
}
