<?php

namespace App\Http\Requests;

use App\Enums\ParserEngine;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'parser_engine' => ['sometimes', Rule::enum(ParserEngine::class)],
            'parser_engine_change_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $source = $this->route('source');
                $requested = $this->input('parser_engine');

                if ($source !== null
                    && is_string($requested)
                    && $requested !== $source->parser_engine->value
                    && blank($this->input('parser_engine_change_reason'))) {
                    $validator->errors()->add('parser_engine_change_reason', 'A reason is required when changing parser engines.');
                }
            },
        ];
    }
}
