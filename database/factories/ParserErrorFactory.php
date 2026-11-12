<?php

namespace Database\Factories;

use App\Enums\ParserErrorResolutionType;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParserError>
 */
class ParserErrorFactory extends Factory
{
    /**
     * Define the model's default state. The default is an open alias error for an unknown boat.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'raw_scrape_payload_id' => RawScrapePayload::factory(),
            'scrape_source_id' => fn (array $attributes): int => RawScrapePayload::query()
                ->findOrFail($attributes['raw_scrape_payload_id'])
                ->scrape_source_id,
            'target_date' => fn (array $attributes): string => RawScrapePayload::query()
                ->findOrFail($attributes['raw_scrape_payload_id'])
                ->target_date
                ->toDateString(),
            'error_type' => 'unknown_boat_alias',
            'raw_field' => 'boat',
            'raw_value' => fake()->unique()->lastName(),
            'message' => 'Boat alias was not recognized.',
            'context' => ['paragraph' => 'Synthetic report paragraph.'],
        ];
    }

    public function structural(string $errorType = 'unaccounted_numeric_tokens'): static
    {
        return $this->state(fn (array $attributes): array => [
            'error_type' => $errorType,
            'raw_field' => null,
            'raw_value' => null,
            'message' => 'The parser left numeric tokens unaccounted for.',
        ]);
    }

    public function resolved(ParserErrorResolutionType $type = ParserErrorResolutionType::Alias): static
    {
        return $this->state(fn (array $attributes): array => [
            'resolved_at' => now(),
            'resolution_type' => $type,
        ]);
    }
}
