<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateScrapeSourceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('source')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'priority' => ['required', 'integer', 'min:1', 'max:1000'],
            'is_enabled' => ['sometimes', 'boolean'],
            'supports_historical_dates' => ['sometimes', 'boolean'],
            'supports_landing_filter' => ['sometimes', 'boolean'],
            'rate_limit_seconds' => ['required', 'integer', 'min:1', 'max:3600'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
