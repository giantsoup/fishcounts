<?php

namespace App\Models;

use App\Enums\ParserBugReportStatus;
use Database\Factories\ParserBugReportFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['id'])]
class ParserBugReport extends Model
{
    /** @use HasFactory<ParserBugReportFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'preview',
        'requires_approval' => true,
        'review_attempt' => 0,
        'occurrence_count' => 0,
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => ParserBugReportStatus::class,
            'requires_approval' => 'boolean',
            'review_attempt' => 'integer',
            'labels' => 'array',
            'issue_number' => 'integer',
            'occurrence_count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'approved_at' => 'datetime',
            'attempts' => 'integer',
            'last_attempted_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    /** @return HasMany<ParserDiagnosticReview, $this> */
    public function diagnosticReviews(): HasMany
    {
        return $this->hasMany(ParserDiagnosticReview::class);
    }

    /** @return BelongsTo<ParserDiagnosticReview, $this> */
    public function sourceReview(): BelongsTo
    {
        return $this->belongsTo(ParserDiagnosticReview::class, 'parser_diagnostic_review_id');
    }

    /** @return HasMany<ParserBugReportOccurrence, $this> */
    public function occurrences(): HasMany
    {
        return $this->hasMany(ParserBugReportOccurrence::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
