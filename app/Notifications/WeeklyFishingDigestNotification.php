<?php

namespace App\Notifications;

use App\Models\User;
use App\Services\Environmental\EnvironmentalConditionFormatter;
use App\Services\Notifications\WeeklyDigestBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class WeeklyFishingDigestNotification extends Notification
{
    /**
     * @param  Collection<int, array<string, mixed>>|null  $summaries
     */
    public function __construct(
        public readonly User $user,
        public readonly CarbonImmutable $weekEnding,
        public readonly ?Collection $summaries = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Weekly fishing digest')
            ->markdown('mail.weekly-fishing-digest', [
                'weeklyEnvironmentalLine' => app(EnvironmentalConditionFormatter::class)->weeklyLine($this->weekEnding->subDays(6), $this->weekEnding),
                'scoresUrl' => route('scores.index'),
                'summaries' => $this->summaries ?? app(WeeklyDigestBuilder::class)->summaries($this->user, $this->weekEnding),
                'weekEnding' => $this->weekEnding,
            ]);
    }
}
