<?php

namespace Tests\Feature;

use App\DTOs\RawPayloadData;
use App\Services\Parsing\AiParserDocumentSanitizer;
use App\Services\Parsing\SourceFishCountDocumentScope;
use App\Services\Parsing\SourceSpecificFishCountParser;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class SanDiegoFishReportsScopeTest extends TestCase
{
    public function test_aggregate_only_page_does_not_produce_individual_reports(): void
    {
        $payload = $this->payload($this->aggregateHtml());
        $parsed = app(SourceSpecificFishCountParser::class)->parse($payload);
        $scopedHtml = app(SourceFishCountDocumentScope::class)->forPayload($payload);

        $this->assertCount(0, $parsed->tripReports);
        $this->assertSame('source-specific-sandiego_fish_reports-v7', $parsed->parserVersion);
        $this->assertStringNotContainsString('rf-dhist-table', $scopedHtml);
        $this->assertStringNotContainsString('rf-dtot-table', $scopedHtml);
        $this->assertStringNotContainsString('rf-dock-card', $scopedHtml);
    }

    public function test_individual_report_is_preserved_alongside_dock_totals_and_history(): void
    {
        $individualHtml = <<<'HTML'
            <table>
                <tr><th>Boat</th><th>Trip</th><th>Anglers</th><th>Fish Count</th></tr>
                <tr><td>Mission Belle</td><td>Full Day</td><td>28</td><td>16 Yellowtail, 8 Calico Bass Released</td></tr>
            </table>
        HTML;
        $payload = $this->payload(str_replace('</body>', $individualHtml.'</body>', $this->aggregateHtml()));
        $parsed = app(SourceSpecificFishCountParser::class)->parse($payload);

        $this->assertCount(1, $parsed->tripReports);
        $report = $parsed->tripReports->sole();
        $this->assertSame('Mission Belle', $report->boatName);
        $this->assertSame('Full Day', $report->tripTypeName);
        $this->assertSame('2026-09-08', $report->tripDate->toDateString());
        $this->assertSame(28, $report->anglers);
        $this->assertSame(['Yellowtail', 'Calico Bass'], collect($report->speciesCounts)->pluck('speciesName')->all());
        $this->assertSame([16, 0], collect($report->speciesCounts)->pluck('count')->all());
        $this->assertSame([0, 8], collect($report->speciesCounts)->pluck('releasedCount')->all());

        $sanitized = app(AiParserDocumentSanitizer::class)->sanitize($payload);
        $this->assertStringContainsString('Mission Belle', $sanitized);
        $this->assertStringNotContainsString('September', $sanitized);
        $this->assertStringNotContainsString('317,867', $sanitized);
        $this->assertStringNotContainsString('60 Bonito', $sanitized);
    }

    public function test_standalone_history_table_does_not_reach_the_generic_fallback(): void
    {
        $payload = $this->payload(<<<'HTML'
            <table class="table rf-dhist-table">
                <tr><th>Month</th><th>Boats</th><th>Trips</th><th>Anglers</th><th>Fish</th><th>Per Angler</th></tr>
                <tr><td>September</td><td>50</td><td>202</td><td>5,260</td><td>20,722 +878 rel</td><td>3.94</td></tr>
                <tr><td>2026 total</td><td>&mdash;</td><td>3,980</td><td>101,240</td><td>317,867</td><td>3.14</td></tr>
            </table>
        HTML);

        $this->assertCount(0, app(SourceSpecificFishCountParser::class)->parse($payload)->tripReports);
    }

    private function aggregateHtml(): string
    {
        return file_get_contents(base_path('tests/Fixtures/Parsing/sandiego-dock-totals-history.html'));
    }

    private function payload(string $body): RawPayloadData
    {
        return new RawPayloadData(
            sourceKey: 'sandiego_fish_reports',
            targetDate: CarbonImmutable::parse('2026-09-08'),
            url: 'https://www.sandiegofishreports.com/dock_totals/index.php?date=2026-09-08',
            body: $body,
        );
    }
}
