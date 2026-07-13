<?php

namespace App\Console\Commands;

use App\Services\AI\HistoricalAiReviewDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Signature('ai-reviews:dispatch
    {scope : Selection scope: new, unresolved, or historical}
    {--from= : Inclusive payload target date (YYYY-MM-DD)}
    {--to= : Inclusive payload target date (YYYY-MM-DD)}
    {--max= : Explicit maximum payload count}
    {--budget-micros= : Explicit maximum estimated spend in USD micros}
    {--authorized-by= : Unique approval reference required for execution}
    {--dry-run : Show counts and estimated maximum cost without writing or dispatching}')]
#[Description('Preview or dispatch a bounded, explicitly authorized AI parser review run')]
class DispatchAiReviewsCommand extends Command
{
    public function handle(HistoricalAiReviewDispatcher $dispatcher): int
    {
        $scope = (string) $this->argument('scope');

        if (! in_array($scope, ['new', 'unresolved', 'historical'], true)) {
            $this->error('Scope must be new, unresolved, or historical.');

            return self::INVALID;
        }

        $configuredMax = (int) config('fish.ai_review.operations.historical_max_items');
        $validator = Validator::make([
            'from' => $this->option('from'),
            'to' => $this->option('to'),
            'max' => $this->option('max'),
            'budget_micros' => $this->option('budget-micros'),
        ], [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'max' => ['required', 'integer', 'min:1', 'max:'.$configuredMax],
            'budget_micros' => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::INVALID;
        }

        $validated = $validator->validated();
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $validated['from']);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $validated['to']);
        $maxItems = (int) $validated['max'];
        $budgetMicros = (int) $validated['budget_micros'];

        $preview = $dispatcher->preview($scope, $from, $to, $maxItems, $budgetMicros);
        $this->table(['Eligible', 'Planned', 'Estimated maximum cost'], [[
            $preview['eligible_count'],
            $preview['planned_count'],
            '$'.number_format($preview['estimated_max_cost_micros'] / 1_000_000, 2),
        ]]);

        if ($this->option('dry-run')) {
            $this->info('Dry run only; no records were written and no jobs were dispatched.');

            return self::SUCCESS;
        }

        $authorizationReference = trim((string) $this->option('authorized-by'));

        if ($authorizationReference === '' || Str::length($authorizationReference) > 255) {
            $this->error('--authorized-by must be a unique approval reference for this execution and cannot exceed 255 characters.');

            return self::INVALID;
        }

        if ($preview['planned_count'] === 0) {
            $this->warn('No eligible payloads fit the supplied selection and budget bounds.');

            return self::SUCCESS;
        }

        if (! config('fish.ai_review.enabled') || ! config('fish.ai_review.dispatch_enabled') || blank(config('services.openai.api_key'))) {
            $this->error('AI review, dispatch, and OpenAI credentials must be enabled before execution.');

            return self::FAILURE;
        }

        try {
            $run = $dispatcher->create($scope, $from, $to, $maxItems, $budgetMicros, $authorizationReference);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }
        $this->info("Historical AI review run {$run->id} created with {$run->selected_count} bounded items.");

        return self::SUCCESS;
    }
}
