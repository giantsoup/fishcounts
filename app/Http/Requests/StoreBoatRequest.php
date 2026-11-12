<?php

namespace App\Http\Requests;

use App\Actions\Boats\CreateBoat;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBoatRequest extends FormRequest
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
            'boat_name' => ['required', 'string', 'max:255'],
            'landing_id' => ['nullable', 'integer', Rule::exists('landings', 'id')->where('is_active', true)],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->isEmpty()) {
                    return;
                }

                $conflict = app(CreateBoat::class)->nameConflict($this->validated('boat_name'));

                if ($conflict !== null) {
                    $validator->errors()->add('boat_name', $conflict);
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('boat_name')) {
            $this->merge(['boat_name' => str($this->input('boat_name'))->squish()->toString()]);
        }
    }
}
