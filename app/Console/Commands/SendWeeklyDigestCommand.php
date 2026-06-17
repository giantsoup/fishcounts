<?php

namespace App\Console\Commands;

use App\Enums\NotificationChannel;
use App\Jobs\SendWeeklyDigestJob;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fish:send-weekly-digest {--date=}')]
#[Description('Dispatch weekly fishing digest notifications.')]
class SendWeeklyDigestCommand extends Command
{
    public function handle(): int
    {
        $date = CarbonImmutable::parse($this->option('date') ?: today())->toDateString();
        $queued = 0;

        User::query()
            ->whereHas('alertRules', fn ($query) => $query->where('include_in_weekly_digest', true))
            ->with('notificationDestinations')
            ->chunkById(100, function ($users) use ($date, &$queued): void {
                foreach ($users as $user) {
                    if ($user->notificationDestinations->where('channel', NotificationChannel::Email)->where('is_enabled', true)->isNotEmpty()) {
                        SendWeeklyDigestJob::dispatch($user->id, $date, NotificationChannel::Email);
                        $queued++;
                    }

                    if ($user->notificationDestinations->where('channel', NotificationChannel::Discord)->where('is_enabled', true)->isNotEmpty()) {
                        SendWeeklyDigestJob::dispatch($user->id, $date, NotificationChannel::Discord);
                        $queued++;
                    }
                }
            });

        $this->info("Queued {$queued} weekly digest delivery jobs for {$date}.");

        return self::SUCCESS;
    }
}
