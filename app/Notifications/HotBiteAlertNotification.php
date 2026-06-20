<?php

namespace App\Notifications;

use App\Models\AlertEvent;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class HotBiteAlertNotification extends Notification
{
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
        $scoreResult = $this->alertEvent->scoreResult;

        return (new MailMessage)
            ->subject("Hot bite alert: {$rule->name}")
            ->markdown('mail.hot-bite-alert', [
                'alertEvent' => $this->alertEvent,
                'countPerAngler' => $scoreResult?->count_per_angler === null ? 'n/a' : (string) $scoreResult->count_per_angler,
                'levelLabel' => str($this->alertEvent->level->value)->replace('_', ' ')->headline()->toString(),
                'rule' => $rule,
                'scoreResult' => $scoreResult,
                'scoresUrl' => route('scores.index'),
            ]);
    }
}
