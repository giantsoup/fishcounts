<?php

namespace App\DTOs;

final readonly class ParsedReportValidationData
{
    /** @param array<string, mixed> $sourceEvidence */
    public function __construct(
        public RawPayloadData $payload,
        public ParsedFishCountCollection $parsed,
        public ?ParsedTripReportData $report,
        public ?int $reportIndex,
        public string $parserVersion,
        public string $format,
        public ?string $sourceIdentifier,
        public string $sanitizedParagraph,
        public array $sourceEvidence = [],
    ) {}
}
