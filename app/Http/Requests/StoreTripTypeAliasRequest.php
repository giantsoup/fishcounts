<?php

namespace App\Http\Requests;

use App\Models\TripTypeAlias;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTripTypeAliasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'trip_type_id' => ['required', 'integer', Rule::exists('trip_types', 'id')->where('is_active', true)],
            'alias' => ['required', 'string', 'max:255'],
            'parser_error_id' => ['nullable', 'integer', Rule::exists('parser_errors', 'id')],
        ];
    }

    public function normalizedAlias(): string
    {
        return str($this->validated('alias'))->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->isEmpty()) {
                    return;
                }

                if (TripTypeAlias::query()->where('normalized_alias', $this->normalizedAlias())->exists()) {
                    $validator->errors()->add('alias', 'This alias already exists.');
                }
            },
        ];
    }
}
