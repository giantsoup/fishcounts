<?php

namespace App\Notifications;

use App\Models\User;
use App\Services\Notifications\WeeklyDigestBuilder;
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
        $lines = app(WeeklyDigestBuilder::class)->lines($this->user, $this->weekEnding);

        $message = (new MailMessage)
            ->subject('Weekly fishing digest')
            ->greeting('Weekly fishing digest')
            ->line('Scores for your digest-enabled alert rules:');

        foreach ($lines as $line) {
            $message->line($line);
        }

        return $message->action('View scores', route('scores.index'));
    }
}
