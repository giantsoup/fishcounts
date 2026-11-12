<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Updates a species' name, condition profile, or active flag. Only the fields sent are changed;
 * duplicate names are rejected by UpdateSpecies, the same rule the admin form uses.
 */
class UpdateSpeciesRequest extends FormRequest
{
    /**
     * Access is decided by the agent-api middleware group and the route's ability.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'environmental_location_profile' => ['sometimes', 'required', 'string', Rule::in(array_keys(config('fish.conditions.profiles', [])))],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
