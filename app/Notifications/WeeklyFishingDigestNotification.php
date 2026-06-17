<?php

namespace App\Notifications;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WeeklyFishingDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly User $user,
        public readonly CarbonImmutable $weekEnding,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $rules = $this->user->alertRules()
            ->with(['species', 'scoreResults' => fn ($query) => $query->whereBetween('score_date', [
                $this->weekEnding->subDays(6)->toDateString(),
                $this->weekEnding->toDateString(),
            ])->latest('score_date')])
            ->where('include_in_weekly_digest', true)
            ->get();

        $message = (new MailMessage)
            ->subject('Weekly fishing digest')
            ->greeting('Weekly fishing digest')
            ->line('Scores for your digest-enabled alert rules:');

        foreach ($rules as $rule) {
            $latest = $rule->scoreResults->first();
            $message->line($latest === null
                ? "{$rule->name}: no scores this week."
                : "{$rule->name}: latest {$latest->score} ({$latest->level->value}) on {$latest->score_date->toDateString()}.");
        }

        return $message->action('View scores', route('scores.index'));
    }
}
