<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ActOnParserReportOverrideRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $isAdmin = $this->user()?->can('admin') ?? false;

        return $isAdmin && ($this->routeIs('admin.parser-report-overrides.disable')
            || (bool) config('fish.parsing.overrides.enabled'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        if ($this->routeIs('admin.parser-report-overrides.disable')) {
            return [
                'disable_reason' => ['required', 'string', 'max:1000'],
                'corrections' => ['prohibited'],
            ];
        }

        return [
            'review_notes' => ['nullable', 'string', 'max:2000'],
            'corrections' => ['prohibited'],
        ];
    }
}
