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
            model: 'gpt-5.6-luna',
            serviceTier: 'default',
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
            model: 'gpt-5.6-luna',
            serviceTier: 'default',
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
            model: 'gpt-5.6-luna',
            serviceTier: 'default',
            inputTokens: 10,
            cachedInputTokens: 6,
            cacheWriteTokens: 5,
            outputTokens: 0,
        );
    }

    public function test_it_accepts_a_dated_snapshot_of_the_priced_model(): void
    {
        $cost = app(OpenAiUsageCostCalculator::class)->calculate(
            model: 'gpt-5.6-luna-2026-07-01',
            serviceTier: 'default',
            inputTokens: 1,
            cachedInputTokens: 0,
            cacheWriteTokens: 0,
            outputTokens: 0,
        );

        $this->assertSame(1, $cost);
    }

    public function test_it_rejects_an_unpriced_model_or_service_tier(): void
    {
        foreach ([
            ['gpt-unpriced', 'default'],
            ['gpt-5.6-luna', 'priority'],
        ] as [$model, $serviceTier]) {
            try {
                app(OpenAiUsageCostCalculator::class)->calculate(
                    model: $model,
                    serviceTier: $serviceTier,
                    inputTokens: 1,
                    cachedInputTokens: 0,
                    cacheWriteTokens: 0,
                    outputTokens: 0,
                );
                $this->fail('Expected unconfigured pricing to be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
