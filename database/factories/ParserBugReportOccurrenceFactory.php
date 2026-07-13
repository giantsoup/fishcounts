<?php

namespace Database\Factories;

use App\Models\ParserBugReport;
use App\Models\ParserBugReportOccurrence;
use App\Models\ParserDiagnosticReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParserBugReportOccurrence>
 */
class ParserBugReportOccurrenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parser_bug_report_id' => ParserBugReport::factory(),
            'parser_diagnostic_review_id' => fn (): ?int => ParserDiagnosticReview::query()->first()?->id,
            'review_attempt' => 0,
            'seen_at' => now(),
        ];
    }
}
