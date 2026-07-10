<?php

namespace App\Http\Requests;

use App\Models\Boat;
use App\Models\BoatAlias;
use App\Services\Parsing\BoatNameNormalizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
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

    public function slug(): string
    {
        return Str::slug($this->validated('boat_name'));
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->isEmpty()) {
                    return;
                }

                if ($this->slug() === '') {
                    $validator->errors()->add('boat_name', 'The boat name must contain letters or numbers.');

                    return;
                }

                if (Boat::query()->where('slug', $this->slug())->exists()) {
                    $validator->errors()->add('boat_name', 'A boat with this name already exists.');

                    return;
                }

                $normalizedName = BoatNameNormalizer::normalize($this->validated('boat_name'));

                if (BoatAlias::query()->where('normalized_alias', $normalizedName)->exists()) {
                    $validator->errors()->add('boat_name', 'This name is already an alias for another boat.');
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
