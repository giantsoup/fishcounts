<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ReverseAutomatedParserDiagnosticReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /** @return array<string, array<mixed>|string> */
    public function rules(): array
    {
        return [];
    }
}
