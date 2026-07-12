<?php

namespace Tests\Unit;

use App\DTOs\ParsedFishCountCollection;
use App\DTOs\ParsedReportValidationData;
use App\DTOs\ParsedSpeciesCountData;
use App\DTOs\ParsedTripReportData;
use App\DTOs\RawPayloadData;
use App\Enums\ParserDiagnosticType;
use App\Services\Parsing\Rules\EmptyOrUnexpectedlySmallResultSetRule;
use App\Services\Parsing\Rules\ExcessiveNameLengthRule;
use App\Services\Parsing\Rules\ExtractedValueSourceSpanMismatchRule;
use App\Services\Parsing\Rules\FractionalTripConflictRule;
use App\Services\Parsing\Rules\ProseCapturedAsEntityRule;
use App\Services\Parsing\Rules\StructuredSourceFallbackRule;
use App\Services\Parsing\Rules\UnaccountedNumericTokensRule;
use App\Services\Parsing\Rules\UnknownAliasRule;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ParsedReportValidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_alias_rule_detects_unknown_values_and_accepts_known_values(): void
    {
        $this->seed(DatabaseSeeder::class);
        $rule = app(UnknownAliasRule::class);

        $unknown = $rule->inspect($this->data($this->report(species: 'Moon Fish'), '20 anglers | 4 Moon Fish'));
        $known = $rule->inspect($this->data($this->report(species: 'Rockfish'), '20 anglers | 4 Rockfish'));

        $this->assertSame(ParserDiagnosticType::UnknownAlias, $unknown[0]->type);
        $this->assertSame('species', $unknown[0]->field);
        $this->assertSame([], $known);
    }

    public function test_fractional_trip_rule_detects_conflicts_and_accepts_valid_fractions_and_decimals(): void
    {
        $rule = app(FractionalTripConflictRule::class);

        $conflict = $rule->inspect($this->data($this->report(tripType: 'Unknown'), 'Sea Watch | 3/4 Day | 20 anglers | 4 Rockfish'));
        $validHalfDay = $rule->inspect($this->data($this->report(tripType: '1/2 Day AM'), 'Dolphin | 1/2 Day AM | 20 anglers | 4 Rockfish'));
        $validThreeQuarterDay = $rule->inspect($this->data($this->report(tripType: '3/4 Day'), 'Sea Watch | 3/4 Day | 20 anglers | 4 Rockfish'));
        $validDecimal = $rule->inspect($this->data($this->report(tripType: '1.5 Day'), 'Pacific Queen | 1.5 Day | 20 anglers | 4 Rockfish'));

        $this->assertSame(ParserDiagnosticType::FractionalTripConflict, $conflict[0]->type);
        $this->assertSame([], $validHalfDay);
        $this->assertSame([], $validThreeQuarterDay);
        $this->assertSame([], $validDecimal);
    }

    public function test_prose_rule_detects_sentence_fragments_and_accepts_entity_names(): void
    {
        $rule = app(ProseCapturedAsEntityRule::class);

        $prose = $rule->inspect($this->data($this->report(species: 'Day Trip Finished With'), '20 anglers | 4 Day Trip Finished With'));
        $clean = $rule->inspect($this->data($this->report(species: 'Vermilion Rockfish'), '20 anglers | 4 Vermilion Rockfish'));

        $this->assertSame(ParserDiagnosticType::ProseCapturedAsEntity, $prose[0]->type);
        $this->assertSame([], $clean);
    }

    public function test_excessive_name_rule_uses_utf8_length_and_accepts_names_at_the_limit(): void
    {
        config()->set('fish.parsing.diagnostics.max_entity_name_length', 12);
        $rule = app(ExcessiveNameLengthRule::class);

        $tooLong = $rule->inspect($this->data($this->report(species: Str::repeat('é', 13)), '20 anglers | 4 fish'));
        $atLimit = $rule->inspect($this->data($this->report(species: Str::repeat('é', 12)), '20 anglers | 4 fish'));

        $this->assertSame(13, $tooLong[0]->evidence['length']);
        $this->assertSame([], $atLimit);
    }

    public function test_numeric_token_rule_detects_unaccounted_values_and_accepts_known_numeric_spans(): void
    {
        $rule = app(UnaccountedNumericTokensRule::class);

        $unaccounted = $rule->inspect($this->data($this->report(), 'Dolphin | Full Day | 20 anglers | 4 Rockfish | code 99'));
        $validFraction = $rule->inspect($this->data($this->report(tripType: '3/4 Day'), 'Dolphin | 3/4 Day | 20 anglers | 4 Rockfish'));
        $validDecimal = $rule->inspect($this->data($this->report(tripType: '1.5 Day'), 'Dolphin | 1.5 Day | 20 anglers | 4 Rockfish at 12 lbs'));

        $this->assertSame(['99'], $unaccounted[0]->evidence['unaccounted_tokens']);
        $this->assertSame([], $validFraction);
        $this->assertSame([], $validDecimal);
    }

    public function test_low_result_rule_requires_source_specific_missing_report_evidence(): void
    {
        $rule = app(EmptyOrUnexpectedlySmallResultSetRule::class);
        $empty = new ParsedFishCountCollection(collect(), 'parser-v2', 'narrative');
        $base = $this->data(null, 'The Pacific Queen returned with 49 Yellowtail for 30 anglers.', $empty);
        $missing = new ParsedReportValidationData(
            payload: $base->payload,
            parsed: $empty,
            report: null,
            reportIndex: 0,
            parserVersion: 'parser-v2',
            format: 'narrative',
            sourceIdentifier: 'paragraph:0',
            sanitizedParagraph: $base->sanitizedParagraph,
            sourceEvidence: ['has_missing_report' => true, 'candidate_label' => 'Pacific Queen'],
        );

        $this->assertSame(ParserDiagnosticType::EmptyOrUnexpectedlySmallResultSet, $rule->inspect($missing)[0]->type);
        $this->assertSame([], $rule->inspect($base));
    }

    public function test_structured_fallback_rule_ignores_expected_business_fallback_rows(): void
    {
        $rule = app(StructuredSourceFallbackRule::class);
        $parserFallback = $this->report(metadata: ['parser' => 'source-v2', 'fallback_parser' => 'generic-line-v2']);
        $businessFallback = $this->report(metadata: ['parser' => 'source-v2', 'source_role' => 'fallback']);

        $this->assertSame(ParserDiagnosticType::StructuredSourceFallback, $rule->inspect($this->data($parserFallback, '20 anglers | 4 Rockfish', sourceKey: 'hm_landing'))[0]->type);
        $this->assertSame([], $rule->inspect($this->data($businessFallback, '20 anglers | 4 Rockfish', sourceKey: 'hm_landing')));
        $this->assertSame([], $rule->inspect($this->data($parserFallback, '20 anglers | 4 Rockfish')));
    }

    public function test_source_span_rule_detects_anglers_and_count_mismatches_and_accepts_clean_spans(): void
    {
        $rule = app(ExtractedValueSourceSpanMismatchRule::class);
        $swapped = $this->report(
            anglers: 38,
            species: 'Calico Bass',
            retained: 25,
            released: 21,
            rawText: '25 Calico Bass Released, 21 Calico Bass',
        );
        $clean = $this->report(
            anglers: 20,
            species: 'Calico Bass',
            retained: 21,
            released: 25,
            rawText: '25 Calico Bass Released, 21 Calico Bass',
        );
        $paragraph = 'Dolphin | 20 anglers | 25 Calico Bass Released, 21 Calico Bass';
        $mismatches = $rule->inspect($this->data($swapped, $paragraph));

        $this->assertSame(['anglers', 'species_counts'], array_column($mismatches, 'field'));
        $this->assertSame([], $rule->inspect($this->data($clean, $paragraph)));
    }

    private function data(
        ?ParsedTripReportData $report,
        string $paragraph,
        ?ParsedFishCountCollection $parsed = null,
        string $sourceKey = 'fishermans_landing',
    ): ParsedReportValidationData {
        $payload = new RawPayloadData(
            sourceKey: $sourceKey,
            targetDate: CarbonImmutable::parse('2026-07-12'),
            url: 'https://www.fishermanslanding.com/fishcounts.php?token=secret',
            body: "<p>{$paragraph}</p>",
        );
        $parsed ??= new ParsedFishCountCollection(collect(array_filter([$report])), 'source-v2', 'narrative');

        return new ParsedReportValidationData(
            payload: $payload,
            parsed: $parsed,
            report: $report,
            reportIndex: $report === null ? null : 0,
            parserVersion: 'source-v2',
            format: 'narrative',
            sourceIdentifier: null,
            sanitizedParagraph: $paragraph,
        );
    }

    /** @param array<string, mixed> $metadata */
    private function report(
        string $tripType = 'Full Day',
        int $anglers = 20,
        string $species = 'Rockfish',
        int $retained = 4,
        int $released = 0,
        ?string $rawText = '4 Rockfish',
        array $metadata = ['parser' => 'source-v2'],
    ): ParsedTripReportData {
        return new ParsedTripReportData(
            sourceKey: 'fishermans_landing',
            tripDate: CarbonImmutable::parse('2026-07-12'),
            regionName: 'San Diego',
            landingName: null,
            boatName: null,
            tripTypeName: $tripType,
            anglers: $anglers,
            rawFishCountText: $rawText,
            speciesCounts: [new ParsedSpeciesCountData($species, $retained, $released, $rawText)],
            metadata: $metadata,
        );
    }
}
