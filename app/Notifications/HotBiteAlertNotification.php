<?php

namespace App\Notifications;

use App\Models\AlertEvent;
use App\Services\Environmental\EnvironmentalConditionFormatter;
use App\Services\Notifications\TripDecisionBuilder;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class HotBiteAlertNotification extends Notification
{
    /**
     * @param  Collection<int, array<string, mixed>>|null  $tripOptions
     * @param  Collection<int, array<string, mixed>>|null  $tripRecommendations
     */
    public function __construct(
        public readonly AlertEvent $alertEvent,
        public readonly ?Collection $tripOptions = null,
        public readonly ?Collection $tripRecommendations = null,
    ) {}

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
        $tripDecisionBuilder = app(TripDecisionBuilder::class);
        $environmentalCondition = app(EnvironmentalConditionFormatter::class)->forDate($this->alertEvent->event_date->toImmutable());
        $tripOptions = $this->tripOptions ?? $tripDecisionBuilder->rankedTrips(
            $rule,
            $this->alertEvent->event_date->toImmutable(),
            $this->alertEvent->event_date->toImmutable(),
        );
        $tripRecommendations = $this->tripRecommendations ?? $tripDecisionBuilder->recommendedBoats($tripOptions);

        return (new MailMessage)
            ->subject("Hot bite alert: {$rule->name}")
            ->markdown('mail.hot-bite-alert', [
                'alertEvent' => $this->alertEvent,
                'levelLabel' => str($this->alertEvent->level->value)->replace('_', ' ')->headline()->toString(),
                'rule' => $rule,
                'scoreResult' => $scoreResult,
                'scoresUrl' => route('scores.index'),
                'tripOptions' => $tripOptions,
                'tripRecommendations' => $tripRecommendations,
                'environmentalCondition' => $environmentalCondition,
            ]);
    }
}
