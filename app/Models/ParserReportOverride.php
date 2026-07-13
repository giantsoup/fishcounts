<?php

namespace App\Models;

use App\Enums\ParserReportOverrideStatus;
use Database\Factories\ParserReportOverrideFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['id'])]
class ParserReportOverride extends Model
{
    /** @use HasFactory<ParserReportOverrideFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'status' => ParserReportOverrideStatus::class,
            'report_index' => 'integer',
            'review_attempt' => 'integer',
            'corrections' => 'array',
            'original_parse' => 'array',
            'corrected_parse' => 'array',
            'affected_scope' => 'array',
            'approved_at' => 'datetime',
            'first_applied_at' => 'datetime',
            'last_applied_at' => 'datetime',
            'disabled_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<RawScrapePayload, $this> */
    public function rawScrapePayload(): BelongsTo
    {
        return $this->belongsTo(RawScrapePayload::class);
    }

    /** @return BelongsTo<ParserDiagnosticReview, $this> */
    public function diagnosticReview(): BelongsTo
    {
        return $this->belongsTo(ParserDiagnosticReview::class, 'parser_diagnostic_review_id');
    }

    /** @return BelongsTo<ParserBugReport, $this> */
    public function parserBugReport(): BelongsTo
    {
        return $this->belongsTo(ParserBugReport::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function disabler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disabled_by_user_id');
    }
}
