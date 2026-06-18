<?php

namespace Tests\Feature;

use App\Enums\AlertEventStatus;
use App\Enums\AlertEventType;
use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\ScoreLevel;
use App\Jobs\SendHotBiteAlertJob;
use App\Jobs\SendWeeklyDigestJob;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\NotificationDestination;
use App\Models\ScoreResult;
use App\Models\ScoreRun;
use App\Models\Species;
use App\Models\User;
use App\Notifications\HotBiteAlertNotification;
use App\Notifications\WeeklyFishingDigestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationFailureIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_discord_hot_alert_failure_does_not_undo_successful_email_delivery(): void
    {
        Notification::fake();
        Http::fake(['https://discord.test/webhook' => Http::response('bad webhook', 500)]);

        $event = $this->alertEvent();
        $this->destination($event->user, NotificationChannel::Email, $event->user->email);
        $this->destination($event->user, NotificationChannel::Discord, 'https://discord.test/webhook');

        SendHotBiteAlertJob::dispatchSync($event->id, NotificationChannel::Email);

        try {
            SendHotBiteAlertJob::dispatchSync($event->id, NotificationChannel::Discord);
            $this->fail('Discord delivery should have failed.');
        } catch (RequestException) {
            // Expected; the assertion below proves the email delivery remains recorded.
        }

        Notification::assertSentTo($event->user, HotBiteAlertNotification::class);
        $this->assertDatabaseHas('notification_deliveries', [
            'alert_event_id' => $event->id,
            'channel' => NotificationChannel::Email->value,
            'status' => NotificationDeliveryStatus::Sent->value,
        ]);
        $this->assertDatabaseHas('notification_deliveries', [
            'alert_event_id' => $event->id,
            'channel' => NotificationChannel::Discord->value,
            'status' => NotificationDeliveryStatus::Failed->value,
        ]);
        $this->assertNotNull($event->refresh()->email_sent_at);
        $this->assertNull($event->discord_sent_at);
        $this->assertSame(AlertEventStatus::Partial, $event->status);
    }

    public function test_discord_weekly_digest_failure_does_not_undo_successful_email_delivery(): void
    {
        Notification::fake();
        Http::fake(['https://discord.test/webhook' => Http::response('bad webhook', 500)]);

        $event = $this->alertEvent();
        $this->destination($event->user, NotificationChannel::Email, $event->user->email);
        $this->destination($event->user, NotificationChannel::Discord, 'https://discord.test/webhook');

        SendWeeklyDigestJob::dispatchSync($event->user_id, '2026-06-17', NotificationChannel::Email);

        try {
            SendWeeklyDigestJob::dispatchSync($event->user_id, '2026-06-17', NotificationChannel::Discord);
            $this->fail('Discord delivery should have failed.');
        } catch (RequestException) {
            // Expected; the assertion below proves the email delivery remains recorded.
        }

        Notification::assertSentTo($event->user, WeeklyFishingDigestNotification::class);
        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $event->user_id,
            'channel' => NotificationChannel::Email->value,
            'notification_type' => WeeklyFishingDigestNotification::class,
            'status' => NotificationDeliveryStatus::Sent->value,
        ]);
        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $event->user_id,
            'channel' => NotificationChannel::Discord->value,
            'notification_type' => WeeklyFishingDigestNotification::class,
            'status' => NotificationDeliveryStatus::Failed->value,
        ]);
    }

    private function alertEvent(): AlertEvent
    {
        $user = User::factory()->create();
        $species = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        $rule = AlertRule::query()->create([
            'user_id' => $user->id,
            'name' => 'Local Yellowtail',
            'species_id' => $species->id,
            'email_enabled' => true,
            'discord_enabled' => true,
            'include_in_weekly_digest' => true,
        ]);
        $scoreRun = ScoreRun::query()->create(['run_date' => '2026-06-17']);
        $scoreResult = ScoreResult::query()->create([
            'score_run_id' => $scoreRun->id,
            'alert_rule_id' => $rule->id,
            'score_date' => '2026-06-17',
            'score' => 82,
            'level' => ScoreLevel::Hot,
            'total_count' => 84,
            'boat_count' => 1,
            'landing_count' => 1,
            'explanation' => [],
        ]);

        return AlertEvent::query()->create([
            'user_id' => $user->id,
            'alert_rule_id' => $rule->id,
            'score_result_id' => $scoreResult->id,
            'event_type' => AlertEventType::ThresholdCrossed,
            'event_date' => '2026-06-17',
            'level' => ScoreLevel::Hot,
            'score' => 82,
            'status' => AlertEventStatus::Pending,
        ]);
    }

    private function destination(User $user, NotificationChannel $channel, string $destination): NotificationDestination
    {
        return NotificationDestination::query()->create([
            'user_id' => $user->id,
            'channel' => $channel,
            'name' => $channel->value,
            'destination' => $destination,
            'is_enabled' => true,
            'verified_at' => now(),
        ]);
    }
}
