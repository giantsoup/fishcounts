<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class QueueParserDiagnosticReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) config('fish.ai_review.human_review_enabled')
            && ($this->user()?->isAdmin() ?? false);
    }

    /** @return array<string, array<mixed>|string> */
    public function rules(): array
    {
        return [];
    }
}
