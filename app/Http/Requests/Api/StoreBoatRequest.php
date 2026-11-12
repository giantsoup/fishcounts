<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creates a boat. Duplicate names are rejected by CreateBoat, the same rule the admin form uses.
 */
class StoreBoatRequest extends FormRequest
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
            'landing_id' => ['sometimes', 'nullable', 'integer', Rule::exists('landings', 'id')->where('is_active', true)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');

        if (is_string($name)) {
            $this->merge(['name' => str($name)->squish()->toString()]);
        }
    }
}
