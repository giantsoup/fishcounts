<?php

namespace App\Console\Commands;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationDelivery;
use App\Models\NotificationDestination;
use App\Models\User;
use App\Notifications\TestNotification;
use App\Services\Notifications\DiscordWebhookSender;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('fish:test-notifications {userId : User ID to test}')]
#[Description('Send test notifications to all enabled destinations for a user.')]
class TestNotificationsCommand extends Command
{
    public function handle(DiscordWebhookSender $discord): int
    {
        $user = User::query()->with('notificationDestinations')->findOrFail((int) $this->argument('userId'));
        $sent = 0;
        $failed = 0;

        $user->notificationDestinations
            ->where('is_enabled', true)
            ->each(function (NotificationDestination $destination) use ($user, $discord, &$sent, &$failed): void {
                $delivery = NotificationDelivery::query()->create([
                    'user_id' => $user->id,
                    'notification_destination_id' => $destination->id,
                    'channel' => $destination->channel,
                    'notification_type' => TestNotification::class,
                    'status' => NotificationDeliveryStatus::Pending,
                    'attempted_at' => now(),
                    'metadata' => ['command' => 'fish:test-notifications'],
                ]);

                try {
                    if ($destination->channel === NotificationChannel::Email) {
                        $user->notify(new TestNotification($destination->name));
                    } elseif ($destination->channel === NotificationChannel::Discord) {
                        $discord->send($destination->destination, [
                            'content' => "Fish Counts test delivery for {$destination->name}.",
                        ]);
                    }

                    $delivery->update(['status' => NotificationDeliveryStatus::Sent, 'sent_at' => now()]);
                    $sent++;
                } catch (Throwable $throwable) {
                    $delivery->update([
                        'status' => NotificationDeliveryStatus::Failed,
                        'failed_at' => now(),
                        'error_message' => str($throwable->getMessage())->replaceMatches('/https:\/\/discord\.com\/api\/webhooks\/[^\s]+/', 'https://discord.com/api/webhooks/[masked]')->limit(1000)->toString(),
                    ]);
                    $failed++;
                }
            });

        $this->info("Sent {$sent} test notification(s); {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
