<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\BackfillRun;
use App\Models\NotificationDestination;
use App\Models\RawScrapePayload;
use App\Models\ScrapeSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_alert_rule_policy_allows_owner_and_admin_but_blocks_other_users(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $inactive = User::factory()->create(['is_active' => false]);
        $rule = new AlertRule(['user_id' => $owner->id]);

        $this->assertTrue($owner->can('viewAny', AlertRule::class));
        $this->assertTrue($owner->can('create', AlertRule::class));
        $this->assertFalse($inactive->can('viewAny', AlertRule::class));
        $this->assertFalse($inactive->can('create', AlertRule::class));

        $this->assertTrue($owner->can('view', $rule));
        $this->assertTrue($owner->can('update', $rule));
        $this->assertTrue($owner->can('delete', $rule));

        $this->assertTrue($admin->can('view', $rule));
        $this->assertTrue($admin->can('update', $rule));
        $this->assertTrue($admin->can('delete', $rule));

        $this->assertFalse($other->can('view', $rule));
        $this->assertFalse($other->can('update', $rule));
        $this->assertFalse($other->can('delete', $rule));
        $this->assertFalse($admin->can('restore', $rule));
        $this->assertFalse($admin->can('forceDelete', $rule));
    }

    public function test_notification_destination_policy_allows_owner_and_admin_but_blocks_other_users(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $inactive = User::factory()->create(['is_active' => false]);
        $destination = new NotificationDestination(['user_id' => $owner->id]);

        $this->assertTrue($owner->can('viewAny', NotificationDestination::class));
        $this->assertTrue($owner->can('create', NotificationDestination::class));
        $this->assertFalse($inactive->can('viewAny', NotificationDestination::class));
        $this->assertFalse($inactive->can('create', NotificationDestination::class));

        $this->assertTrue($owner->can('view', $destination));
        $this->assertTrue($owner->can('update', $destination));
        $this->assertTrue($owner->can('delete', $destination));

        $this->assertTrue($admin->can('view', $destination));
        $this->assertTrue($admin->can('update', $destination));
        $this->assertTrue($admin->can('delete', $destination));

        $this->assertFalse($other->can('view', $destination));
        $this->assertFalse($other->can('update', $destination));
        $this->assertFalse($other->can('delete', $destination));
        $this->assertFalse($admin->can('restore', $destination));
        $this->assertFalse($admin->can('forceDelete', $destination));
    }

    public function test_backfill_source_and_raw_payload_policies_are_admin_only(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $backfill = new BackfillRun;
        $source = new ScrapeSource;
        $payload = new RawScrapePayload;

        foreach (['viewAny', 'create'] as $ability) {
            $this->assertTrue($admin->can($ability, BackfillRun::class));
            $this->assertTrue($admin->can($ability, ScrapeSource::class));
            $this->assertFalse($user->can($ability, BackfillRun::class));
            $this->assertFalse($user->can($ability, ScrapeSource::class));
        }

        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertTrue($admin->can($ability, $backfill));
            $this->assertTrue($admin->can($ability, $source));
            $this->assertFalse($user->can($ability, $backfill));
            $this->assertFalse($user->can($ability, $source));
        }

        $this->assertTrue($admin->can('viewAny', RawScrapePayload::class));
        $this->assertTrue($admin->can('view', $payload));
        $this->assertTrue($admin->can('reparse', $payload));
        $this->assertFalse($admin->can('create', RawScrapePayload::class));
        $this->assertFalse($admin->can('update', $payload));
        $this->assertFalse($admin->can('delete', $payload));

        $this->assertFalse($user->can('viewAny', RawScrapePayload::class));
        $this->assertFalse($user->can('view', $payload));
        $this->assertFalse($user->can('reparse', $payload));
    }

    public function test_user_policy_blocks_self_delete_and_non_admin_management(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->assertTrue($admin->can('viewAny', User::class));
        $this->assertTrue($admin->can('create', User::class));
        $this->assertTrue($admin->can('view', $user));
        $this->assertTrue($admin->can('update', $user));
        $this->assertTrue($admin->can('delete', $user));
        $this->assertFalse($admin->can('delete', $admin));

        $this->assertFalse($user->can('viewAny', User::class));
        $this->assertFalse($user->can('create', User::class));
        $this->assertTrue($user->can('view', $user));
        $this->assertTrue($user->can('update', $user));
        $this->assertFalse($user->can('view', $other));
        $this->assertFalse($user->can('update', $other));
        $this->assertFalse($user->can('delete', $other));
        $this->assertFalse($admin->can('restore', $user));
        $this->assertFalse($admin->can('forceDelete', $user));
    }
}
