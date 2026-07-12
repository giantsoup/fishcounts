<?php

namespace Database\Factories;

use App\Enums\AiBudgetPeriodType;
use App\Models\AiBudgetPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiBudgetPeriod>
 */
class AiBudgetPeriodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'openai',
            'period_type' => AiBudgetPeriodType::Monthly,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'limit_micros' => 50000000,
        ];
    }
}
