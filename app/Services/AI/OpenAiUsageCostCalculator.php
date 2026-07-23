<?php

namespace App\Services\AI;

use InvalidArgumentException;

final class OpenAiUsageCostCalculator
{
    public function calculate(
        int $inputTokens,
        int $cachedInputTokens,
        int $cacheWriteTokens,
        int $outputTokens,
    ): int {
        if ($inputTokens < 0 || $cachedInputTokens < 0 || $cacheWriteTokens < 0 || $outputTokens < 0) {
            throw new InvalidArgumentException('OpenAI token usage cannot be negative.');
        }

        if (($cachedInputTokens + $cacheWriteTokens) > $inputTokens) {
            throw new InvalidArgumentException('Cached and cache-write input tokens cannot exceed total input tokens.');
        }

        $uncachedInputTokens = $inputTokens - $cachedInputTokens - $cacheWriteTokens;
        $costNumerator = ($uncachedInputTokens * (int) config('fish.ai_review.pricing.input_cost_per_million_micros'))
            + ($cachedInputTokens * (int) config('fish.ai_review.pricing.cached_input_cost_per_million_micros'))
            + ($cacheWriteTokens * (int) config('fish.ai_review.pricing.cache_write_cost_per_million_micros'))
            + ($outputTokens * (int) config('fish.ai_review.pricing.output_cost_per_million_micros'));

        return intdiv($costNumerator + 999_999, 1_000_000);
    }
}
