<?php

namespace App\Services\AI;

use App\Enums\AiBudgetPeriodType;
use App\Enums\AiBudgetReservationStatus;
use App\Exceptions\AiBudgetExceededException;
use App\Models\AiBudgetPeriod;
use App\Models\AiBudgetReservation;
use App\Models\ParserDiagnosticReview;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class AiBudgetManager
{
    public function reserveMonthly(
        string $provider,
        string $reservationKey,
        int $estimatedCostMicros,
        ?ParserDiagnosticReview $review = null,
    ): AiBudgetReservation {
        if ($estimatedCostMicros <= 0) {
            throw new InvalidArgumentException('The estimated AI cost must be greater than zero.');
        }

        if ($provider === '' || Str::length($provider) > 32) {
            throw new InvalidArgumentException('The AI provider must be between 1 and 32 characters.');
        }

        if ($reservationKey === '' || Str::length($reservationKey) > 100) {
            throw new InvalidArgumentException('The AI reservation key must be between 1 and 100 characters.');
        }

        $limitMicros = (int) config('fish.ai_review.budgets.monthly_limit_micros');

        if ($limitMicros <= 0) {
            throw new AiBudgetExceededException;
        }

        return DB::transaction(function () use (
            $provider,
            $reservationKey,
            $estimatedCostMicros,
            $review,
            $limitMicros,
        ): AiBudgetReservation {
            $now = CarbonImmutable::now();
            $periodStart = $now->startOfMonth()->toDateString();
            $periodEnd = $now->endOfMonth()->toDateString();
            $periodTable = (new AiBudgetPeriod)->getTable();

            DB::table($periodTable)->insertOrIgnore([
                'provider' => $provider,
                'period_type' => AiBudgetPeriodType::Monthly->value,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'limit_micros' => $limitMicros,
                'reserved_micros' => 0,
                'spent_micros' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $period = AiBudgetPeriod::query()
                ->where('provider', $provider)
                ->where('period_type', AiBudgetPeriodType::Monthly)
                ->whereDate('period_start', $periodStart)
                ->lockForUpdate()
                ->firstOrFail();

            $this->releaseExpiredReservations($period, $now);

            $existingReservation = AiBudgetReservation::query()
                ->where('reservation_key', $reservationKey)
                ->first();

            if ($existingReservation !== null) {
                $matchesReservation = $existingReservation->ai_budget_period_id === $period->id
                    && $existingReservation->reserved_micros === $estimatedCostMicros
                    && $existingReservation->parser_diagnostic_review_id === $review?->id;

                if (! $matchesReservation || $existingReservation->status === AiBudgetReservationStatus::Released) {
                    throw new InvalidArgumentException('The AI reservation key is already associated with different or expired parameters.');
                }

                return $existingReservation;
            }

            $period->limit_micros = $limitMicros;
            $period->save();

            $reserved = AiBudgetPeriod::query()
                ->whereKey($period->id)
                ->whereRaw('spent_micros + reserved_micros + ? <= limit_micros', [$estimatedCostMicros])
                ->increment('reserved_micros', $estimatedCostMicros);

            if ($reserved !== 1) {
                throw new AiBudgetExceededException;
            }

            return AiBudgetReservation::query()->create([
                'ai_budget_period_id' => $period->id,
                'parser_diagnostic_review_id' => $review?->id,
                'reservation_key' => $reservationKey,
                'reserved_micros' => $estimatedCostMicros,
                'reserved_at' => $now,
                'expires_at' => $now->addMinutes((int) config('fish.ai_review.budgets.reservation_ttl_minutes')),
            ]);
        }, attempts: 3);
    }

    public function settle(AiBudgetReservation $reservation, int $actualCostMicros): AiBudgetReservation
    {
        if ($actualCostMicros < 0 || $actualCostMicros > $reservation->reserved_micros) {
            throw new InvalidArgumentException('The actual AI cost must be between zero and the reserved amount.');
        }

        return DB::transaction(function () use ($reservation, $actualCostMicros): AiBudgetReservation {
            $lockedReservation = AiBudgetReservation::query()->lockForUpdate()->findOrFail($reservation->id);

            if ($lockedReservation->status !== AiBudgetReservationStatus::Reserved) {
                return $lockedReservation;
            }

            $period = AiBudgetPeriod::query()->lockForUpdate()->findOrFail($lockedReservation->ai_budget_period_id);
            $period->reserved_micros -= $lockedReservation->reserved_micros;
            $period->spent_micros += $actualCostMicros;
            $period->save();

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

            $period = AiBudgetPeriod::query()->lockForUpdate()->findOrFail($lockedReservation->ai_budget_period_id);
            $period->reserved_micros -= $lockedReservation->reserved_micros;
            $period->save();

            $lockedReservation->forceFill([
                'status' => AiBudgetReservationStatus::Released,
                'released_at' => now(),
            ])->save();

            return $lockedReservation->refresh();
        }, attempts: 3);
    }

    private function releaseExpiredReservations(AiBudgetPeriod $period, CarbonImmutable $now): void
    {
        $expiredReservations = AiBudgetReservation::query()
            ->whereBelongsTo($period, 'budgetPeriod')
            ->where('status', AiBudgetReservationStatus::Reserved)
            ->where('expires_at', '<=', $now)
            ->lockForUpdate()
            ->get(['id', 'reserved_micros']);

        if ($expiredReservations->isEmpty()) {
            return;
        }

        $releasedMicros = $expiredReservations->sum('reserved_micros');
        $period->reserved_micros = max(0, $period->reserved_micros - $releasedMicros);
        $period->save();

        AiBudgetReservation::query()
            ->whereKey($expiredReservations->modelKeys())
            ->update([
                'status' => AiBudgetReservationStatus::Released,
                'released_at' => $now,
                'updated_at' => $now,
            ]);
    }
}
