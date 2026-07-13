<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $timezone = (string) config('fish.ai_review.budgets.timezone', 'America/Los_Angeles');
        $limitMicros = (int) config('fish.ai_review.budgets.daily_limit_micros', 5_000_000);

        DB::table('ai_budget_reservations as reservations')
            ->join('ai_budget_periods as monthly_period', 'monthly_period.id', '=', 'reservations.ai_budget_period_id')
            ->whereNull('reservations.daily_ai_budget_period_id')
            ->select([
                'reservations.id as id',
                'reservations.status',
                'reservations.reserved_micros',
                'reservations.actual_micros',
                'reservations.reserved_at',
                'monthly_period.provider',
            ])
            ->orderBy('reservations.id')
            ->chunkById(500, function ($reservations) use ($timezone, $limitMicros): void {
                foreach ($reservations as $reservation) {
                    $periodDate = CarbonImmutable::parse($reservation->reserved_at, 'UTC')->setTimezone($timezone)->toDateString();
                    $now = now();

                    DB::table('ai_budget_periods')->insertOrIgnore([
                        'provider' => $reservation->provider,
                        'period_type' => 'daily',
                        'period_start' => $periodDate,
                        'period_end' => $periodDate,
                        'limit_micros' => $limitMicros,
                        'reserved_micros' => 0,
                        'spent_micros' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $dailyPeriodId = DB::table('ai_budget_periods')
                        ->where('provider', $reservation->provider)
                        ->where('period_type', 'daily')
                        ->whereDate('period_start', $periodDate)
                        ->value('id');

                    DB::table('ai_budget_reservations')->where('id', $reservation->id)->update([
                        'daily_ai_budget_period_id' => $dailyPeriodId,
                        'updated_at' => $now,
                    ]);

                    if ($reservation->status === 'reserved') {
                        DB::table('ai_budget_periods')->where('id', $dailyPeriodId)->increment('reserved_micros', $reservation->reserved_micros);
                    }

                    if ($reservation->status === 'settled') {
                        DB::table('ai_budget_periods')->where('id', $dailyPeriodId)->increment('spent_micros', $reservation->actual_micros ?? 0);
                    }
                }
            }, 'reservations.id', 'id');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('ai_budget_reservations')->update(['daily_ai_budget_period_id' => null]);
        DB::table('ai_budget_periods')->where('period_type', 'daily')->delete();
    }
};
