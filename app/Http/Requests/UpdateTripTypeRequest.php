<?php

namespace App\Http\Requests;

use App\Models\TripType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTripTypeRequest extends FormRequest
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
        $tripTypeId = $this->route('tripType')?->getKey();

        return [
            'order_trip_type_id' => ['required', 'integer', Rule::in([$tripTypeId])],
            'order_sort_order' => ['required', 'integer', 'min:0', 'max:'.TripType::MAX_SORT_ORDER],
        ];
    }
}
