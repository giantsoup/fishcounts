<?php

namespace Database\Factories;

use App\Enums\ScrapeRunType;
use App\Models\RawScrapePayload;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RawScrapePayload>
 */
class RawScrapePayloadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $payload = '<html><body><p>Synthetic fish count report.</p></body></html>';

        return [
            'scrape_source_id' => ScrapeSource::factory(),
            'scrape_run_id' => fn (array $attributes): int => ScrapeRun::query()->create([
                'scrape_source_id' => $attributes['scrape_source_id'],
                'run_type' => ScrapeRunType::Daily,
                'target_date' => $attributes['target_date'],
            ])->id,
            'target_date' => '2026-07-01',
            'url' => fake()->url(),
            'http_status' => 200,
            'content_type' => 'text/html',
            'payload' => $payload,
            'payload_hash' => fn (): string => hash('sha256', fake()->unique()->uuid()),
            'fetched_at' => now(),
        ];
    }
}
