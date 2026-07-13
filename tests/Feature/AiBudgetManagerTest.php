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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

        $monthlyPeriod = $this->period('monthly');
        $dailyPeriod = $this->period('daily');
        $this->assertSame(1000, $monthlyPeriod->reserved_micros);
        $this->assertSame(1000, $dailyPeriod->reserved_micros);
        $this->assertSame(0, $monthlyPeriod->spent_micros);
        $this->assertSame('2026-07-01', $monthlyPeriod->period_start->toDateString());
        $this->assertSame('2026-07-31', $monthlyPeriod->period_end->toDateString());
        $this->assertSame('2026-07-12', $dailyPeriod->period_start->toDateString());

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
        $this->assertSame(600, $this->period('daily')->reserved_micros);
        $this->assertSame(600, $this->period('monthly')->reserved_micros);
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

        $this->assertDatabaseCount('ai_budget_periods', 2);
        $this->assertSame(600, $this->period('monthly')->reserved_micros);
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
        $this->assertSame(1000, $this->period('daily')->reserved_micros);
        $this->assertSame(1000, $this->period('monthly')->reserved_micros);
    }

    public function test_settlement_and_release_atomically_reconcile_the_budget(): void
    {
        config()->set('fish.ai_review.budgets.monthly_limit_micros', 1000);
        $manager = app(AiBudgetManager::class);
        $settledReservation = $manager->reserveMonthly('openai', 'settled-review', 600);
        $releasedReservation = $manager->reserveMonthly('openai', 'released-review', 400);

        $settled = $manager->settle($settledReservation, 500);
        $released = $manager->release($releasedReservation);
        $monthlyPeriod = $this->period('monthly');
        $dailyPeriod = $this->period('daily');

        $this->assertSame(AiBudgetReservationStatus::Settled, $settled->status);
        $this->assertSame(500, $settled->actual_micros);
        $this->assertSame(AiBudgetReservationStatus::Released, $released->status);
        $this->assertSame(0, $monthlyPeriod->reserved_micros);
        $this->assertSame(500, $monthlyPeriod->spent_micros);
        $this->assertSame(0, $dailyPeriod->reserved_micros);
        $this->assertSame(500, $dailyPeriod->spent_micros);
    }

    public function test_actual_cost_cannot_exceed_the_atomic_reservation(): void
    {
        config()->set('fish.ai_review.budgets.monthly_limit_micros', 1000);
        $reservation = app(AiBudgetManager::class)->reserveMonthly('openai', 'review', 500);

        $this->expectException(InvalidArgumentException::class);
        app(AiBudgetManager::class)->settle($reservation, 501);
    }

    public function test_daily_and_monthly_hard_limits_are_enabled(): void
    {
        $this->assertSame(5000000, config('fish.ai_review.budgets.daily_limit_micros'));
        $this->assertTrue(config('fish.ai_review.budgets.hard_stop'));
        $this->assertSame(50000000, config('fish.ai_review.budgets.monthly_limit_micros'));
    }

    public function test_daily_limit_stops_spend_even_when_monthly_budget_remains(): void
    {
        config()->set('fish.ai_review.budgets.daily_limit_micros', 1000);
        config()->set('fish.ai_review.budgets.monthly_limit_micros', 10_000);
        $manager = app(AiBudgetManager::class);
        $manager->reserve('openai', 'daily-cap', 1000);

        $this->expectException(AiBudgetExceededException::class);
        $manager->reserve('openai', 'daily-over-cap', 1);
    }

    public function test_daily_budget_uses_the_configured_operational_timezone_without_shifting_reservation_timestamps(): void
    {
        CarbonImmutable::setTestNow('2026-07-13 06:30:00');
        config()->set('fish.ai_review.budgets.timezone', 'America/Los_Angeles');

        $reservation = app(AiBudgetManager::class)->reserve('openai', 'timezone-boundary', 1000);

        $this->assertSame('2026-07-12', $this->period('daily')->period_start->toDateString());
        $this->assertSame('2026-07-13 06:30:00', $reservation->reserved_at->format('Y-m-d H:i:s'));
    }

    public function test_concurrent_reservations_cannot_cross_either_hard_cap(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The PCNTL extension is required for the concurrency test.');
        }

        $databasePath = tempnam(sys_get_temp_dir(), 'fishcounts-phase9-budget-');
        $this->assertNotFalse($databasePath);
        $originalConnection = config('database.default');

        try {
            config()->set('database.connections.phase9_budget_concurrency', [
                'driver' => 'sqlite',
                'database' => $databasePath,
                'prefix' => '',
                'foreign_key_constraints' => true,
                'busy_timeout' => 5000,
                'journal_mode' => 'WAL',
                'synchronous' => 'NORMAL',
            ]);
            config()->set('database.default', 'phase9_budget_concurrency');
            config()->set('fish.ai_review.budgets.daily_limit_micros', 1000);
            config()->set('fish.ai_review.budgets.monthly_limit_micros', 1000);
            DB::purge('phase9_budget_concurrency');
            Artisan::call('migrate:fresh', ['--database' => 'phase9_budget_concurrency', '--force' => true]);
            DB::disconnect('phase9_budget_concurrency');

            $processes = [];
            foreach (['concurrent-a', 'concurrent-b'] as $key) {
                $processId = pcntl_fork();
                $this->assertNotSame(-1, $processId);

                if ($processId === 0) {
                    DB::purge('phase9_budget_concurrency');

                    try {
                        app(AiBudgetManager::class)->reserve('openai', $key, 600);
                        file_put_contents($databasePath.".{$key}", 'reserved');
                    } catch (AiBudgetExceededException) {
                        file_put_contents($databasePath.".{$key}", 'denied');
                    }

                    exit(0);
                }

                $processes[$processId] = $key;
            }

            $results = [];
            foreach ($processes as $processId => $key) {
                pcntl_waitpid($processId, $status);
                $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0);
                $resultPath = $databasePath.".{$key}";
                $results[] = file_get_contents($resultPath);
                unlink($resultPath);
            }

            sort($results);
            $this->assertSame(['denied', 'reserved'], $results);
            DB::purge('phase9_budget_concurrency');
            $this->assertSame(600, $this->period('daily')->reserved_micros);
            $this->assertSame(600, $this->period('monthly')->reserved_micros);
        } finally {
            DB::purge('phase9_budget_concurrency');
            config()->set('database.default', $originalConnection);

            if (is_string($databasePath) && is_file($databasePath)) {
                unlink($databasePath);
            }
        }
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

    private function period(string $type): AiBudgetPeriod
    {
        return AiBudgetPeriod::query()->where('period_type', $type)->sole();
    }
}
