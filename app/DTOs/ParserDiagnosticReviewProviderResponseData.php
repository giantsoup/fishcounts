<?php

namespace App\DTOs;

final readonly class ParserDiagnosticReviewProviderResponseData
{
    /**
     * @param  array<string, array<string, mixed>>  $results
     */
    public function __construct(
        public string $responseId,
        public string $model,
        public array $results,
        public bool $refused,
        public ?string $refusal,
        public int $inputTokens,
        public int $cachedInputTokens,
        public int $outputTokens,
        public int $reasoningTokens,
        public int $totalTokens,
        public int $cacheWriteTokens = 0,
        public string $serviceTier = 'default',
    ) {}
}
