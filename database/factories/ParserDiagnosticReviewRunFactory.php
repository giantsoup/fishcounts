<?php

namespace Database\Factories;

use App\Enums\ParserDiagnosticReviewRunStatus;
use App\Models\ParserDiagnosticReviewRun;
use App\Models\RawScrapePayload;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParserDiagnosticReviewRun>
 */
class ParserDiagnosticReviewRunFactory extends Factory
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
            'requested_by_user_id' => fn (): int => User::factory()->admin()->create()->id,
            'status' => ParserDiagnosticReviewRunStatus::Queued,
        ];
    }
}
