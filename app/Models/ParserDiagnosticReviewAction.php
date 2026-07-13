<?php

namespace App\Models;

use App\Enums\ParserDiagnosticReviewActionType;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['id'])]
class ParserDiagnosticReviewAction extends Model
{
    protected function casts(): array
    {
        return [
            'action' => ParserDiagnosticReviewActionType::class,
            'review_attempt' => 'integer',
            'details' => 'array',
        ];
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

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
