<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ActOnParserDiagnosticReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) config('fish.ai_review.human_review_enabled')
            && ($this->user()?->isAdmin() ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }
}
