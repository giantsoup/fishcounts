<?php

namespace Tests\Unit;

use JsonException;
use PHPUnit\Framework\TestCase;

class ParsingEvaluationCorpusTest extends TestCase
{
    public function test_every_phase_zero_failure_category_has_a_manually_reviewed_expected_result(): void
    {
        $corpus = $this->corpus();
        $categories = collect($corpus['fixtures'])->pluck('categories')->flatten()->unique();
        $fixtureIds = collect($corpus['fixtures'])->pluck('id');
        $provenance = collect($corpus['fixtures'])->pluck('provenance')->flatten()->unique();

        $this->assertSame($fixtureIds->count(), $fixtureIds->unique()->count(), 'Evaluation fixture IDs must be unique.');

        foreach ([
            'unknown_legitimate_alias',
            'new_entity_candidate',
            'sentence_fragment_as_species',
            'sentence_fragment_as_boat_name',
            'fractional_trip_type',
            'incorrect_anglers',
            'incorrect_retained_count',
            'incorrect_released_count',
            'report_disappears',
            'clean_structured_report',
            'clean_narrative_report',
        ] as $requiredCategory) {
            $this->assertTrue($categories->contains($requiredCategory), "Missing evaluation category [{$requiredCategory}].");
        }

        foreach ([
            'current_unresolved_parser_error',
            'corrected_parser_migration',
            'existing_test',
            'known_silent_failure',
        ] as $requiredProvenance) {
            $this->assertTrue($provenance->contains($requiredProvenance), "Missing evaluation provenance [{$requiredProvenance}].");
        }

        $fractionalFixtures = collect($corpus['fixtures'])
            ->filter(fn (array $fixture): bool => in_array('fractional_trip_type', $fixture['categories'], true));

        $this->assertTrue($fractionalFixtures->contains(fn (array $fixture): bool => str_contains($fixture['input']['sanitized_text'], '1/2 Day')));
        $this->assertTrue($fractionalFixtures->contains(fn (array $fixture): bool => str_contains($fixture['input']['sanitized_text'], '3/4 Day')));

        foreach ($corpus['fixtures'] as $fixture) {
            $this->assertTrue($fixture['manually_reviewed'], "Fixture [{$fixture['id']}] has not been manually reviewed.");
            $this->assertTrue($fixture['privacy_reviewed'], "Fixture [{$fixture['id']}] has not passed privacy review.");
            $this->assertNotEmpty($fixture['provenance']);
            $this->assertNotSame('', $fixture['expected']['classification']);
            $this->assertContains($fixture['expected']['classification'], [
                'legitimate_alias',
                'new_entity_candidate',
                'parser_boundary_error',
                'fractional_trip_conflict',
                'value_extraction_error',
                'missing_report',
                'clean',
            ]);
            $this->assertArrayHasKey('diagnostics', $fixture['expected']);
            $this->assertNotEmpty($fixture['expected']['corrected_parse']['reports']);
            $this->assertIsBool($fixture['expected']['github_issue']['create']);
            $this->assertNotSame('', $fixture['expected']['github_issue']['reason']);

            if ($fixture['expected']['classification'] === 'clean') {
                $this->assertSame([], $fixture['expected']['diagnostics']);
                $this->assertFalse($fixture['expected']['github_issue']['create']);
                $this->assertSame($fixture['input']['deterministic_parse']['reports'], $fixture['expected']['corrected_parse']['reports']);
            } else {
                $this->assertNotEmpty($fixture['expected']['diagnostics']);
            }

            if ($fixture['expected']['canonical_target'] !== null) {
                $this->assertContains($fixture['expected']['canonical_target']['type'], ['boat', 'species', 'trip_type']);
                $this->assertNotSame('', $fixture['expected']['canonical_target']['key']);
                $this->assertNotSame('', $fixture['expected']['canonical_target']['name']);
            }
        }
    }

    public function test_shadow_metrics_have_reproducible_population_formulas_and_measurement_rules(): void
    {
        $metrics = $this->corpus()['shadow_metrics'];

        $this->assertSame([
            'diagnostic_recall',
            'clean_false_positive_rate',
            'human_classification_agreement',
            'invalid_schema_rate',
            'invalid_canonical_id_rate',
            'average_latency',
            'average_token_cost',
        ], array_keys($metrics));

        foreach ($metrics as $name => $metric) {
            $this->assertNotSame('', $metric['numerator'], "Metric [{$name}] needs a numerator.");
            $this->assertNotSame('', $metric['denominator'], "Metric [{$name}] needs a denominator.");
            $this->assertNotSame('', $metric['unit'], "Metric [{$name}] needs a unit.");
            $this->assertContains($metric['direction'], ['maximize', 'minimize']);
            $this->assertContains($metric['quality_gate'], ['set_after_baseline', 'baseline_only']);
        }

        $this->assertSame('maximize', $metrics['diagnostic_recall']['direction']);
        $this->assertSame('minimize', $metrics['clean_false_positive_rate']['direction']);
        $this->assertSame('maximize', $metrics['human_classification_agreement']['direction']);
        $this->assertSame('minimize', $metrics['invalid_schema_rate']['direction']);
        $this->assertSame('minimize', $metrics['invalid_canonical_id_rate']['direction']);
    }

    public function test_architecture_decisions_keep_ai_optional_and_processing_new_payloads_first(): void
    {
        $corpus = $this->corpus();
        $decisions = $corpus['architecture_decisions'];

        $this->assertSame('new_payloads_first', $corpus['scope']);
        $this->assertSame('non_blocking', $decisions['fallback']);
        $this->assertSame('after_shadow_validation', $decisions['historical_scope']);
        $this->assertSame('measured_results_not_model_self_report', $decisions['confidence_policy']);
    }

    public function test_architecture_decisions_use_one_approval_per_phase_administrator_review_and_laravel_http(): void
    {
        $decisions = $this->corpus()['architecture_decisions'];

        $this->assertSame('one_pull_request_and_approval_per_phase', $decisions['delivery']);
        $this->assertSame('administrator', $decisions['reviewer_role']);
        $this->assertSame('laravel_http_client_without_provider_sdks', $decisions['external_clients']);
    }

    public function test_architecture_decisions_use_explicit_report_parser_versions(): void
    {
        $corpus = $this->corpus();

        $this->assertSame('explicit_parsed_report_metadata', $corpus['architecture_decisions']['parser_version_source']);

        foreach ($corpus['fixtures'] as $fixture) {
            $this->assertMatchesRegularExpression('/-v\d+$/', $fixture['source']['parser_version']);

            foreach ($fixture['input']['deterministic_parse']['reports'] as $report) {
                $this->assertSame($fixture['source']['parser_version'], $report['parser_version']);
            }

            foreach ($fixture['expected']['corrected_parse']['reports'] as $report) {
                $this->assertSame($fixture['source']['parser_version'], $report['parser_version']);
            }
        }
    }

    public function test_phase_zero_is_authorized_with_no_additional_failures_and_commit_safe_fixtures(): void
    {
        $corpus = $this->corpus();
        $decisions = $corpus['architecture_decisions'];

        $this->assertSame('none_beyond_existing_examples', $decisions['additional_known_failures']);
        $this->assertTrue($decisions['fixtures_commit_safe']);
        $this->assertTrue($decisions['phase_zero_implementation_authorized']);
        $this->assertSame('pending_user_review', $corpus['corpus_approval']);
        $this->assertSame('manually_reviewed_pending_user_approval', $corpus['metrics_definition_status']);
    }

    public function test_evaluation_fixtures_contain_only_approved_public_sanitized_context(): void
    {
        $corpus = $this->corpus();
        $decisions = $corpus['architecture_decisions'];

        $this->assertSame(['public_fish_count_text', 'public_source_url'], $decisions['shareable_data']);
        $this->assertSame([
            'openai' => 'public_fish_count_text_and_public_source_url_only',
            'local_diagnostics' => 'public_fish_count_text_and_public_source_url_only',
            'github_issues' => 'public_fish_count_text_and_public_source_url_only',
        ], $decisions['sharing_approval']);
        $this->assertSame([
            'html',
            'cookies',
            'request_headers',
            'credentials',
            'unrelated_page_content',
            'private_user_data',
        ], $decisions['excluded_data']);

        foreach ($corpus['fixtures'] as $fixture) {
            $encodedFixture = json_encode($fixture, JSON_THROW_ON_ERROR);

            $this->assertStringStartsWith('https://', $fixture['source']['url']);
            $this->assertStringNotContainsString('<', $fixture['input']['sanitized_text']);
            $this->assertDoesNotMatchRegularExpression(
                '/(?:authorization|cookie|set-cookie|api[_-]?key|access[_-]?token|bearer\s+|password|private[_-]?key)/i',
                $encodedFixture,
                "Fixture [{$fixture['id']}] contains disallowed context.",
            );
            $this->assertDoesNotMatchRegularExpression(
                '/"(?:html|cookies?|headers?|request_headers|credentials?|unrelated_page_content|private_user_data)"\s*:/i',
                $encodedFixture,
                "Fixture [{$fixture['id']}] contains a disallowed context field.",
            );
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function corpus(): array
    {
        $contents = file_get_contents(__DIR__.'/../Fixtures/Parsing/evaluation-corpus-v1.json');

        $this->assertNotFalse($contents);

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }
}
