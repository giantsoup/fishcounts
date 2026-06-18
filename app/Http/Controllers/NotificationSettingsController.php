<?php

namespace App\Http\Controllers;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Http\Requests\UpdateNotificationSettingsRequest;
use App\Models\NotificationDelivery;
use App\Models\NotificationDestination;
use App\Notifications\TestNotification;
use App\Services\Notifications\DiscordWebhookSender;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Throwable;

class NotificationSettingsController extends Controller
{
    public function edit(): View
    {
        $this->authorize('viewAny', NotificationDestination::class);

        return view('notification-settings.edit', [
            'destinations' => auth()->user()->notificationDestinations()->get(),
        ]);
    }

    public function update(UpdateNotificationSettingsRequest $request): RedirectResponse
    {
        $user = $request->user();

        if ($request->boolean('email_enabled')) {
            $user->notificationDestinations()->updateOrCreate(
                ['channel' => NotificationChannel::Email, 'name' => 'Primary email'],
                ['destination' => $user->email, 'is_enabled' => true, 'verified_at' => $user->email_verified_at],
            );
        }

        if ($request->filled('discord_webhook_url')) {
            $user->notificationDestinations()->updateOrCreate(
                ['channel' => NotificationChannel::Discord, 'name' => 'Discord webhook'],
                ['destination' => $request->string('discord_webhook_url')->toString(), 'is_enabled' => $request->boolean('discord_enabled')],
            );
        }

        return redirect()->route('notification-settings.edit')->with('status', 'Notification settings updated.');
    }

    public function test(NotificationDestination $notificationDestination, DiscordWebhookSender $discord): RedirectResponse
    {
        $this->authorize('update', $notificationDestination);

        $delivery = NotificationDelivery::query()->create([
            'user_id' => $notificationDestination->user_id,
            'notification_destination_id' => $notificationDestination->id,
            'channel' => $notificationDestination->channel,
            'notification_type' => TestNotification::class,
            'status' => NotificationDeliveryStatus::Pending,
            'attempted_at' => now(),
            'metadata' => ['test' => true],
        ]);

        if (! $notificationDestination->is_enabled) {
            $delivery->update([
                'status' => NotificationDeliveryStatus::Skipped,
                'failed_at' => now(),
                'error_message' => 'Destination is disabled.',
            ]);

            return redirect()->route('notification-settings.edit')->with('status', 'Destination is disabled.');
        }

        try {
            if ($notificationDestination->channel === NotificationChannel::Email) {
                $notificationDestination->user->notify(new TestNotification($notificationDestination->name));
            } elseif ($notificationDestination->channel === NotificationChannel::Discord) {
                $discord->send($notificationDestination->destination, [
                    'content' => "Fish Counts test delivery for {$notificationDestination->name}.",
                ]);
            }

            $delivery->update(['status' => NotificationDeliveryStatus::Sent, 'sent_at' => now()]);
        } catch (Throwable $throwable) {
            $delivery->update([
                'status' => NotificationDeliveryStatus::Failed,
                'failed_at' => now(),
                'error_message' => str($throwable->getMessage())->replaceMatches('/https:\/\/discord\.com\/api\/webhooks\/[^\s]+/', 'https://discord.com/api/webhooks/[masked]')->limit(1000)->toString(),
            ]);

            return redirect()->route('notification-settings.edit')->with('status', 'Test notification failed. Check notification logs.');
        }

        return redirect()->route('notification-settings.edit')->with('status', 'Test notification sent.');
    }
}
