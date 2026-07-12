<?php

namespace Tests\Feature;

use App\Enums\AiBudgetReservationStatus;
use App\Exceptions\AiBudgetExceededException;
use App\Models\AiBudgetPeriod;
use App\Models\AiBudgetReservation;
use App\Services\AI\AiBudgetManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class AiBudgetManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_atomic_conditional_reservations_cannot_exceed_the_monthly_hard_cap(): void
    {
        CarbonImmutable::setTestNow('2026-07-12 12:00:00');
        config()->set('fish.ai_review.budgets.monthly_limit_micros', 1000);
        $manager = app(AiBudgetManager::class);

        $manager->reserveMonthly('openai', 'review-1', 600);
        $manager->reserveMonthly('openai', 'review-2', 400);

        $period = AiBudgetPeriod::query()->sole();
        $this->assertSame(1000, $period->reserved_micros);
        $this->assertSame(0, $period->spent_micros);
        $this->assertSame('2026-07-01', $period->period_start->toDateString());
        $this->assertSame('2026-07-31', $period->period_end->toDateString());

        $this->expectException(AiBudgetExceededException::class);
        $manager->reserveMonthly('openai', 'review-3', 1);
    }

    public function test_reservation_keys_are_idempotent_without_double_counting(): void
    {
        config()->set('fish.ai_review.budgets.monthly_limit_micros', 1000);
        $manager = app(AiBudgetManager::class);

        $first = $manager->reserveMonthly('openai', 'same-review', 600);
        $duplicate = $manager->reserveMonthly('openai', 'same-review', 600);

        $this->assertTrue($first->is($duplicate));
        $this->assertSame(600, AiBudgetPeriod::query()->sole()->reserved_micros);
    }

    public function test_idempotency_key_rejects_different_amount_provider_or_period(): void
    {
        CarbonImmutable::setTestNow('2026-07-12 12:00:00');
        config()->set('fish.ai_review.budgets.monthly_limit_micros', 2000);
        $manager = app(AiBudgetManager::class);
        $manager->reserveMonthly('openai', 'same-key', 600);

        foreach ([
            fn (): AiBudgetReservation => $manager->reserveMonthly('openai', 'same-key', 601),
            fn (): AiBudgetReservation => $manager->reserveMonthly('another-provider', 'same-key', 600),
            function () use ($manager): AiBudgetReservation {
                CarbonImmutable::setTestNow('2026-08-01 00:00:00');

                return $manager->reserveMonthly('openai', 'same-key', 600);
            },
        ] as $reservationAttempt) {
            try {
                $reservationAttempt();
                $this->fail('Expected a mismatched idempotency key to be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertDatabaseCount('ai_budget_periods', 1);
        $this->assertSame(600, AiBudgetPeriod::query()->sole()->reserved_micros);
    }

    public function test_expired_reservations_are_released_before_checking_the_cap(): void
    {
        CarbonImmutable::setTestNow('2026-07-12 12:00:00');
        config()->set('fish.ai_review.budgets.monthly_limit_micros', 1000);
        config()->set('fish.ai_review.budgets.reservation_ttl_minutes', 15);
        $manager = app(AiBudgetManager::class);
        $expired = $manager->reserveMonthly('openai', 'expired-review', 1000);

        CarbonImmutable::setTestNow('2026-07-12 12:16:00');
        $replacement = $manager->reserveMonthly('openai', 'replacement-review', 1000);

        $this->assertSame(AiBudgetReservationStatus::Released, $expired->refresh()->status);
        $this->assertNotNull($expired->released_at);
        $this->assertSame(AiBudgetReservationStatus::Reserved, $replacement->status);
        $this->assertSame(1000, AiBudgetPeriod::query()->sole()->reserved_micros);
    }

    public function test_settlement_and_release_atomically_reconcile_the_budget(): void
    {
        config()->set('fish.ai_review.budgets.monthly_limit_micros', 1000);
        $manager = app(AiBudgetManager::class);
        $settledReservation = $manager->reserveMonthly('openai', 'settled-review', 600);
        $releasedReservation = $manager->reserveMonthly('openai', 'released-review', 400);

        $settled = $manager->settle($settledReservation, 500);
        $released = $manager->release($releasedReservation);
        $period = AiBudgetPeriod::query()->sole();

        $this->assertSame(AiBudgetReservationStatus::Settled, $settled->status);
        $this->assertSame(500, $settled->actual_micros);
        $this->assertSame(AiBudgetReservationStatus::Released, $released->status);
        $this->assertSame(0, $period->reserved_micros);
        $this->assertSame(500, $period->spent_micros);
    }

    public function test_actual_cost_cannot_exceed_the_atomic_reservation(): void
    {
        config()->set('fish.ai_review.budgets.monthly_limit_micros', 1000);
        $reservation = app(AiBudgetManager::class)->reserveMonthly('openai', 'review', 500);

        $this->expectException(InvalidArgumentException::class);
        app(AiBudgetManager::class)->settle($reservation, 501);
    }

    public function test_daily_limit_is_intentionally_disabled(): void
    {
        $this->assertNull(config('fish.ai_review.budgets.daily_limit_micros'));
        $this->assertTrue(config('fish.ai_review.budgets.hard_stop'));
        $this->assertSame(50000000, config('fish.ai_review.budgets.monthly_limit_micros'));
    }

    public function test_database_enforces_one_budget_bucket_per_provider_and_period(): void
    {
        $period = AiBudgetPeriod::factory()->create();

        $this->expectException(QueryException::class);

        AiBudgetPeriod::factory()->create([
            'provider' => $period->provider,
            'period_type' => $period->period_type,
            'period_start' => $period->period_start,
        ]);
    }
}
