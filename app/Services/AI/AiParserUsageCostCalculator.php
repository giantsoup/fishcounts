<?php

namespace App\Services\AI;

use App\DTOs\AiParserProviderResponseData;
use InvalidArgumentException;

final class AiParserUsageCostCalculator
{
    public const string VERSION = 'openai-list-price-v1';

    public function calculate(AiParserProviderResponseData $response): int
    {
        $model = (string) config('fish.ai_parsing.pricing.model');
        if (! is_string($response->model)
            || ($response->model !== $model
                && preg_match('/^'.preg_quote($model, '/').'-\d{4}-\d{2}-\d{2}$/', $response->model) !== 1)) {
            throw new InvalidArgumentException("AI parser pricing is not configured for model [{$response->model}].");
        }
        if ($response->serviceTier !== (string) config('fish.ai_parsing.pricing.service_tier')) {
            throw new InvalidArgumentException("AI parser pricing is not configured for service tier [{$response->serviceTier}].");
        }
        $uncached = $response->inputTokens - $response->cachedInputTokens - $response->cacheWriteTokens;
        if ($uncached < 0) {
            throw new InvalidArgumentException('AI parser input usage was inconsistent.');
        }
        $numerator = ($uncached * (int) config('fish.ai_parsing.pricing.input_cost_per_million_micros'))
            + ($response->cachedInputTokens * (int) config('fish.ai_parsing.pricing.cached_input_cost_per_million_micros'))
            + ($response->cacheWriteTokens * (int) config('fish.ai_parsing.pricing.cache_write_cost_per_million_micros'))
            + ($response->outputTokens * (int) config('fish.ai_parsing.pricing.output_cost_per_million_micros'));

        return intdiv($numerator + 999_999, 1_000_000);
    }

    /** @return array<string, int|string> */
    public function pricingSnapshot(): array
    {
        return [
            'model' => (string) config('fish.ai_parsing.pricing.model'),
            'service_tier' => (string) config('fish.ai_parsing.pricing.service_tier'),
            'input_cost_per_million_micros' => (int) config('fish.ai_parsing.pricing.input_cost_per_million_micros'),
            'cached_input_cost_per_million_micros' => (int) config('fish.ai_parsing.pricing.cached_input_cost_per_million_micros'),
            'cache_write_cost_per_million_micros' => (int) config('fish.ai_parsing.pricing.cache_write_cost_per_million_micros'),
            'output_cost_per_million_micros' => (int) config('fish.ai_parsing.pricing.output_cost_per_million_micros'),
        ];
    }
}
