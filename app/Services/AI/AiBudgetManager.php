<?php

namespace App\Services\AI;

use App\Enums\AiBudgetPeriodType;
use App\Enums\AiBudgetReservationStatus;
use App\Exceptions\AiBudgetExceededException;
use App\Models\AiBudgetPeriod;
use App\Models\AiBudgetReservation;
use App\Models\ParserDiagnosticReview;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class AiBudgetManager
{
    public function reserve(
        string $provider,
        string $reservationKey,
        int $estimatedCostMicros,
        ?ParserDiagnosticReview $review = null,
    ): AiBudgetReservation {
        $this->validateReservation($provider, $reservationKey, $estimatedCostMicros);

        $limits = $this->configuredLimits();

        return DB::transaction(function () use ($provider, $reservationKey, $estimatedCostMicros, $review, $limits): AiBudgetReservation {
            $now = CarbonImmutable::now();
            $periodNow = $now->setTimezone((string) config('fish.ai_review.budgets.timezone'));
            $this->createPeriod($provider, AiBudgetPeriodType::Daily, $periodNow, $now, $limits[AiBudgetPeriodType::Daily->value]);
            $this->createPeriod($provider, AiBudgetPeriodType::Monthly, $periodNow, $now, $limits[AiBudgetPeriodType::Monthly->value]);

            $periods = AiBudgetPeriod::query()
                ->where('provider', $provider)
                ->where(function ($query) use ($periodNow): void {
                    $query->where(function ($query) use ($periodNow): void {
                        $query->where('period_type', AiBudgetPeriodType::Daily)
                            ->whereDate('period_start', $periodNow->toDateString());
                    })->orWhere(function ($query) use ($periodNow): void {
                        $query->where('period_type', AiBudgetPeriodType::Monthly)
                            ->whereDate('period_start', $periodNow->startOfMonth()->toDateString());
                    });
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (AiBudgetPeriod $period): string => $period->period_type->value);

            $dailyPeriod = $periods->get(AiBudgetPeriodType::Daily->value);
            $monthlyPeriod = $periods->get(AiBudgetPeriodType::Monthly->value);

            if (! $dailyPeriod instanceof AiBudgetPeriod || ! $monthlyPeriod instanceof AiBudgetPeriod) {
                throw new AiBudgetExceededException;
            }

            $this->releaseExpiredReservations($periods, $now);

            $existingReservation = AiBudgetReservation::query()
                ->where('reservation_key', $reservationKey)
                ->first();

            if ($existingReservation !== null) {
                $matchesReservation = $existingReservation->ai_budget_period_id === $monthlyPeriod->id
                    && $existingReservation->daily_ai_budget_period_id === $dailyPeriod->id
                    && $existingReservation->reserved_micros === $estimatedCostMicros
                    && $existingReservation->parser_diagnostic_review_id === $review?->id;

                if (! $matchesReservation || $existingReservation->status === AiBudgetReservationStatus::Released) {
                    throw new InvalidArgumentException('The AI reservation key is already associated with different or expired parameters.');
                }

                return $existingReservation;
            }

            foreach ($periods as $period) {
                $period->limit_micros = $limits[$period->period_type->value];
                $period->save();

                $reserved = AiBudgetPeriod::query()
                    ->whereKey($period->id)
                    ->whereRaw('spent_micros + reserved_micros + ? <= limit_micros', [$estimatedCostMicros])
                    ->increment('reserved_micros', $estimatedCostMicros);

                if ($reserved !== 1) {
                    throw new AiBudgetExceededException;
                }
            }

            return AiBudgetReservation::query()->create([
                'ai_budget_period_id' => $monthlyPeriod->id,
                'daily_ai_budget_period_id' => $dailyPeriod->id,
                'parser_diagnostic_review_id' => $review?->id,
                'reservation_key' => $reservationKey,
                'reserved_micros' => $estimatedCostMicros,
                'reserved_at' => $now,
                'expires_at' => $now->addMinutes((int) config('fish.ai_review.budgets.reservation_ttl_minutes')),
            ]);
        }, attempts: 3);
    }

    public function reserveMonthly(
        string $provider,
        string $reservationKey,
        int $estimatedCostMicros,
        ?ParserDiagnosticReview $review = null,
    ): AiBudgetReservation {
        return $this->reserve($provider, $reservationKey, $estimatedCostMicros, $review);
    }

    public function settle(AiBudgetReservation $reservation, int $actualCostMicros): AiBudgetReservation
    {
        if ($actualCostMicros < 0) {
            throw new InvalidArgumentException('The actual AI cost cannot be negative.');
        }

        return DB::transaction(function () use ($reservation, $actualCostMicros): AiBudgetReservation {
            $lockedReservation = AiBudgetReservation::query()->lockForUpdate()->findOrFail($reservation->id);

            if ($lockedReservation->status !== AiBudgetReservationStatus::Reserved) {
                return $lockedReservation;
            }

            foreach ($this->lockReservationPeriods($lockedReservation) as $period) {
                $period->reserved_micros = max(0, $period->reserved_micros - $lockedReservation->reserved_micros);
                $period->spent_micros += $actualCostMicros;
                $period->save();
            }

            $lockedReservation->forceFill([
                'status' => AiBudgetReservationStatus::Settled,
                'actual_micros' => $actualCostMicros,
                'settled_at' => now(),
            ])->save();

            return $lockedReservation->refresh();
        }, attempts: 3);
    }

    public function release(AiBudgetReservation $reservation): AiBudgetReservation
    {
        return DB::transaction(function () use ($reservation): AiBudgetReservation {
            $lockedReservation = AiBudgetReservation::query()->lockForUpdate()->findOrFail($reservation->id);

            if ($lockedReservation->status !== AiBudgetReservationStatus::Reserved) {
                return $lockedReservation;
            }

            foreach ($this->lockReservationPeriods($lockedReservation) as $period) {
                $period->reserved_micros = max(0, $period->reserved_micros - $lockedReservation->reserved_micros);
                $period->save();
            }

            $lockedReservation->forceFill([
                'status' => AiBudgetReservationStatus::Released,
                'released_at' => now(),
            ])->save();

            return $lockedReservation->refresh();
        }, attempts: 3);
    }

    /** @return array<string, int> */
    private function configuredLimits(): array
    {
        $dailyLimit = (int) config('fish.ai_review.budgets.daily_limit_micros');
        $monthlyLimit = (int) config('fish.ai_review.budgets.monthly_limit_micros');

        if ($dailyLimit < 0 || $monthlyLimit <= 0) {
            throw new AiBudgetExceededException;
        }

        $limits = [
            AiBudgetPeriodType::Daily->value => $dailyLimit > 0 ? $dailyLimit : $monthlyLimit,
            AiBudgetPeriodType::Monthly->value => $monthlyLimit,
        ];

        return $limits;
    }

    private function createPeriod(
        string $provider,
        AiBudgetPeriodType $type,
        CarbonImmutable $periodNow,
        CarbonImmutable $timestamp,
        int $limitMicros,
    ): void {
        $start = $type === AiBudgetPeriodType::Daily ? $periodNow->startOfDay() : $periodNow->startOfMonth();
        $end = $type === AiBudgetPeriodType::Daily ? $periodNow->endOfDay() : $periodNow->endOfMonth();

        DB::table((new AiBudgetPeriod)->getTable())->insertOrIgnore([
            'provider' => $provider,
            'period_type' => $type->value,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'limit_micros' => $limitMicros,
            'reserved_micros' => 0,
            'spent_micros' => 0,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    /** @param Collection<int, AiBudgetPeriod> $periods */
    private function releaseExpiredReservations(Collection $periods, CarbonImmutable $now): void
    {
        $periodIds = $periods->modelKeys();
        $expiredReservations = AiBudgetReservation::query()
            ->where('status', AiBudgetReservationStatus::Reserved)
            ->where('expires_at', '<=', $now)
            ->where(function ($query) use ($periodIds): void {
                $query->whereIn('ai_budget_period_id', $periodIds)
                    ->orWhereIn('daily_ai_budget_period_id', $periodIds);
            })
            ->lockForUpdate()
            ->get(['id', 'ai_budget_period_id', 'daily_ai_budget_period_id', 'reserved_micros']);

        foreach ($expiredReservations as $reservation) {
            foreach (array_filter([$reservation->ai_budget_period_id, $reservation->daily_ai_budget_period_id]) as $periodId) {
                $period = $periods->firstWhere('id', $periodId);

                if ($period instanceof AiBudgetPeriod) {
                    $period->reserved_micros = max(0, $period->reserved_micros - $reservation->reserved_micros);
                }
            }
        }

        foreach ($periods as $period) {
            $period->save();
        }

        if ($expiredReservations->isNotEmpty()) {
            AiBudgetReservation::query()->whereKey($expiredReservations->modelKeys())->update([
                'status' => AiBudgetReservationStatus::Released,
                'released_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** @return Collection<int, AiBudgetPeriod> */
    private function lockReservationPeriods(AiBudgetReservation $reservation): Collection
    {
        return AiBudgetPeriod::query()
            ->whereKey(array_filter([$reservation->ai_budget_period_id, $reservation->daily_ai_budget_period_id]))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function validateReservation(string $provider, string $reservationKey, int $estimatedCostMicros): void
    {
        if ($estimatedCostMicros <= 0) {
            throw new InvalidArgumentException('The estimated AI cost must be greater than zero.');
        }

        if ($provider === '' || Str::length($provider) > 32) {
            throw new InvalidArgumentException('The AI provider must be between 1 and 32 characters.');
        }

        if ($reservationKey === '' || Str::length($reservationKey) > 100) {
            throw new InvalidArgumentException('The AI reservation key must be between 1 and 100 characters.');
        }
    }
}
