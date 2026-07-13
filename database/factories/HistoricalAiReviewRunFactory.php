<?php

namespace Database\Factories;

use App\Enums\HistoricalAiReviewRunStatus;
use App\Models\HistoricalAiReviewRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HistoricalAiReviewRun>
 */
class HistoricalAiReviewRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scope' => 'historical',
            'status' => HistoricalAiReviewRunStatus::Pending,
            'date_from' => now()->subMonth()->toDateString(),
            'date_to' => now()->toDateString(),
            'max_items' => 10,
            'budget_micros' => 5_000_000,
            'estimated_item_cost_micros' => 1_000_000,
            'authorization_reference' => fake()->safeEmail(),
            'selection_fingerprint' => hash('sha256', fake()->uuid()),
            'selected_count' => 0,
        ];
    }
}
