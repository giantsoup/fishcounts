<?php

namespace App\Http\Requests;

use App\Services\Parsing\BoatNameNormalizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBoatAliasRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
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
            'boat_id' => ['required', 'integer', Rule::exists('boats', 'id')->where('is_active', true)],
            'alias' => ['required', 'string', 'max:255'],
            'parser_error_id' => ['nullable', 'integer', Rule::exists('parser_errors', 'id')],
        ];
    }

    public function normalizedAlias(): string
    {
        return BoatNameNormalizer::normalize($this->validated('alias'));
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->isEmpty()) {
                    return;
                }

                if ($this->normalizedAlias() === '') {
                    $validator->errors()->add('alias', 'The alias must contain letters or numbers.');

                    return;
                }

            },
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('alias')) {
            $this->merge(['alias' => str($this->input('alias'))->squish()->toString()]);
        }
    }
}
