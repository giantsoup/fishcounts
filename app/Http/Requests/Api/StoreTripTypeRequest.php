<?php

namespace App\Http\Requests\Api;

use App\Models\TripType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creates a trip type. Duplicate names are rejected by CreateTripType, the same rule the admin form uses.
 */
class StoreTripTypeRequest extends FormRequest
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
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.TripType::MAX_SORT_ORDER],
        ];
    }
}
