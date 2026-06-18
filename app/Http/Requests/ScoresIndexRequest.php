<?php

namespace App\Http\Requests;

use App\Enums\ScoreLevel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ScoresIndexRequest extends FormRequest
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
            'alert_rule_id' => ['nullable', 'integer', Rule::exists('alert_rules', 'id')->where('user_id', $this->user()?->id)],
            'level' => ['nullable', Rule::enum(ScoreLevel::class)],
            'minimum_score' => ['nullable', 'integer', 'min:0', 'max:100'],
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

    /** @return array{from: string, to: string, alert_rule_id: ?int, level: ?string, minimum_score: ?int} */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'from' => $this->dateFilter('from')?->toDateString() ?? now()->subDays(30)->toDateString(),
            'to' => $this->dateFilter('to')?->toDateString() ?? now()->toDateString(),
            'alert_rule_id' => isset($validated['alert_rule_id']) ? (int) $validated['alert_rule_id'] : null,
            'level' => isset($validated['level']) ? (string) $validated['level'] : null,
            'minimum_score' => isset($validated['minimum_score']) ? (int) $validated['minimum_score'] : null,
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
