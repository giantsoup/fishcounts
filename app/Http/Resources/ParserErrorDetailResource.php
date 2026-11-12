<?php

namespace App\Http\Resources;

use App\Models\ParserError;
use Illuminate\Http\Request;

/**
 * One parser error with everything the agent needs to investigate it: the
 * redacted context, its payload's metadata (never the body), the trip reports
 * parsed from that payload, and the latest parser execution.
 *
 * @mixin ParserError
 */
class ParserErrorDetailResource extends ParserErrorResource
{
    /**
     * Context keys returned to the agent. The stored context is already redacted
     * and capped by DiagnosticContextFactory; anything else in it stays private.
     */
    private const CONTEXT_KEYS = ['source', 'date', 'url', 'parser_version', 'format', 'report_index', 'source_identifier', 'extracted_fields', 'evidence'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = $this->rawScrapePayload;

        return [
            ...parent::toArray($request),
            'context' => $this->contextFields(),
            'raw_scrape_payload' => RawPayloadResource::make($this->whenLoaded('rawScrapePayload')),
            'trip_reports' => TripReportResource::collection($payload?->tripReports ?? collect()),
            'latest_parser_execution' => $payload?->latestParserExecution === null
                ? null
                : ParserExecutionResource::make($payload->latestParserExecution),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contextFields(): array
    {
        $context = is_array($this->context) ? $this->context : [];

        return [
            'paragraph' => $context['sanitized_paragraph'] ?? null,
            ...collect(self::CONTEXT_KEYS)->mapWithKeys(fn (string $key): array => [$key => $context[$key] ?? null])->all(),
        ];
    }
}
