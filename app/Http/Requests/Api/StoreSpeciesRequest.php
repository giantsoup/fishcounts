<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creates a species. Duplicate names are rejected by CreateSpecies, the same rule the admin form uses.
 */
class StoreSpeciesRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'environmental_location_profile' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(config('fish.conditions.profiles', [])))],
        ];
    }
}
