<?php

namespace Database\Factories;

use App\Enums\ParserReportOverrideStatus;
use App\Models\ParserBugReport;
use App\Models\ParserDiagnosticReview;
use App\Models\ParserReportOverride;
use App\Models\RawScrapePayload;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParserReportOverride>
 */
class ParserReportOverrideFactory extends Factory
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
            'parser_diagnostic_review_id' => fn (): int => ParserDiagnosticReview::query()->firstOrFail()->id,
            'parser_bug_report_id' => fn (): int => ParserBugReport::query()->firstOrFail()->id,
            'review_attempt' => 0,
            'report_index' => 0,
            'report_fingerprint' => hash('sha256', fake()->unique()->uuid()),
            'paragraph_fingerprint' => hash('sha256', fake()->unique()->uuid()),
            'parser_version' => 'parser-v1',
            'correction_schema_version' => 'v1',
            'status' => ParserReportOverrideStatus::Pending,
            'corrections' => [],
            'original_parse' => [],
            'corrected_parse' => [],
            'affected_scope' => [],
            'created_by_user_id' => User::factory(),
            'created_by_name' => fake()->name(),
            'created_by_email' => fake()->safeEmail(),
        ];
    }
}
