<?php

namespace App\Jobs;

use App\Enums\AlertEventStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Models\AlertEvent;
use App\Models\NotificationDelivery;
use App\Models\NotificationDestination;
use App\Notifications\HotBiteAlertNotification;
use App\Services\Notifications\DiscordWebhookSender;
use App\Services\Notifications\TripDecisionBuilder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Throwable;

class SendHotBiteAlertJob implements ShouldBeUnique, ShouldQueue
{
    use FoundationQueueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $alertEventId, public NotificationChannel $channel)
    {
        $this->onQueue('notifications');
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(DiscordWebhookSender $discord, TripDecisionBuilder $tripDecisionBuilder): void
    {
        $event = AlertEvent::query()->with(['user.notificationDestinations', 'alertRule.species', 'scoreResult'])->findOrFail($this->alertEventId);

        if ($this->alreadySent($event)) {
            return;
        }

        $destination = $this->destination($event);

        if ($this->channel === NotificationChannel::Discord && $destination === null) {
            NotificationDelivery::query()->create([
                'alert_event_id' => $event->id,
                'user_id' => $event->user_id,
                'channel' => $this->channel,
                'notification_type' => HotBiteAlertNotification::class,
                'status' => NotificationDeliveryStatus::Skipped,
                'attempted_at' => now(),
                'failed_at' => now(),
                'error_message' => 'No enabled Discord destination.',
            ]);

            return;
        }

        $tripOptions = $tripDecisionBuilder->rankedTrips(
            $event->alertRule,
            $event->scoreResult->score_date->toImmutable(),
            $event->scoreResult->score_date->toImmutable(),
        );
        $tripRecommendations = $tripDecisionBuilder->recommendedBoats($tripOptions);

        $delivery = NotificationDelivery::query()->create([
            'alert_event_id' => $event->id,
            'user_id' => $event->user_id,
            'notification_destination_id' => $destination?->id,
            'channel' => $this->channel,
            'notification_type' => HotBiteAlertNotification::class,
            'status' => NotificationDeliveryStatus::Pending,
            'attempted_at' => now(),
            'metadata' => [
                'booking_availability' => $this->bookingAvailabilityMetadata($tripRecommendations),
            ],
        ]);

        try {
            if ($this->channel === NotificationChannel::Email) {
                Notification::sendNow($event->user, new HotBiteAlertNotification($event, $tripOptions, $tripRecommendations));
                $event->update(['email_sent_at' => now()]);
            } else {
                $discord->send($destination->destination, [
                    'content' => "**Hot bite alert: {$event->alertRule->name}**\n{$event->alertRule->species->name} scored {$event->score} for {$event->scoreResult->score_date->toDateString()}.",
                ]);
                $event->update(['discord_sent_at' => now()]);
            }

            $delivery->update(['status' => NotificationDeliveryStatus::Sent, 'sent_at' => now()]);
            $event->update(['status' => $this->eventStatus($event->fresh())]);
        } catch (Throwable $throwable) {
            $delivery->update([
                'status' => NotificationDeliveryStatus::Failed,
                'failed_at' => now(),
                'error_message' => $this->maskedError($throwable),
            ]);
            $event->update(['status' => AlertEventStatus::Partial, 'error_message' => $this->maskedError($throwable)]);

            throw $throwable;
        }
    }

    public function uniqueId(): string
    {
        return "{$this->alertEventId}:{$this->channel->value}";
    }

    private function alreadySent(AlertEvent $event): bool
    {
        return match ($this->channel) {
            NotificationChannel::Email => $event->email_sent_at !== null,
            NotificationChannel::Discord => $event->discord_sent_at !== null,
        };
    }

    private function destination(AlertEvent $event): ?NotificationDestination
    {
        return $event->user->notificationDestinations
            ->first(fn (NotificationDestination $destination): bool => $destination->channel === $this->channel && $destination->is_enabled);
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
        return str($throwable->getMessage())->replaceMatches('/https:\/\/discord\.com\/api\/webhooks\/[^\s]+/', 'https://discord.com/api/webhooks/[masked]')->limit(1000)->toString();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $tripRecommendations
     * @return array<int, array<string, mixed>>
     */
    private function bookingAvailabilityMetadata(Collection $tripRecommendations): array
    {
        return $tripRecommendations
            ->map(fn (array $trip): array => [
                'boat_name' => $trip['boat_name'],
                'landing_name' => $trip['landing_name'],
                'trip_type' => $trip['trip_type'],
                'trip_date' => $trip['trip_date'],
                'booking_availability' => $trip['booking_availability'] ?? null,
            ])
            ->values()
            ->all();
    }
}
