<?php

namespace Database\Factories;

use App\Models\ParserDiagnosticReview;
use App\Models\RawScrapePayload;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParserDiagnosticReview>
 */
class ParserDiagnosticReviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'raw_scrape_payload_id' => fn (): int => RawScrapePayload::query()->firstOrFail()->id,
            'payload_hash' => fn (): string => RawScrapePayload::query()->firstOrFail()->payload_hash,
            'diagnostic_fingerprint' => fn (): string => hash('sha256', fake()->unique()->uuid()),
            'model' => 'gpt-5.6-luna',
            'prompt_version' => 'v1',
            'schema_version' => 'v1',
        ];
    }
}
