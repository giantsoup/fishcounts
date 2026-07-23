<?php

namespace App\Services\AI;

use App\Enums\AiBudgetPeriodType;
use App\Enums\AiBudgetReservationStatus;
use App\Enums\AiParserAttemptCostBasis;
use App\Exceptions\AiBudgetExceededException;
use App\Models\AiBudgetPeriod;
use App\Models\AiBudgetReservation;
use App\Models\ParserExecution;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class AiParserBudgetManager
{
    public function reserve(ParserExecution $execution, int $attempt): AiBudgetReservation
    {
        $estimatedCost = (int) config('fish.ai_parsing.budgets.estimated_attempt_cost_micros');
        if ($estimatedCost <= 0) {
            throw new AiBudgetExceededException;
        }

        return DB::transaction(function () use ($execution, $attempt, $estimatedCost): AiBudgetReservation {
            $provider = (string) config('fish.ai_parsing.provider');
            $now = CarbonImmutable::now();
            $localNow = $now->setTimezone((string) config('fish.ai_parsing.budgets.timezone'));
            $periods = collect([
                AiBudgetPeriodType::Daily->value => [
                    'start' => $localNow->startOfDay(),
                    'end' => $localNow->endOfDay(),
                    'limit' => (int) config('fish.ai_parsing.budgets.daily_limit_micros'),
                ],
                AiBudgetPeriodType::Monthly->value => [
                    'start' => $localNow->startOfMonth(),
                    'end' => $localNow->endOfMonth(),
                    'limit' => (int) config('fish.ai_parsing.budgets.monthly_limit_micros'),
                ],
            ]);

            foreach ($periods as $type => $period) {
                DB::table((new AiBudgetPeriod)->getTable())->insertOrIgnore([
                    'provider' => $provider,
                    'period_type' => $type,
                    'period_start' => $period['start']->toDateString(),
                    'period_end' => $period['end']->toDateString(),
                    'limit_micros' => $period['limit'],
                    'reserved_micros' => 0,
                    'spent_micros' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $locked = AiBudgetPeriod::query()
                ->where('provider', $provider)
                ->where(function ($query) use ($localNow): void {
                    $query->where(function ($query) use ($localNow): void {
                        $query->where('period_type', AiBudgetPeriodType::Daily)
                            ->whereDate('period_start', $localNow->toDateString());
                    })->orWhere(function ($query) use ($localNow): void {
                        $query->where('period_type', AiBudgetPeriodType::Monthly)
                            ->whereDate('period_start', $localNow->startOfMonth()->toDateString());
                    });
                })->orderBy('id')->lockForUpdate()->get()->keyBy(fn (AiBudgetPeriod $period): string => $period->period_type->value);
            $daily = $locked->get(AiBudgetPeriodType::Daily->value);
            $monthly = $locked->get(AiBudgetPeriodType::Monthly->value);
            if (! $daily instanceof AiBudgetPeriod || ! $monthly instanceof AiBudgetPeriod) {
                throw new AiBudgetExceededException;
            }
            $this->releaseExpiredReservations($locked, $now);

            $key = "parser:{$execution->id}:{$attempt}";
            $existing = AiBudgetReservation::query()->where('reservation_key', $key)->first();
            if ($existing !== null) {
                if ($existing->status === AiBudgetReservationStatus::Reserved) {
                    return $existing;
                }

                throw new AiBudgetExceededException;
            }

            foreach ($locked as $period) {
                $limit = (int) $periods[$period->period_type->value]['limit'];
                if ($limit <= 0 || ($period->spent_micros + $period->reserved_micros + $estimatedCost) > $limit) {
                    throw new AiBudgetExceededException;
                }
                $period->forceFill([
                    'limit_micros' => $limit,
                    'reserved_micros' => $period->reserved_micros + $estimatedCost,
                ])->save();
            }

            return AiBudgetReservation::query()->create([
                'ai_budget_period_id' => $monthly->id,
                'daily_ai_budget_period_id' => $daily->id,
                'parser_execution_id' => $execution->id,
                'attempt_number' => $attempt,
                'reservation_key' => $key,
                'reserved_micros' => $estimatedCost,
                'reserved_at' => $now,
                'expires_at' => $now->addMinutes((int) config('fish.ai_parsing.budgets.reservation_ttl_minutes')),
            ]);
        }, attempts: 3);
    }

    public function settle(
        AiBudgetReservation $reservation,
        int $actualCostMicros,
        AiParserAttemptCostBasis $costBasis = AiParserAttemptCostBasis::Metered,
    ): void {
        DB::transaction(function () use ($reservation, $actualCostMicros, $costBasis): void {
            $locked = AiBudgetReservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if ($locked->status !== AiBudgetReservationStatus::Reserved) {
                return;
            }
            $this->periods($locked)->each(function (AiBudgetPeriod $period) use ($locked, $actualCostMicros): void {
                $period->forceFill([
                    'reserved_micros' => max(0, $period->reserved_micros - $locked->reserved_micros),
                    'spent_micros' => $period->spent_micros + $actualCostMicros,
                ])->save();
            });
            $locked->forceFill([
                'status' => AiBudgetReservationStatus::Settled,
                'actual_micros' => $actualCostMicros,
                'cost_basis' => $costBasis,
                'settled_at' => now(),
            ])->save();
        }, attempts: 3);
    }

    public function release(AiBudgetReservation $reservation): void
    {
        DB::transaction(function () use ($reservation): void {
            $locked = AiBudgetReservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if ($locked->status !== AiBudgetReservationStatus::Reserved) {
                return;
            }
            $this->periods($locked)->each(function (AiBudgetPeriod $period) use ($locked): void {
                $period->forceFill([
                    'reserved_micros' => max(0, $period->reserved_micros - $locked->reserved_micros),
                ])->save();
            });
            $locked->forceFill([
                'status' => AiBudgetReservationStatus::Released,
                'cost_basis' => AiParserAttemptCostBasis::None,
                'released_at' => now(),
            ])->save();
        }, attempts: 3);
    }

    /** @return Collection<int, AiBudgetPeriod> */
    private function periods(AiBudgetReservation $reservation): Collection
    {
        return AiBudgetPeriod::query()
            ->whereKey(array_filter([$reservation->ai_budget_period_id, $reservation->daily_ai_budget_period_id]))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** @param Collection<int, AiBudgetPeriod> $periods */
    private function releaseExpiredReservations(Collection $periods, CarbonImmutable $now): void
    {
        $expired = AiBudgetReservation::query()
            ->where('status', AiBudgetReservationStatus::Reserved)
            ->where('expires_at', '<=', $now)
            ->where(function ($query) use ($periods): void {
                $query->whereIn('ai_budget_period_id', $periods->modelKeys())
                    ->orWhereIn('daily_ai_budget_period_id', $periods->modelKeys());
            })
            ->lockForUpdate()
            ->get();

        foreach ($expired as $reservation) {
            foreach (array_filter([$reservation->ai_budget_period_id, $reservation->daily_ai_budget_period_id]) as $periodId) {
                $period = $periods->firstWhere('id', $periodId);
                if ($period instanceof AiBudgetPeriod) {
                    $period->reserved_micros = max(0, $period->reserved_micros - $reservation->reserved_micros);
                }
            }
        }

        $periods->each->save();
        if ($expired->isNotEmpty()) {
            AiBudgetReservation::query()->whereKey($expired->modelKeys())->update([
                'status' => AiBudgetReservationStatus::Released,
                'released_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
