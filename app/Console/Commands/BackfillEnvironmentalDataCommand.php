<?php

namespace App\Console\Commands;

use App\Services\Environmental\EnvironmentalBackfillDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('fish:backfill-environmental-data
    {--from= : First date to collect, YYYY-MM-DD. Cannot be earlier than 2026-01-01.}
    {--to= : Last date to collect, YYYY-MM-DD.}
    {--profile= : Limit collection to one environmental location profile.}
    {--sync : Run collection immediately instead of queueing jobs.}')]
#[Description('Backfill historical environmental conditions for a date range.')]
class BackfillEnvironmentalDataCommand extends Command
{
    public function handle(EnvironmentalBackfillDispatcher $dispatcher): int
    {
        $fromInput = $this->option('from');
        $toInput = $this->option('to');
        $locationProfile = $this->option('profile');
        $latestDate = EnvironmentalBackfillDispatcher::latestDate()->toDateString();
        $validator = Validator::make(
            [
                'from' => $fromInput,
                'to' => $toInput,
                'profile' => $locationProfile,
            ],
            [
                'from' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.EnvironmentalBackfillDispatcher::EARLIEST_DATE, 'before_or_equal:'.$latestDate],
                'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.$latestDate],
                'profile' => ['nullable', 'string'],
            ],
            [
                'from.after_or_equal' => 'The --from date cannot be earlier than '.EnvironmentalBackfillDispatcher::EARLIEST_DATE.'.',
                'to.after_or_equal' => 'The --to date must be after or equal to the --from date.',
            ],
        );

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first());

            return self::FAILURE;
        }

        $validated = $validator->validated();
        $timezone = (string) config('fish.conditions.timezone', 'America/Los_Angeles');
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $validated['from'], $timezone);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $validated['to'], $timezone);
        $scope = is_string($locationProfile) && $locationProfile !== '' ? " for {$locationProfile}" : '';
        $runSynchronously = (bool) $this->option('sync');
        $jobCount = $dispatcher->dispatchRange(
            $from,
            $to,
            is_string($locationProfile) && $locationProfile !== '' ? $locationProfile : null,
            $runSynchronously,
        );

        if ($jobCount === 0) {
            $this->components->error("No enabled environmental sources with historical support found{$scope}.");

            return self::FAILURE;
        }

        $action = $runSynchronously ? 'Completed' : 'Queued';
        $this->info("{$action} {$jobCount} environmental backfill job(s) from {$from->toDateString()} to {$to->toDateString()}{$scope}.");

        return self::SUCCESS;
    }
}
