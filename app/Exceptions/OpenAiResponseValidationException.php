<?php

namespace App\Exceptions;

use Throwable;
use UnexpectedValueException;

final class OpenAiResponseValidationException extends UnexpectedValueException
{
    public function __construct(
        string $message,
        public readonly string $responseId,
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $cachedInputTokens,
        public readonly int $cacheWriteTokens,
        public readonly int $outputTokens,
        public readonly int $reasoningTokens,
        public readonly int $totalTokens,
        public readonly string $serviceTier,
        public readonly bool $hasValidUsage = true,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
