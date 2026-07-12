<?php

namespace Database\Factories;

use App\Models\AiBudgetPeriod;
use App\Models\AiBudgetReservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiBudgetReservation>
 */
class AiBudgetReservationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_budget_period_id' => AiBudgetPeriod::factory(),
            'reservation_key' => fake()->unique()->uuid(),
            'reserved_micros' => 100000,
            'reserved_at' => now(),
        ];
    }
}
