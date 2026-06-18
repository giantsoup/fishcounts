<?php

namespace Tests\Feature;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationDestination;
use App\Models\User;
use App\Notifications\TestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_send_test_email_notification_to_owned_destination(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $destination = NotificationDestination::query()->create([
            'user_id' => $user->id,
            'channel' => NotificationChannel::Email,
            'name' => 'Primary email',
            'destination' => $user->email,
            'is_enabled' => true,
            'verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('notification-settings.test', $destination))
            ->assertRedirect(route('notification-settings.edit'));

        Notification::assertSentTo($user, TestNotification::class);
        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $user->id,
            'notification_destination_id' => $destination->id,
            'channel' => NotificationChannel::Email->value,
            'status' => NotificationDeliveryStatus::Sent->value,
        ]);
    }

    public function test_user_cannot_send_test_notification_to_another_users_destination(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $destination = NotificationDestination::query()->create([
            'user_id' => $otherUser->id,
            'channel' => NotificationChannel::Email,
            'name' => 'Primary email',
            'destination' => $otherUser->email,
            'is_enabled' => true,
            'verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('notification-settings.test', $destination))
            ->assertForbidden();
    }
}
