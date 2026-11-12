<?php

namespace App\Http\Requests\Api;

use App\Models\Landing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Updates a boat's name, landing, booking URL, or active flag. Only the fields sent are changed;
 * duplicate names are rejected by UpdateBoat, the same rule the admin form uses.
 */
class UpdateBoatRequest extends FormRequest
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
            'landing_id' => ['sometimes', 'nullable', 'integer', Rule::exists(Landing::class, 'id')->where('is_active', true)],
            'booking_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $bookingUrl = $this->input('booking_url');

        if (is_string($name)) {
            $this->merge(['name' => Str::squish($name)]);
        }

        if (is_string($bookingUrl)) {
            $this->merge(['booking_url' => Str::of($bookingUrl)->trim()->toString() ?: null]);
        }
    }
}
