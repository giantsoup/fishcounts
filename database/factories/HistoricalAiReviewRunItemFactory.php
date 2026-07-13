<?php

namespace Database\Factories;

use App\Models\HistoricalAiReviewRun;
use App\Models\HistoricalAiReviewRunItem;
use App\Models\RawScrapePayload;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HistoricalAiReviewRunItem>
 */
class HistoricalAiReviewRunItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'historical_ai_review_run_id' => HistoricalAiReviewRun::factory(),
            'raw_scrape_payload_id' => RawScrapePayload::query()->value('id'),
            'payload_hash' => hash('sha256', fake()->uuid()),
            'item_fingerprint' => hash('sha256', fake()->uuid()),
        ];
    }
}
