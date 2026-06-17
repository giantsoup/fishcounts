<?php

namespace Database\Seeders;

use App\Models\AlertRule;
use App\Models\Region;
use App\Models\Species;
use App\Models\TripType;
use App\Models\User;
use Illuminate\Database\Seeder;

class DefaultAlertRuleSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('email', config('fish.admin.email'))->first();

        if (! $admin) {
            return;
        }

        $rule = AlertRule::query()->updateOrCreate(
            ['user_id' => $admin->id, 'name' => 'Local Yellowtail'],
            [
                'species_id' => Species::query()->where('slug', 'yellowtail')->firstOrFail()->id,
                'is_enabled' => true,
                'minimum_score' => 70,
                'trend_window_days' => 3,
                'baseline_window_days' => 7,
                'email_enabled' => true,
                'discord_enabled' => (bool) config('services.discord.webhook_url'),
                'include_in_weekly_digest' => true,
            ],
        );

        $rule->regions()->sync(Region::query()->where('slug', 'san-diego')->pluck('id'));
        $rule->tripTypes()->sync(TripType::query()
            ->whereIn('slug', ['1-2-day', '1-2-day-am', '1-2-day-pm', '3-4-day', 'full-day', 'full-day-coronado-islands'])
            ->pluck('id'));
    }
}
