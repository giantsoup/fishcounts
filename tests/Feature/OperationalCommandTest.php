<?php

namespace Tests\Feature;

use App\Enums\NotificationChannel;
use App\Enums\Role;
use App\Enums\ScrapeRunType;
use App\Enums\SourceType;
use App\Jobs\ComputeScoreForRuleJob;
use App\Jobs\ParseRawPayloadJob;
use App\Models\AlertRule;
use App\Models\NotificationDestination;
use App\Models\RawScrapePayload;
use App\Models\Region;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Models\Species;
use App\Models\User;
use App\Notifications\TestNotification;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OperationalCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_admin_command_prompts_for_email_and_password(): void
    {
        $this->artisan('fish:create-admin')
            ->expectsQuestion('Admin email', 'local-dev@example.test')
            ->expectsQuestion('Admin password', 'local-dev-password')
            ->assertSuccessful();

        $user = User::query()->where('email', 'local-dev@example.test')->firstOrFail();

        $this->assertSame('Fish Counts Admin', $user->name);
        $this->assertTrue($user->isAdmin());
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('local-dev-password', $user->password));
    }

    public function test_create_admin_command_refuses_to_create_another_initial_admin(): void
    {
        User::factory()->admin()->create();

        $this->artisan('fish:create-admin', [
            'email' => 'second-admin@example.test',
            '--password' => 'second-admin-password',
        ])->assertFailed();

        $this->assertFalse(
            User::query()->where('email', 'second-admin@example.test')->exists()
        );
    }

    public function test_admin_user_seeder_preserves_existing_admin_password(): void
    {
        config([
            'fish.admin.email' => 'admin@example.test',
            'fish.admin.name' => 'Fish Counts Admin',
            'fish.admin.password' => 'new-seeded-password',
        ]);

        $user = User::factory()->create([
            'email' => 'admin@example.test',
            'password' => Hash::make('existing-password'),
            'role' => Role::User,
            'email_verified_at' => null,
        ]);

        $this->seed(AdminUserSeeder::class);

        $user->refresh();

        $this->assertSame(Role::Admin, $user->role);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('existing-password', $user->password));
        $this->assertFalse(Hash::check('new-seeded-password', $user->password));
    }

    public function test_parse_payload_command_queues_parser_job(): void
    {
        Queue::fake();
        $payload = $this->payload();

        $this->artisan('fish:parse-payload', ['payloadId' => $payload->id])->assertSuccessful();

        Queue::assertPushed(ParseRawPayloadJob::class, fn (ParseRawPayloadJob $job): bool => $job->rawScrapePayloadId === $payload->id);
    }

    public function test_reparse_date_command_queues_payloads_for_date(): void
    {
        Queue::fake();
        $payload = $this->payload();

        $this->artisan('fish:reparse-date', ['date' => '2026-01-05'])->assertSuccessful();

        Queue::assertPushed(ParseRawPayloadJob::class, fn (ParseRawPayloadJob $job): bool => $job->rawScrapePayloadId === $payload->id);
    }

    public function test_score_backfill_command_queues_score_jobs_for_range(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $species = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        $region = Region::query()->create(['name' => 'San Diego', 'slug' => 'san-diego']);
        $rule = AlertRule::query()->create([
            'user_id' => $user->id,
            'name' => 'Local Yellowtail',
            'species_id' => $species->id,
        ]);
        $rule->regions()->sync([$region->id]);

        $this->artisan('fish:score-backfill', ['--from' => '2026-01-05', '--to' => '2026-01-06'])->assertSuccessful();

        Queue::assertPushed(ComputeScoreForRuleJob::class, 2);
    }

    public function test_test_notifications_command_sends_enabled_email_destination(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        NotificationDestination::query()->create([
            'user_id' => $user->id,
            'channel' => NotificationChannel::Email,
            'name' => 'Primary email',
            'destination' => $user->email,
            'is_enabled' => true,
        ]);

        $this->artisan('fish:test-notifications', ['userId' => $user->id])->assertSuccessful();

        Notification::assertSentTo($user, TestNotification::class);
        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $user->id,
            'notification_type' => TestNotification::class,
            'status' => 'sent',
        ]);
    }

    public function test_production_check_fails_for_unsafe_defaults(): void
    {
        config([
            'app.debug' => true,
            'database.default' => 'sqlite',
            'queue.default' => 'database',
            'cache.default' => 'database',
            'session.secure' => false,
            'mail.default' => 'log',
            'fish.admin.password' => 'password',
        ]);

        $this->artisan('fish:production-check', ['--skip-database' => true])->assertFailed();
    }

    public function test_production_check_passes_for_production_ready_configuration(): void
    {
        $databaseConnection = config('database.default');

        try {
            config([
                'app.env' => 'production',
                'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
                'app.debug' => false,
                'database.default' => 'mariadb',
                'queue.default' => 'database',
                'cache.default' => 'database',
                'session.secure' => true,
                'session.http_only' => true,
                'mail.default' => 'smtp',
                'mail.from.address' => 'alerts@example.test',
                'fish.admin.password' => 'not-the-default-password',
            ]);

            $this->artisan('fish:production-check', ['--skip-database' => true])->assertSuccessful();
        } finally {
            config(['database.default' => $databaseConnection]);
        }
    }

    private function payload(): RawScrapePayload
    {
        $source = ScrapeSource::query()->create([
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
        ]);
        $run = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => ScrapeRunType::Manual,
            'target_date' => '2026-01-05',
        ]);

        return RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-01-05',
            'url' => 'https://www.fishermanslanding.com/fishcounts.php',
            'payload' => '<p>Dolphin Full Day 20 anglers 40 Yellowtail</p>',
            'payload_hash' => hash('sha256', 'payload'),
            'fetched_at' => now(),
        ]);
    }
}
