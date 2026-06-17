<?php

namespace App\Http\Requests;

use App\Models\AlertRule;
use App\Models\Boat;
use App\Models\Landing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAlertRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AlertRule::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'species_id' => ['required', Rule::exists('species', 'id')->where('is_active', true)],
            'is_enabled' => ['sometimes', 'boolean'],
            'minimum_score' => ['required', 'integer', 'min:0', 'max:100'],
            'minimum_total_count' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'minimum_count_per_angler' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'trend_window_days' => ['required', 'integer', 'min:1', 'max:30'],
            'baseline_window_days' => ['required', 'integer', 'min:1', 'max:90'],
            'email_enabled' => ['sometimes', 'boolean'],
            'discord_enabled' => ['sometimes', 'boolean'],
            'include_in_weekly_digest' => ['sometimes', 'boolean'],
            'region_ids' => ['required', 'array', 'min:1'],
            'region_ids.*' => ['integer', Rule::exists('regions', 'id')->where('is_active', true)],
            'trip_type_ids' => ['required', 'array', 'min:1'],
            'trip_type_ids.*' => ['integer', Rule::exists('trip_types', 'id')->where('is_active', true)],
            'landing_ids' => ['nullable', 'array'],
            'landing_ids.*' => ['integer', Rule::exists('landings', 'id')->where('is_active', true)],
            'boat_ids' => ['nullable', 'array'],
            'boat_ids.*' => ['integer', Rule::exists('boats', 'id')->where('is_active', true)],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $regionIds = collect($this->input('region_ids', []))->map(fn ($id): int => (int) $id);
                $landingIds = collect($this->input('landing_ids', []))->map(fn ($id): int => (int) $id);
                $boatIds = collect($this->input('boat_ids', []))->map(fn ($id): int => (int) $id);

                if ($landingIds->isNotEmpty() && Landing::query()->whereIn('id', $landingIds)->whereNotIn('region_id', $regionIds)->exists()) {
                    $validator->errors()->add('landing_ids', 'Selected landings must belong to the selected regions.');
                }

                if ($boatIds->isNotEmpty() && Boat::query()
                    ->whereIn('boats.id', $boatIds)
                    ->leftJoin('landings', 'landings.id', '=', 'boats.landing_id')
                    ->whereNotIn('landings.region_id', $regionIds)
                    ->exists()) {
                    $validator->errors()->add('boat_ids', 'Selected boats must belong to landings in the selected regions.');
                }
            },
        ];
    }
}
