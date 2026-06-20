<?php

namespace App\Http\Requests;

use App\Models\TripType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class StoreTripTypeRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
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

                if (TripType::query()->where('slug', $this->slug())->exists()) {
                    $validator->errors()->add('name', 'This trip type already exists.');
                }
            },
        ];
    }
}
