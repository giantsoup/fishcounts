<?php

namespace App\Http\Requests;

use App\Models\TripType;
use App\Models\TripTypeAlias;
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
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:'.TripType::MAX_SORT_ORDER],
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

                    return;
                }

                $normalizedNames = [
                    Str::of($this->validated('name'))->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString(),
                    Str::of($this->validated('name'))->lower()->squish()->toString(),
                ];

                if (TripTypeAlias::query()->whereIn('normalized_alias', $normalizedNames)->exists()) {
                    $validator->errors()->add('name', 'This name is already used as a trip type alias.');
                }
            },
        ];
    }
}
