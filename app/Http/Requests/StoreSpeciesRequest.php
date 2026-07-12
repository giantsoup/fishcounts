<?php

namespace App\Http\Requests;

use App\Models\Species;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSpeciesRequest extends FormRequest
{
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
            'environmental_location_profile' => ['nullable', 'string', Rule::in(array_keys(config('fish.conditions.profiles', [])))],
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    public function slug(): string
    {
        return Str::slug($this->validated('name'));
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->isEmpty()) {
                    return;
                }

                if ($this->slug() === '') {
                    $validator->errors()->add('name', 'The name must contain letters or numbers.');

                    return;
                }

                if (Species::query()->where('slug', $this->slug())->exists()) {
                    $validator->errors()->add('name', 'This species already exists.');
                }
            },
        ];
    }
}
