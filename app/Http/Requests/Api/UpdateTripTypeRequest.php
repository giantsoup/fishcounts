<?php

namespace App\Http\Requests\Api;

use App\Models\TripType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Updates a trip type's name, sort order, or active flag. Only the fields sent are changed;
 * duplicate names are rejected by UpdateTripType, the same rule the admin form uses.
 */
class UpdateTripTypeRequest extends FormRequest
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
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0', 'max:'.TripType::MAX_SORT_ORDER],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
