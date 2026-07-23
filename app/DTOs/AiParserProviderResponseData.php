<?php

namespace App\DTOs;

final readonly class AiParserProviderResponseData
{
    /** @param null|array<string, mixed> $result */
    public function __construct(
        public ?string $responseId,
        public ?string $requestId,
        public int $httpStatus,
        public ?string $status,
        public ?string $incompleteReason,
        public ?string $model,
        public ?string $serviceTier,
        public string $responseBodyHash,
        public ?string $outputExcerpt,
        public ?array $result,
        public bool $usageAvailable,
        public int $inputTokens,
        public int $cachedInputTokens,
        public int $cacheWriteTokens,
        public int $outputTokens,
        public int $reasoningTokens,
        public int $totalTokens,
        public int $attempts,
        public int $latencyMs,
        public ?string $errorCode = null,
        public ?string $errorType = null,
    ) {}
}
