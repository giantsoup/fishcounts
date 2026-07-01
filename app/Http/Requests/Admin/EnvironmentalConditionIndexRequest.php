<?php

namespace App\Http\Requests\Admin;

use App\Support\DateInputNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class EnvironmentalConditionIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'from' => DateInputNormalizer::toIsoDate($this->input('from')),
            'to' => DateInputNormalizer::toIsoDate($this->input('to')),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'location_profile' => ['nullable', 'string', 'max:100'],
            'source_id' => ['nullable', 'integer', Rule::exists('environmental_sources', 'id')],
            'metric' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'status' => ['nullable', Rule::in(['partial', 'finalized'])],
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

    /** @return array{from: string, to: string, location_profile: string, source_id: ?int, metric: ?string, status: ?string} */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'from' => $this->dateFilter('from')?->toDateString() ?? now()->subDays(30)->toDateString(),
            'to' => $this->dateFilter('to')?->toDateString() ?? now()->toDateString(),
            'location_profile' => isset($validated['location_profile']) && $validated['location_profile'] !== ''
                ? (string) $validated['location_profile']
                : (string) config('fish.conditions.location_profile', 'san_diego_bight'),
            'source_id' => isset($validated['source_id']) ? (int) $validated['source_id'] : null,
            'metric' => isset($validated['metric']) && $validated['metric'] !== '' ? (string) $validated['metric'] : null,
            'status' => isset($validated['status']) && $validated['status'] !== '' ? (string) $validated['status'] : null,
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
