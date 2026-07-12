<?php

namespace App\DTOs;

final readonly class ParseRawPayloadResult
{
    public function __construct(
        public int $rawScrapePayloadId,
        public string $parserVersion,
        public int $parsedReportCount,
        public int $diagnosticCount,
        public bool $shouldDispatchDeduplication,
    ) {}
}
