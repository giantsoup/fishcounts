<?php

namespace App\Jobs;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\WeeklyFishingDigestNotification;
use App\Services\Notifications\DiscordWebhookSender;
use App\Services\Notifications\WeeklyDigestBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Throwable;

class SendWeeklyDigestJob implements ShouldBeUnique, ShouldQueue
{
    use FoundationQueueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $userId, public string $date, public NotificationChannel $channel)
    {
        $this->onQueue('notifications');
    }

    public function handle(DiscordWebhookSender $discord, WeeklyDigestBuilder $digestBuilder): void
    {
        $user = User::query()->with('notificationDestinations')->findOrFail($this->userId);

        if ($this->alreadySent()) {
            return;
        }

        $destination = $user->notificationDestinations
            ->first(fn ($destination): bool => $destination->channel === $this->channel && $destination->is_enabled);

        if ($this->channel === NotificationChannel::Discord && $destination === null) {
            NotificationDelivery::query()->create([
                'user_id' => $user->id,
                'channel' => $this->channel,
                'notification_type' => WeeklyFishingDigestNotification::class,
                'status' => NotificationDeliveryStatus::Skipped,
                'attempted_at' => now(),
                'failed_at' => now(),
                'error_message' => 'No enabled destination.',
                'metadata' => ['week_ending' => $this->date],
            ]);

            return;
        }

        $weekEnding = CarbonImmutable::parse($this->date);
        $summaries = $digestBuilder->summaries($user, $weekEnding);

        $delivery = NotificationDelivery::query()->create([
            'user_id' => $user->id,
            'notification_destination_id' => $destination?->id,
            'channel' => $this->channel,
            'notification_type' => WeeklyFishingDigestNotification::class,
            'status' => NotificationDeliveryStatus::Pending,
            'attempted_at' => now(),
            'metadata' => [
                'week_ending' => $this->date,
                'booking_availability' => $this->bookingAvailabilityMetadata($summaries),
            ],
        ]);

        try {
            if ($this->channel === NotificationChannel::Email) {
                Notification::sendNow($user, new WeeklyFishingDigestNotification($user, $weekEnding, $summaries));
            } else {
                $discord->send($destination->destination, [
                    'content' => $digestBuilder->discordContent($user, $weekEnding, $summaries),
                ]);
            }

            $delivery->update(['status' => NotificationDeliveryStatus::Sent, 'sent_at' => now()]);
        } catch (Throwable $throwable) {
            $delivery->update([
                'status' => NotificationDeliveryStatus::Failed,
                'failed_at' => now(),
                'error_message' => str($throwable->getMessage())->replaceMatches('/https:\/\/discord\.com\/api\/webhooks\/[^\s]+/', 'https://discord.com/api/webhooks/[masked]')->limit(1000)->toString(),
            ]);

            throw $throwable;
        }
    }

    public function uniqueId(): string
    {
        return "{$this->userId}:{$this->date}:{$this->channel->value}";
    }

    private function alreadySent(): bool
    {
        return NotificationDelivery::query()
            ->where('user_id', $this->userId)
            ->where('channel', $this->channel)
            ->where('notification_type', WeeklyFishingDigestNotification::class)
            ->where('status', NotificationDeliveryStatus::Sent)
            ->where('metadata->week_ending', $this->date)
            ->exists();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $summaries
     * @return array<int, array<string, mixed>>
     */
    private function bookingAvailabilityMetadata(Collection $summaries): array
    {
        return $summaries
            ->filter(fn (array $summary): bool => (bool) ($summary['has_scores'] ?? false))
            ->map(fn (array $summary): array => [
                'rule_name' => $summary['rule_name'],
                'trip_recommendations' => $summary['trip_recommendations']
                    ->map(fn (array $trip): array => [
                        'boat_name' => $trip['boat_name'],
                        'landing_name' => $trip['landing_name'],
                        'trip_type' => $trip['trip_type'],
                        'trip_date' => $trip['trip_date'],
                        'booking_availability' => $trip['booking_availability'] ?? null,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
