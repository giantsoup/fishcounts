<?php

namespace App\Services\Parsing;

use App\DTOs\ParsedReportValidationData;
use App\DTOs\ParserDiagnosticFindingData;
use Illuminate\Support\Str;
use JsonException;

class DiagnosticFingerprintFactory
{
    /**
     * The ordered report fingerprint inputs are source, date, URL, payload hash,
     * parser version, format, report occurrence, source identifier, and paragraph.
     * Parser or sanitized-paragraph changes therefore invalidate the fingerprint.
     *
     * @throws JsonException
     */
    public function report(ParsedReportValidationData $data, string $payloadHash): string
    {
        return $this->hash([
            'source' => $this->normalize($data->payload->sourceKey),
            'date' => $data->payload->targetDate->toDateString(),
            'url' => $data->payload->url,
            'payload_hash' => $payloadHash,
            'parser_version' => $data->parserVersion,
            'format' => $data->format,
            'report_index' => $data->reportIndex,
            'source_identifier' => $data->sourceIdentifier,
            'paragraph' => $this->normalize($data->sanitizedParagraph),
        ]);
    }

    /** @throws JsonException */
    public function diagnostic(string $reportFingerprint, ParserDiagnosticFindingData $finding): string
    {
        return $this->hash([
            'report_fingerprint' => $reportFingerprint,
            'type' => $finding->type->value,
            'field' => $this->normalize($finding->field),
            'raw_value' => $this->normalize($finding->rawValue ?? ''),
        ]);
    }

    /** @param array<string, mixed> $inputs
     * @throws JsonException
     */
    private function hash(array $inputs): string
    {
        return hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->lower()->squish()->toString();
    }
}
