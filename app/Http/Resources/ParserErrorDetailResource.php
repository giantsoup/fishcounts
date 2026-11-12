<?php

namespace App\Http\Resources;

use App\Models\ParserError;
use App\Models\RawScrapePayload;
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
        return [
            ...parent::toArray($request),
            'context' => $this->contextFields(),
            'raw_scrape_payload' => $this->whenLoaded('rawScrapePayload', fn (): ?array => $this->payloadMetadata($this->rawScrapePayload)),
            'trip_reports' => TripReportResource::collection($this->rawScrapePayload?->tripReports ?? collect()),
            'latest_parser_execution' => $this->rawScrapePayload?->latestParserExecution === null
                ? null
                : ParserExecutionSummaryResource::make($this->rawScrapePayload->latestParserExecution),
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

    /**
     * Payload metadata only. The URL loses its query string and fragment, which can carry credentials.
     *
     * @return array<string, mixed>|null
     */
    private function payloadMetadata(?RawScrapePayload $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        return [
            'id' => $payload->id,
            'scrape_source_id' => $payload->scrape_source_id,
            'target_date' => $payload->target_date?->toDateString(),
            'url' => self::urlWithoutQuery($payload->url),
            'http_status' => $payload->http_status,
            'content_type' => $payload->content_type,
            'payload_hash' => $payload->payload_hash,
            'parser_version' => $payload->parser_version,
            'fetched_at' => $payload->fetched_at?->toIso8601String(),
            'parsed_at' => $payload->parsed_at?->toIso8601String(),
            'authoritative_parser_execution_id' => $payload->authoritative_parser_execution_id,
        ];
    }

    private static function urlWithoutQuery(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        return strtok(strtok($url, '#') ?: '', '?') ?: null;
    }
}
