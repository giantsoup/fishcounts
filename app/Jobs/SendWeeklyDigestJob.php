<?php

namespace App\Jobs;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\WeeklyFishingDigestNotification;
use App\Services\Notifications\DiscordWebhookSender;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Throwable;

class SendWeeklyDigestJob implements ShouldQueue
{
    use FoundationQueueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $userId, public string $date, public NotificationChannel $channel)
    {
        $this->onQueue('notifications');
    }

    public function handle(DiscordWebhookSender $discord): void
    {
        $user = User::query()->with('notificationDestinations')->findOrFail($this->userId);
        $destination = $user->notificationDestinations
            ->first(fn ($destination): bool => $destination->channel === $this->channel && $destination->is_enabled);

        $delivery = NotificationDelivery::query()->create([
            'user_id' => $user->id,
            'notification_destination_id' => $destination?->id,
            'channel' => $this->channel,
            'notification_type' => WeeklyFishingDigestNotification::class,
            'status' => NotificationDeliveryStatus::Pending,
            'attempted_at' => now(),
            'metadata' => ['week_ending' => $this->date],
        ]);

        try {
            if ($this->channel === NotificationChannel::Email) {
                $user->notify(new WeeklyFishingDigestNotification($user, CarbonImmutable::parse($this->date)));
            } elseif ($destination !== null) {
                $discord->send($destination->destination, ['content' => "Weekly fishing digest for week ending {$this->date} is ready."]);
            } else {
                $delivery->update(['status' => NotificationDeliveryStatus::Skipped, 'failed_at' => now(), 'error_message' => 'No enabled destination.']);

                return;
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
}
