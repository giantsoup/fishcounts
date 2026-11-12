<?php

namespace App\Services\Parsing;

use App\DTOs\ParserDiagnosticData;
use App\Models\ParserError;
use App\Models\RawScrapePayload;

class ParserDiagnosticSynchronizer
{
    /** @param array<int, ParserDiagnosticData> $diagnostics */
    public function sync(RawScrapePayload $payload, array $diagnostics): int
    {
        ParserError::query()
            ->where('raw_scrape_payload_id', $payload->id)
            ->open()
            ->delete();

        foreach ($diagnostics as $diagnostic) {
            ParserError::query()->firstOrCreate(
                ['diagnostic_fingerprint' => $diagnostic->diagnosticFingerprint],
                [
                    'raw_scrape_payload_id' => $payload->id,
                    'scrape_source_id' => $payload->scrape_source_id,
                    'target_date' => $payload->target_date,
                    'error_type' => $diagnostic->errorType(),
                    'raw_field' => $diagnostic->field,
                    'raw_value' => $diagnostic->rawValue,
                    'message' => $diagnostic->message,
                    'context' => $diagnostic->context,
                    'report_fingerprint' => $diagnostic->reportFingerprint,
                ],
            );
        }

        return ParserError::query()
            ->where('raw_scrape_payload_id', $payload->id)
            ->open()
            ->count();
    }
}
