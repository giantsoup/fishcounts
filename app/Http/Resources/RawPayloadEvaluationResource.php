<?php

namespace App\Http\Resources;

use App\DTOs\ParsedSpeciesCountData;
use App\DTOs\ParsedTripReportData;
use App\DTOs\ParserDiagnosticData;
use App\DTOs\RawPayloadEvaluationData;
use App\Models\ParserError;
use App\Services\Parsing\DiagnosticContextFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The result of a dry-run deterministic parse. Text copied from the payload passes
 * through the diagnostic secret redaction; diagnostic context is already redacted.
 *
 * @property RawPayloadEvaluationData $resource
 */
class RawPayloadEvaluationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $redactor = app(DiagnosticContextFactory::class);

        return [
            'raw_scrape_payload_id' => $this->resource->payload->id,
            'engine' => 'deterministic',
            'parser_version' => $this->resource->parsed->parserVersion,
            'format' => $this->resource->parsed->format,
            'reports' => $this->resource->parsed->tripReports
                ->map(fn (ParsedTripReportData $report, int $index): array => $this->report($report, $index, $redactor))
                ->values()
                ->all(),
            'diagnostics' => array_map(
                fn (ParserDiagnosticData $diagnostic): array => $this->diagnostic($diagnostic),
                array_values($this->resource->diagnostics),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function report(ParsedTripReportData $report, int $index, DiagnosticContextFactory $redactor): array
    {
        $redact = fn (?string $value): ?string => $value === null ? null : $redactor->redactSecrets($value);

        return [
            'index' => $index,
            'trip_date' => $report->tripDate->toDateString(),
            'region' => $redact($report->regionName),
            'landing' => $redact($report->landingName),
            'boat' => $redact($report->boatName),
            'canonical_boat_id' => $report->canonicalBoatId,
            'trip_type' => $redact($report->tripTypeName),
            'canonical_trip_type_id' => $report->canonicalTripTypeId,
            'anglers' => $report->anglers,
            'raw_fish_count_text' => $redact($report->rawFishCountText),
            'source_trip_identifier' => isset($report->metadata['source_trip_identifier'])
                ? $redact((string) $report->metadata['source_trip_identifier'])
                : null,
            'species_counts' => array_map(fn (ParsedSpeciesCountData $count): array => [
                'species' => $redact($count->speciesName),
                'canonical_species_id' => $count->canonicalSpeciesId,
                'count' => $count->count,
                'released' => $count->releasedCount,
                'basis' => $count->quantityBasis->value,
                'limits' => $count->isLimits,
                'raw_text' => $redact($count->rawText),
            ], array_values($report->speciesCounts)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function diagnostic(ParserDiagnosticData $diagnostic): array
    {
        $errorType = $diagnostic->errorType();

        return [
            'error_type' => $errorType,
            'kind' => in_array($errorType, ParserError::ALIAS_ERROR_TYPES, true) ? 'alias' : 'structural',
            'raw_field' => $diagnostic->field,
            'raw_value' => $diagnostic->rawValue,
            'message' => $diagnostic->message,
            'context' => $diagnostic->context,
            'report_fingerprint' => $diagnostic->reportFingerprint,
            'diagnostic_fingerprint' => $diagnostic->diagnosticFingerprint,
        ];
    }
}
