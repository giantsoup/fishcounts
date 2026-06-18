<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CountsIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'species_id' => ['nullable', 'integer', Rule::exists('species', 'id')->where('is_active', true)],
            'trip_type_id' => ['nullable', 'integer', Rule::exists('trip_types', 'id')->where('is_active', true)],
            'landing_id' => ['nullable', 'integer', Rule::exists('landings', 'id')->where('is_active', true)],
            'boat_id' => ['nullable', 'integer', Rule::exists('boats', 'id')->where('is_active', true)],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $from = $this->dateFilter('from');
                $to = $this->dateFilter('to');

                if ($from !== null && $to !== null && $from->gt($to)) {
                    $validator->errors()->add('from', 'The from date must be before or equal to the to date.');
                }

                if ($from !== null && $to !== null && $from->diffInDays($to) > 366) {
                    $validator->errors()->add('to', 'The date range may not exceed 366 days.');
                }
            },
        ];
    }

    /** @return array{from: string, to: string, species_id: ?int, trip_type_id: ?int, landing_id: ?int, boat_id: ?int} */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'from' => $this->dateFilter('from')?->toDateString() ?? now()->subDays(30)->toDateString(),
            'to' => $this->dateFilter('to')?->toDateString() ?? now()->toDateString(),
            'species_id' => isset($validated['species_id']) ? (int) $validated['species_id'] : null,
            'trip_type_id' => isset($validated['trip_type_id']) ? (int) $validated['trip_type_id'] : null,
            'landing_id' => isset($validated['landing_id']) ? (int) $validated['landing_id'] : null,
            'boat_id' => isset($validated['boat_id']) ? (int) $validated['boat_id'] : null,
        ];
    }

    private function dateFilter(string $key): ?CarbonImmutable
    {
        $value = $this->input($key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
