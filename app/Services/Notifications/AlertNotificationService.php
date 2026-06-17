<?php

namespace App\Services\Notifications;

use App\Enums\AlertEventStatus;
use App\Enums\AlertEventType;
use App\Enums\NotificationChannel;
use App\Jobs\SendHotBiteAlertJob;
use App\Models\AlertEvent;
use App\Models\ScoreResult;
use Carbon\CarbonImmutable;

class AlertNotificationService
{
    public function dispatchThresholdCrossings(CarbonImmutable $date): int
    {
        $dispatched = 0;

        ScoreResult::query()
            ->with(['alertRule.user', 'alertRule.species'])
            ->whereDate('score_date', $date->toDateString())
            ->whereHas('alertRule', fn ($query) => $query->where('is_enabled', true))
            ->get()
            ->each(function (ScoreResult $scoreResult) use (&$dispatched): void {
                $rule = $scoreResult->alertRule;

                if ($scoreResult->score < $rule->minimum_score || ! $this->crossedThreshold($scoreResult)) {
                    return;
                }

                $event = AlertEvent::query()->firstOrCreate(
                    [
                        'alert_rule_id' => $rule->id,
                        'event_type' => AlertEventType::ThresholdCrossed,
                        'event_date' => $scoreResult->score_date,
                    ],
                    [
                        'user_id' => $rule->user_id,
                        'score_result_id' => $scoreResult->id,
                        'level' => $scoreResult->level,
                        'score' => $scoreResult->score,
                        'status' => AlertEventStatus::Pending,
                    ],
                );

                if (! $event->wasRecentlyCreated) {
                    return;
                }

                if ($rule->email_enabled) {
                    SendHotBiteAlertJob::dispatch($event->id, NotificationChannel::Email);
                    $dispatched++;
                }

                if ($rule->discord_enabled) {
                    SendHotBiteAlertJob::dispatch($event->id, NotificationChannel::Discord);
                    $dispatched++;
                }
            });

        return $dispatched;
    }

    private function crossedThreshold(ScoreResult $scoreResult): bool
    {
        $previous = ScoreResult::query()
            ->where('alert_rule_id', $scoreResult->alert_rule_id)
            ->whereDate('score_date', '<', $scoreResult->score_date)
            ->latest('score_date')
            ->first();

        return $previous === null || $previous->score < $scoreResult->alertRule->minimum_score;
    }
}
