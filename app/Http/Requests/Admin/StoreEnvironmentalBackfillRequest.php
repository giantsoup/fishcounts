<?php

namespace App\Http\Requests\Admin;

use App\Models\EnvironmentalSource;
use App\Services\Environmental\EnvironmentalBackfillDispatcher;
use App\Support\DateInputNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEnvironmentalBackfillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'from_date' => DateInputNormalizer::toIsoDate($this->input('from_date')),
            'to_date' => DateInputNormalizer::toIsoDate($this->input('to_date')),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $latestDate = EnvironmentalBackfillDispatcher::latestDate()->toDateString();

        return [
            'from_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.EnvironmentalBackfillDispatcher::EARLIEST_DATE, 'before_or_equal:'.$latestDate],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date', 'before_or_equal:'.$latestDate],
            'location_profile' => [
                'required',
                'string',
                'max:100',
                Rule::exists(EnvironmentalSource::class, 'location_profile')
                    ->where(fn ($query) => $query->where('is_enabled', true)->where('supports_historical_dates', true)),
            ],
            'confirmed' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'from_date.after_or_equal' => 'Conditions cannot be backfilled before January 1, 2026.',
            'to_date.after_or_equal' => 'The end date must be on or after the start date.',
            'location_profile.exists' => 'Choose a profile with at least one enabled historical data source.',
            'confirmed.accepted' => 'Confirm that you understand this will queue historical provider requests.',
        ];
    }

    public function fromDate(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $this->validated('from_date'),
            (string) config('fish.conditions.timezone', 'America/Los_Angeles'),
        );
    }

    public function toDate(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $this->validated('to_date'),
            (string) config('fish.conditions.timezone', 'America/Los_Angeles'),
        );
    }

    public function locationProfile(): string
    {
        return (string) $this->validated('location_profile');
    }
}
