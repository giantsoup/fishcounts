<?php

namespace App\Http\Requests;

use App\Models\Species;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSpeciesRequest extends FormRequest
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
            'environmental_location_profile' => ['nullable', 'string', Rule::in(array_keys(config('fish.conditions.profiles', [])))],
            'name' => ['required', 'string', 'max:255'],
        ];
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

                $conflict = Species::nameConflict($this->validated('name'));

                if ($conflict !== null) {
                    $validator->errors()->add('name', $conflict);
                }
            },
        ];
    }
}
