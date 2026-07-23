<?php

namespace Tests\Unit;

use App\DTOs\ParsedFishCountCollection;
use App\DTOs\ParsedReportValidationData;
use App\DTOs\ParsedSpeciesCountData;
use App\DTOs\ParsedTripReportData;
use App\DTOs\RawPayloadData;
use App\Enums\ParserDiagnosticType;
use App\Services\Parsing\GenericFishCountParser;
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
        $canonical = $rule->inspect($this->data(
            $this->report(
                tripType: 'Narrative Trip Phrase',
                species: 'Narrative Fish Phrase',
                canonicalBoatId: 1,
                canonicalTripTypeId: 1,
                canonicalSpeciesId: 1,
            ),
            'Narrative Boat Phrase | Narrative Trip Phrase | 20 anglers | 4 Narrative Fish Phrase',
        ));

        $this->assertSame(ParserDiagnosticType::UnknownAlias, $unknown[0]->type);
        $this->assertSame('species', $unknown[0]->field);
        $this->assertSame([], $known);
        $this->assertSame([], $canonical);
    }

    public function test_fractional_trip_rule_detects_conflicts_and_accepts_valid_fractions_and_decimals(): void
    {
        $rule = app(FractionalTripConflictRule::class);

        $conflict = $rule->inspect($this->data($this->report(tripType: 'Unknown'), 'Sea Watch | 3/4 Day | 20 anglers | 4 Rockfish'));
        $validHalfDay = $rule->inspect($this->data($this->report(tripType: '1/2 Day AM'), 'Dolphin | 1/2 Day AM | 20 anglers | 4 Rockfish'));
        $validThreeQuarterDay = $rule->inspect($this->data($this->report(tripType: '3/4 Day'), 'Sea Watch | 3/4 Day | 20 anglers | 4 Rockfish'));
        $validModifiedThreeQuarterDay = $rule->inspect($this->data($this->report(tripType: 'Local 3/4 Day'), 'Sea Watch | Local 3/4 Day | 20 anglers | 4 Rockfish'));
        $validDecimal = $rule->inspect($this->data($this->report(tripType: '1.5 Day'), 'Pacific Queen | 1.5 Day | 20 anglers | 4 Rockfish'));

        $this->assertSame(ParserDiagnosticType::FractionalTripConflict, $conflict[0]->type);
        $this->assertSame([], $validHalfDay);
        $this->assertSame([], $validThreeQuarterDay);
        $this->assertSame([], $validModifiedThreeQuarterDay);
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
        $validPackCapacity = $rule->inspect($this->data($this->report(), 'Dolphin | Full Day 6 pack charter | 20 anglers | 4 Rockfish'));
        $validHyphenatedPackCapacity = $rule->inspect($this->data($this->report(), 'Dolphin | Full Day 6-pack charter | 20 anglers | 4 Rockfish'));
        $validStructuredRow = $rule->inspect($this->data($this->report(), 'Dolphin | Full Day | 20 | 4 Rockfish |', format: 'structured-table'));
        $unparsedStructuredCount = $rule->inspect($this->data($this->report(), 'Dolphin | Full Day | 20 | 4 Rockfish, 20 Dorado |', format: 'structured-table'));
        $duplicateStructuredToken = $rule->inspect($this->data($this->report(), 'Dolphin | Full Day | 20 | 4 Rockfish | 20 |', format: 'structured-table'));
        $narrativeBareToken = $rule->inspect($this->data($this->report(), 'Dolphin | Full Day | 20 anglers | 4 Rockfish | 20 |'));

        $this->assertSame(['99'], $unaccounted[0]->evidence['unaccounted_tokens']);
        $this->assertSame([], $validFraction);
        $this->assertSame([], $validDecimal);
        $this->assertSame([], $validPackCapacity);
        $this->assertSame([], $validHyphenatedPackCapacity);
        $this->assertSame([], $validStructuredRow);
        $this->assertSame(['20'], $unparsedStructuredCount[0]->evidence['unaccounted_tokens']);
        $this->assertSame(['20'], $duplicateStructuredToken[0]->evidence['unaccounted_tokens']);
        $this->assertSame(['20'], $narrativeBareToken[0]->evidence['unaccounted_tokens']);
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

    public function test_numeric_and_source_span_rules_accept_parenthetical_released_counts(): void
    {
        $report = $this->report(
            anglers: 53,
            species: 'Calico Bass',
            retained: 8,
            released: 60,
            rawText: '8 Calico Bass, 60 Calico Bass Released',
        );
        $paragraph = 'The Dolphin AM trip had 8 Calico Bass (60 released) for 53 anglers.';
        $data = $this->data($report, $paragraph);

        $this->assertSame([], app(UnaccountedNumericTokensRule::class)->inspect($data));
        $this->assertSame([], app(ExtractedValueSourceSpanMismatchRule::class)->inspect($data));

        $commaParagraph = 'The Dolphin PM trip had 8 Calico Bass, (60 released) for 53 anglers.';
        $commaData = $this->data($report, $commaParagraph);

        $this->assertSame([], app(UnaccountedNumericTokensRule::class)->inspect($commaData));
        $this->assertSame([], app(ExtractedValueSourceSpanMismatchRule::class)->inspect($commaData));
    }

    public function test_numeric_and_source_span_rules_accept_a_follow_up_release_sentence(): void
    {
        $report = $this->report(
            anglers: 38,
            species: 'Calico Bass',
            retained: 26,
            released: 100,
            rawText: '26 Calico Bass, 100 Calico Bass Released',
        );
        $data = $this->data($report, 'The Sea Watch caught 26 Calico Bass. 100 were released for 38 anglers.');

        $this->assertSame([], app(UnaccountedNumericTokensRule::class)->inspect($data));
        $this->assertSame([], app(ExtractedValueSourceSpanMismatchRule::class)->inspect($data));
    }

    public function test_numeric_and_source_span_rules_accept_normalized_typos_and_limits_notation(): void
    {
        $typoReport = $this->report(
            anglers: 35,
            species: 'Calico Bass',
            retained: 20,
            released: 50,
            rawText: '20 Calico Bass, 50 Calico Bass Released',
        );
        $typoData = $this->data($typoReport, 'The Dolphin PM trip had 20 Cakico Bass (50 released) for 35 anglers.');
        $limitsReport = $this->report(
            tripType: '3 Day',
            anglers: 16,
            species: 'Bluefin Tuna',
            retained: 96,
            rawText: '96 Bluefin Tuna',
        );
        $limitsData = $this->data($limitsReport, 'The Pegasus returned with LIMITS (96) of Bluefin Tuna (up to 160 lbs.) for 16 anglers on a 3 day trip.');

        $this->assertSame([], app(UnaccountedNumericTokensRule::class)->inspect($typoData));
        $this->assertSame([], app(ExtractedValueSourceSpanMismatchRule::class)->inspect($typoData));
        $this->assertSame([], app(UnaccountedNumericTokensRule::class)->inspect($limitsData));
        $this->assertSame([], app(ExtractedValueSourceSpanMismatchRule::class)->inspect($limitsData));
    }

    public function test_numeric_and_source_span_rules_accept_production_count_grammar(): void
    {
        $paragraphs = [
            'The Pegasus called in with 32 Bluefin Tuna (80lbs - 150lbs) and 1 @ 200lbs and 4 Yellowfin Tuna for 19 anglers on their 2 day trip.',
            'The Dolphin caught 44 Calico (Kelp) Bass and released 70, 19 Rockfish for 22 anglers.',
            'The Dolphin caught limits of Calico (Kelp) Bass for 22 anglers, so 105 kept in total, and 200 released, 20 Barracuda.',
            'The Lucky B caught Limits of Yellowfin Tuna (15), 6 Yellowtail for 3 anglers.',
            'The Pacific Queen returned with 31 Bluefin Tuna (70 to 170), and 1 20lbs Yellowtail for 29 anglers.',
        ];

        foreach ($paragraphs as $paragraph) {
            $payload = new RawPayloadData(
                sourceKey: 'fishermans_landing',
                targetDate: CarbonImmutable::parse('2026-07-12'),
                url: 'https://www.fishermanslanding.com/fishcounts.php',
                body: "<p>{$paragraph}</p>",
            );
            $parsed = app(GenericFishCountParser::class)->parse($payload);
            $report = $parsed->tripReports->first();
            $data = new ParsedReportValidationData(
                payload: $payload,
                parsed: $parsed,
                report: $report,
                reportIndex: 0,
                parserVersion: $parsed->parserVersion ?? '',
                format: $parsed->format ?? '',
                sourceIdentifier: null,
                sanitizedParagraph: $paragraph,
            );

            $this->assertNotNull($report, $paragraph);
            $this->assertSame([], app(UnaccountedNumericTokensRule::class)->inspect($data), $paragraph);
            $this->assertSame([], app(ExtractedValueSourceSpanMismatchRule::class)->inspect($data), $paragraph);
        }
    }

    public function test_source_span_rule_still_detects_an_incorrect_normalized_total(): void
    {
        $paragraph = 'The Pegasus called in with 32 Bluefin Tuna (80lbs - 150lbs) and 1 @ 200lbs for 19 anglers on their 2 day trip.';
        $incorrectReport = $this->report(
            tripType: '2 Day',
            anglers: 19,
            species: 'Bluefin Tuna',
            retained: 32,
            rawText: '32 Bluefin Tuna',
        );
        $data = $this->data($incorrectReport, $paragraph);

        $sourceSpanFindings = app(ExtractedValueSourceSpanMismatchRule::class)->inspect($data);

        $this->assertCount(1, $sourceSpanFindings);
        $this->assertSame(33, $sourceSpanFindings[0]->evidence['mismatches'][0]['source_retained']);
    }

    private function data(
        ?ParsedTripReportData $report,
        string $paragraph,
        ?ParsedFishCountCollection $parsed = null,
        string $sourceKey = 'fishermans_landing',
        string $format = 'narrative',
    ): ParsedReportValidationData {
        $payload = new RawPayloadData(
            sourceKey: $sourceKey,
            targetDate: CarbonImmutable::parse('2026-07-12'),
            url: 'https://www.fishermanslanding.com/fishcounts.php?token=secret',
            body: "<p>{$paragraph}</p>",
        );
        $parsed ??= new ParsedFishCountCollection(collect(array_filter([$report])), 'source-v2', $format);

        return new ParsedReportValidationData(
            payload: $payload,
            parsed: $parsed,
            report: $report,
            reportIndex: $report === null ? null : 0,
            parserVersion: 'source-v2',
            format: $format,
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
        ?int $canonicalBoatId = null,
        ?int $canonicalTripTypeId = null,
        ?int $canonicalSpeciesId = null,
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
            speciesCounts: [new ParsedSpeciesCountData($species, $retained, $released, $rawText, $canonicalSpeciesId)],
            metadata: $metadata,
            canonicalBoatId: $canonicalBoatId,
            canonicalTripTypeId: $canonicalTripTypeId,
        );
    }
}
