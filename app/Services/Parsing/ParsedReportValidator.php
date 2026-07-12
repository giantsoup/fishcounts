<?php

namespace App\Services\Parsing;

use App\Contracts\Parsing\ParsedReportDiagnosticRule;
use App\DTOs\ParsedFishCountCollection;
use App\DTOs\ParsedReportValidationData;
use App\DTOs\ParserDiagnosticData;
use App\DTOs\ParserDiagnosticFindingData;
use App\DTOs\RawPayloadData;
use App\Models\RawScrapePayload;
use App\Services\Parsing\Rules\EmptyOrUnexpectedlySmallResultSetRule;
use App\Services\Parsing\Rules\ExcessiveNameLengthRule;
use App\Services\Parsing\Rules\ExtractedValueSourceSpanMismatchRule;
use App\Services\Parsing\Rules\FractionalTripConflictRule;
use App\Services\Parsing\Rules\ProseCapturedAsEntityRule;
use App\Services\Parsing\Rules\StructuredSourceFallbackRule;
use App\Services\Parsing\Rules\UnaccountedNumericTokensRule;
use App\Services\Parsing\Rules\UnknownAliasRule;
use Illuminate\Support\Str;

class ParsedReportValidator
{
    public function __construct(
        private readonly UnknownAliasRule $unknownAliasRule,
        private readonly FractionalTripConflictRule $fractionalTripConflictRule,
        private readonly ProseCapturedAsEntityRule $proseCapturedAsEntityRule,
        private readonly ExcessiveNameLengthRule $excessiveNameLengthRule,
        private readonly UnaccountedNumericTokensRule $unaccountedNumericTokensRule,
        private readonly EmptyOrUnexpectedlySmallResultSetRule $emptyOrUnexpectedlySmallResultSetRule,
        private readonly StructuredSourceFallbackRule $structuredSourceFallbackRule,
        private readonly ExtractedValueSourceSpanMismatchRule $extractedValueSourceSpanMismatchRule,
        private readonly DiagnosticContextFactory $contextFactory,
        private readonly DiagnosticFingerprintFactory $fingerprintFactory,
    ) {}

    /** @return array<int, ParserDiagnosticData> */
    public function validate(RawScrapePayload $storedPayload, RawPayloadData $payload, ParsedFishCountCollection $parsed): array
    {
        $diagnostics = [];

        foreach ($parsed->tripReports as $index => $report) {
            $parserVersion = (string) ($report->metadata['parser'] ?? $parsed->parserVersion ?? 'unknown');
            $format = (string) ($report->metadata['format'] ?? $parsed->format ?? 'unknown');
            $data = new ParsedReportValidationData(
                payload: $payload,
                parsed: $parsed,
                report: $report,
                reportIndex: $index,
                parserVersion: $parserVersion,
                format: $format,
                sourceIdentifier: isset($report->metadata['source_trip_identifier']) ? (string) $report->metadata['source_trip_identifier'] : null,
                sanitizedParagraph: $this->contextFactory->paragraphForReport($payload, $report, $index),
            );

            foreach ($this->enabledRules() as $rule) {
                $diagnostics = array_merge($diagnostics, $this->diagnosticsForRule($rule, $data, $storedPayload->payload_hash));
            }
        }

        if ((bool) config('fish.parsing.diagnostics.suspicious_enabled', false)) {
            foreach ($this->missingSourceReportData($payload, $parsed) as $data) {
                $diagnostics = array_merge(
                    $diagnostics,
                    $this->diagnosticsForRule($this->emptyOrUnexpectedlySmallResultSetRule, $data, $storedPayload->payload_hash),
                );
            }
        }

        return $diagnostics;
    }

    /** @return array<int, ParsedReportDiagnosticRule> */
    private function enabledRules(): array
    {
        if (! (bool) config('fish.parsing.diagnostics.suspicious_enabled', false)) {
            return [$this->unknownAliasRule];
        }

        return [
            $this->unknownAliasRule,
            $this->fractionalTripConflictRule,
            $this->proseCapturedAsEntityRule,
            $this->excessiveNameLengthRule,
            $this->unaccountedNumericTokensRule,
            $this->structuredSourceFallbackRule,
            $this->extractedValueSourceSpanMismatchRule,
        ];
    }

    /** @return array<int, ParserDiagnosticData> */
    private function diagnosticsForRule(ParsedReportDiagnosticRule $rule, ParsedReportValidationData $data, string $payloadHash): array
    {
        $reportFingerprint = $this->fingerprintFactory->report($data, $payloadHash);

        return collect($rule->inspect($data))
            ->map(function (ParserDiagnosticFindingData $finding) use ($data, $reportFingerprint): ParserDiagnosticData {
                $sanitizedFinding = new ParserDiagnosticFindingData(
                    type: $finding->type,
                    field: $finding->field,
                    rawValue: $finding->rawValue === null ? null : $this->contextFactory->sanitizeDiagnosticText($finding->rawValue),
                    message: $this->contextFactory->sanitizeDiagnosticText($finding->message),
                    evidence: $finding->evidence,
                );

                return new ParserDiagnosticData(
                    type: $sanitizedFinding->type,
                    field: $sanitizedFinding->field,
                    rawValue: $sanitizedFinding->rawValue,
                    message: $sanitizedFinding->message,
                    context: array_merge(
                        ['diagnostic_type' => $sanitizedFinding->type->value],
                        $this->contextFactory->context($data, $sanitizedFinding->evidence),
                    ),
                    reportFingerprint: $reportFingerprint,
                    diagnosticFingerprint: $this->fingerprintFactory->diagnostic($reportFingerprint, $sanitizedFinding),
                );
            })
            ->all();
    }

    /** @return array<int, ParsedReportValidationData> */
    private function missingSourceReportData(RawPayloadData $payload, ParsedFishCountCollection $parsed): array
    {
        $supportedSources = config('fish.parsing.diagnostics.source_specific_result_evidence', []);

        if (! is_array($supportedSources) || ! array_key_exists($payload->sourceKey, $supportedSources)) {
            return [];
        }

        $evidenceStrategy = (string) $supportedSources[$payload->sourceKey];
        $paragraphs = collect($this->contextFactory->fishCountParagraphs($payload))
            ->filter(fn (string $paragraph): bool => $this->hasSourceSpecificReportEvidence($paragraph, $evidenceStrategy))
            ->values();

        return $paragraphs
            ->reject(function (string $paragraph) use ($parsed): bool {
                return $parsed->tripReports->contains(function ($report) use ($paragraph): bool {
                    $rawCounts = Str::of($report->rawFishCountText ?? '')->squish()->toString();

                    return $rawCounts !== '' && Str::contains($paragraph, $rawCounts, true);
                });
            })
            ->map(function (string $paragraph, int $index) use ($payload, $parsed, $paragraphs, $evidenceStrategy): ParsedReportValidationData {
                preg_match('/(?:The\s+)?(?<label>[A-Z][A-Za-z0-9 \'&.-]{1,60}?)(?:\s+(?:returned|called|checked|finished|\||\d+(?:\.\d+|\/\d+)?\s*Day))\b/', $paragraph, $matches);

                return new ParsedReportValidationData(
                    payload: $payload,
                    parsed: $parsed,
                    report: null,
                    reportIndex: $index,
                    parserVersion: $parsed->parserVersion ?? 'unknown',
                    format: $parsed->format ?? 'unknown',
                    sourceIdentifier: "source-paragraph:{$index}",
                    sanitizedParagraph: $paragraph,
                    sourceEvidence: [
                        'evidence_strategy' => $evidenceStrategy,
                        'candidate_count' => $paragraphs->count(),
                        'parsed_count' => $parsed->tripReports->count(),
                        'has_missing_report' => true,
                        'candidate_label' => isset($matches['label']) ? Str::of($matches['label'])->squish()->toString() : null,
                    ],
                );
            })
            ->values()
            ->all();
    }

    private function hasSourceSpecificReportEvidence(string $paragraph, string $strategy): bool
    {
        $hasAnglerCount = preg_match('/\b\d+\s+(?:anglers?|people|passengers?)\b/i', $paragraph) === 1;
        $hasSpeciesCount = preg_match('/\b\d+\s+(?!(?:anglers?|people|passengers?|boats?|trips?|days?|hours?|lbs?|pounds?|oz)\b)[A-Za-z][A-Za-z .\'-]{2,}/i', $paragraph) === 1;

        return match ($strategy) {
            'narrative_report_paragraph' => $hasAnglerCount && $hasSpeciesCount,
            'structured_report_row' => $hasAnglerCount && $hasSpeciesCount && substr_count($paragraph, '|') >= 2,
            'party_boat_score_row' => $hasAnglerCount && $hasSpeciesCount && substr_count($paragraph, '|') >= 3,
            default => false,
        };
    }
}
