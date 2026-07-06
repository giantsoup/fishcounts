<?php

namespace App\Http\Controllers;

use App\Enums\AlertEventStatus;
use App\Enums\AlertEventType;
use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Models\AlertEvent;
use App\Models\NotificationDelivery;
use App\Models\ScoreResult;
use App\Notifications\HotBiteAlertNotification;
use App\Services\Notifications\TripDecisionBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Throwable;

class ScoreHotBiteEmailController extends Controller
{
    public function __invoke(Request $request, ScoreResult $scoreResult): RedirectResponse
    {
        $scoreResult->loadMissing(['alertRule.user', 'alertRule.species']);
        $rule = $scoreResult->alertRule;
        $user = $rule->user;

        abort_unless($request->user()?->is($user), 403);

        $event = AlertEvent::query()->firstOrNew([
            'alert_rule_id' => $rule->id,
            'event_type' => AlertEventType::ThresholdCrossed,
            'event_date' => $scoreResult->score_date,
        ]);
        $event->fill([
            'user_id' => $user->id,
            'score_result_id' => $scoreResult->id,
            'level' => $scoreResult->level,
            'score' => $scoreResult->score,
            'error_message' => null,
        ]);

        if (! $event->exists) {
            $event->status = AlertEventStatus::Pending;
        }

        $event->save();
        $event->setRelation('alertRule', $rule);
        $tripDecisionBuilder = app(TripDecisionBuilder::class);
        $tripOptions = $tripDecisionBuilder->rankedTrips(
            $rule,
            $event->event_date->toImmutable(),
            $event->event_date->toImmutable(),
        );
        $tripRecommendations = $tripDecisionBuilder->recommendedBoats($tripOptions);

        $delivery = NotificationDelivery::query()->create([
            'alert_event_id' => $event->id,
            'user_id' => $user->id,
            'channel' => NotificationChannel::Email,
            'notification_type' => HotBiteAlertNotification::class,
            'status' => NotificationDeliveryStatus::Pending,
            'attempted_at' => now(),
            'metadata' => [
                'manual_resend' => true,
                'score_result_id' => $scoreResult->id,
                'requested_by_user_id' => $request->user()->id,
                'booking_availability' => $tripRecommendations
                    ->map(fn (array $trip): array => [
                        'boat_name' => $trip['boat_name'],
                        'landing_name' => $trip['landing_name'],
                        'trip_type' => $trip['trip_type'],
                        'trip_date' => $trip['trip_date'],
                        'booking_availability' => $trip['booking_availability'] ?? null,
                    ])
                    ->values()
                    ->all(),
            ],
        ]);

        try {
            Notification::sendNow($user, new HotBiteAlertNotification($event, $tripOptions, $tripRecommendations));

            $event->email_sent_at = now();
            $event->status = $this->eventStatus($event);
            $event->error_message = null;
            $event->save();

            $delivery->update([
                'status' => NotificationDeliveryStatus::Sent,
                'sent_at' => now(),
            ]);
        } catch (Throwable $throwable) {
            report($throwable);

            $delivery->update([
                'status' => NotificationDeliveryStatus::Failed,
                'failed_at' => now(),
                'error_message' => $this->maskedError($throwable),
            ]);

            $event->update([
                'status' => $event->email_sent_at !== null || $event->discord_sent_at !== null
                    ? $this->eventStatus($event)
                    : AlertEventStatus::Failed,
                'error_message' => $this->maskedError($throwable),
            ]);

            return back()->with('error', 'The hot bite email could not be resent. Check the notification logs for details.');
        }

        return back()->with('status', "Hot bite email resent to {$user->email}.");
    }

    private function eventStatus(AlertEvent $event): AlertEventStatus
    {
        $rule = $event->alertRule;
        $emailComplete = ! $rule->email_enabled || $event->email_sent_at !== null;
        $discordComplete = ! $rule->discord_enabled || $event->discord_sent_at !== null;

        return $emailComplete && $discordComplete ? AlertEventStatus::Sent : AlertEventStatus::Partial;
    }

    private function maskedError(Throwable $throwable): string
    {
        return str($throwable->getMessage())
            ->replaceMatches('/https:\/\/discord\.com\/api\/webhooks\/[^\s]+/', 'https://discord.com/api/webhooks/[masked]')
            ->limit(1000)
            ->toString();
    }
}
