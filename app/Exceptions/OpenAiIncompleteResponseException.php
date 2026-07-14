<?php

namespace App\Exceptions;

use UnexpectedValueException;

final class OpenAiIncompleteResponseException extends UnexpectedValueException
{
    public readonly string $reason;

    public function __construct(
        public readonly string $responseId,
        public readonly string $model,
        string $reason,
        public readonly int $inputTokens,
        public readonly int $cachedInputTokens,
        public readonly int $outputTokens,
        public readonly int $reasoningTokens,
        public readonly int $totalTokens,
    ) {
        $normalizedReason = str($reason)
            ->lower()
            ->replaceMatches('/[^a-z0-9_.-]+/', '_')
            ->trim('_')
            ->limit(64, '')
            ->toString();
        $this->reason = $normalizedReason !== '' ? $normalizedReason : 'unknown';

        parent::__construct(match ($this->reason) {
            'max_output_tokens' => 'The OpenAI response reached the configured output-token limit before it completed.',
            default => "The OpenAI response was incomplete ({$this->reason}).",
        });
    }
}
