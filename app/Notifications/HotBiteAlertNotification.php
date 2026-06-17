<?php

namespace App\Notifications;

use App\Models\AlertEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class HotBiteAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly AlertEvent $alertEvent) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->alertEvent->loadMissing(['alertRule.species', 'scoreResult']);
        $rule = $this->alertEvent->alertRule;

        return (new MailMessage)
            ->subject("Hot bite alert: {$rule->name}")
            ->greeting('Hot bite threshold crossed')
            ->line("{$rule->species->name} scored {$this->alertEvent->score} for {$this->alertEvent->event_date->toFormattedDateString()}.")
            ->line("Total count: {$this->alertEvent->scoreResult?->total_count}.")
            ->action('View scores', route('scores.index'));
    }
}
