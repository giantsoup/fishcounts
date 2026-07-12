<?php

namespace Tests\Unit;

use App\DTOs\CanonicalCandidateData;
use App\DTOs\ParserDiagnosticReviewRequestData;
use App\Enums\CanonicalEntityType;
use App\Enums\ParserCorrectionOperation;
use App\Enums\ParserDiagnosticReviewClassification;
use App\Enums\ParserDiagnosticType;
use App\Services\AI\ParserDiagnosticReviewResultValidator;
use App\Services\AI\ParserDiagnosticReviewSchema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ParserDiagnosticReviewResultValidatorTest extends TestCase
{
    public function test_valid_result_becomes_an_immutable_typed_dto(): void
    {
        $result = app(ParserDiagnosticReviewResultValidator::class)->validate($this->validResult(), $this->request());

        $this->assertSame(ParserDiagnosticReviewClassification::ValueExtractionError, $result->classification);
        $this->assertSame(0.94, $result->confidence);
        $this->assertSame(ParserCorrectionOperation::SetSpeciesCount, $result->corrections[0]->operation);
        $this->assertSame(CanonicalEntityType::Species, $result->corrections[0]->canonicalType);
        $this->assertSame(21, $result->corrections[0]->retainedCount);
        $this->assertTrue((new \ReflectionClass($result))->isReadOnly());
    }

    public function test_validator_rejects_extra_keys_unknown_enums_ids_negative_counts_and_confidence(): void
    {
        $extraRoot = $this->validResult();
        $extraRoot['raw_response'] = ['not' => 'allowed'];
        $this->assertInvalid($extraRoot);

        $extraCorrection = $this->validResult();
        $extraCorrection['corrections'][0]['explanation'] = 'not allowed';
        $this->assertInvalid($extraCorrection);

        $unknownClassification = $this->validResult();
        $unknownClassification['classification'] = 'invented';
        $this->assertInvalid($unknownClassification);

        $unknownOperation = $this->validResult();
        $unknownOperation['corrections'][0]['operation'] = 'execute_sql';
        $this->assertInvalid($unknownOperation);

        $unknownId = $this->validResult();
        $unknownId['corrections'][0]['canonical_id'] = 999;
        $this->assertInvalid($unknownId);

        $negativeCount = $this->validResult();
        $negativeCount['corrections'][0]['retained_count'] = -1;
        $this->assertInvalid($negativeCount);

        $invalidConfidence = $this->validResult();
        $invalidConfidence['confidence'] = 1.01;
        $this->assertInvalid($invalidConfidence);
    }

    public function test_validator_enforces_operation_specific_fields(): void
    {
        $missingCount = $this->validResult();
        $missingCount['corrections'][0]['released_count'] = null;
        $this->assertInvalid($missingCount);

        $wrongType = $this->validResult();
        $wrongType['corrections'][0]['canonical_type'] = 'boat';
        $wrongType['corrections'][0]['canonical_id'] = 8;
        $this->assertInvalid($wrongType);

        $cleanWithCorrection = $this->validResult();
        $cleanWithCorrection['classification'] = 'clean';
        $this->assertInvalid($cleanWithCorrection);

        $anglerCorrection = $this->validResult();
        $anglerCorrection['corrections'][0] = [
            'operation' => 'set_angler_count',
            'report_index' => 0,
            'field' => 'anglers',
            'canonical_type' => null,
            'canonical_id' => null,
            'value' => 20,
            'retained_count' => null,
            'released_count' => null,
        ];

        $validated = app(ParserDiagnosticReviewResultValidator::class)->validate($anglerCorrection, $this->request());

        $this->assertSame(20, $validated->corrections[0]->value);
    }

    public function test_clean_result_may_have_an_empty_correction_list(): void
    {
        $clean = $this->validResult();
        $clean['classification'] = 'clean';
        $clean['corrections'] = [];

        $result = app(ParserDiagnosticReviewResultValidator::class)->validate($clean, $this->request());

        $this->assertSame(ParserDiagnosticReviewClassification::Clean, $result->classification);
        $this->assertSame([], $result->corrections);
    }

    public function test_json_schema_is_strict_at_every_object_boundary(): void
    {
        $schema = app(ParserDiagnosticReviewSchema::class)->schema();
        $correction = $schema['properties']['corrections']['items'];

        $this->assertFalse($schema['additionalProperties']);
        $this->assertFalse($correction['additionalProperties']);
        $this->assertSame(['classification', 'confidence', 'rationale', 'corrections'], $schema['required']);
        $this->assertSame([
            'operation',
            'report_index',
            'field',
            'canonical_type',
            'canonical_id',
            'value',
            'retained_count',
            'released_count',
        ], $correction['required']);
        $this->assertContains('map_alias', $correction['properties']['operation']['enum']);
        $this->assertNotContains('create_entity', $correction['properties']['operation']['enum']);
    }

    /** @param array<string, mixed> $result */
    private function assertInvalid(array $result): void
    {
        try {
            app(ParserDiagnosticReviewResultValidator::class)->validate($result, $this->request());
            $this->fail('Expected the parser diagnostic review result to be rejected.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    private function request(): ParserDiagnosticReviewRequestData
    {
        return new ParserDiagnosticReviewRequestData(
            payloadId: 10,
            payloadHash: hash('sha256', 'payload'),
            diagnosticFingerprint: hash('sha256', 'diagnostic'),
            diagnosticType: ParserDiagnosticType::ExtractedValueSourceSpanMismatch,
            field: 'species_counts',
            rawValue: '25 Calico Bass Released, 21 Calico Bass',
            context: ['sanitized_paragraph' => 'A public fish-count paragraph.'],
            candidates: [
                new CanonicalCandidateData(CanonicalEntityType::Species, 12, 'Calico Bass', ['Bass']),
                new CanonicalCandidateData(CanonicalEntityType::Boat, 8, 'Dolphin'),
            ],
        );
    }

    /** @return array<string, mixed> */
    private function validResult(): array
    {
        return [
            'classification' => 'value_extraction_error',
            'confidence' => 0.94,
            'rationale' => 'Retained and released counts were reversed.',
            'corrections' => [[
                'operation' => 'set_species_count',
                'report_index' => 0,
                'field' => 'species_count',
                'canonical_type' => 'species',
                'canonical_id' => 12,
                'value' => null,
                'retained_count' => 21,
                'released_count' => 25,
            ]],
        ];
    }
}
