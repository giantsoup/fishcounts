<?php

namespace Database\Factories;

use App\Enums\ParserBugReportStatus;
use App\Models\ParserBugReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParserBugReport>
 */
class ParserBugReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'signature' => fn (): string => hash('sha256', fake()->unique()->uuid()),
            'source_slug' => 'fishermans_landing',
            'status' => ParserBugReportStatus::Preview,
            'title' => '[Parser][fishermans_landing] Incorrect parser boundary',
            'body' => 'Sanitized parser bug preview.',
            'labels' => ['parser-bug', 'llm-detected'],
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
