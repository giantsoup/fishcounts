<?php

namespace Tests\Unit;

use App\Services\AI\OpenAiUsageCostCalculator;
use InvalidArgumentException;
use Tests\TestCase;

class OpenAiUsageCostCalculatorTest extends TestCase
{
    public function test_it_calculates_published_luna_token_costs_without_double_counting_reasoning_tokens(): void
    {
        $cost = app(OpenAiUsageCostCalculator::class)->calculate(
            inputTokens: 100,
            cachedInputTokens: 10,
            cacheWriteTokens: 20,
            outputTokens: 40,
        );

        $this->assertSame(336, $cost);
    }

    public function test_it_rounds_fractional_microdollars_up_to_preserve_the_hard_stop(): void
    {
        $cost = app(OpenAiUsageCostCalculator::class)->calculate(
            inputTokens: 1,
            cachedInputTokens: 1,
            cacheWriteTokens: 0,
            outputTokens: 0,
        );

        $this->assertSame(1, $cost);
    }

    public function test_it_rejects_impossible_usage_totals(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(OpenAiUsageCostCalculator::class)->calculate(
            inputTokens: 10,
            cachedInputTokens: 6,
            cacheWriteTokens: 5,
            outputTokens: 0,
        );
    }
}
