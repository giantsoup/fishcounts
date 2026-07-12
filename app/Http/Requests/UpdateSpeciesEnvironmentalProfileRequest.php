<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSpeciesEnvironmentalProfileRequest extends FormRequest
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
        $speciesId = $this->route('species')?->getKey();

        return [
            'species_environmental_location_profile' => ['required', 'string', Rule::in(array_keys(config('fish.conditions.profiles', [])))],
            'species_id' => ['required', 'integer', Rule::in([$speciesId])],
        ];
    }
}
