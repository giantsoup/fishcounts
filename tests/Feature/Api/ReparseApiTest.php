<?php

namespace Tests\Feature\Api;

use App\Enums\ParserReparseRunStatus;
use App\Jobs\DispatchParserReparseRunJob;
use App\Models\ParserError;
use App\Models\ParserReparseRun;
use App\Models\RawScrapePayload;
use App\Models\ScrapeSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReparseApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_preview_returns_the_scope_and_a_64_character_fingerprint(): void
    {
        $source = ScrapeSource::factory()->create();
        $this->openErrorOn($source, '2026-07-10');
        $this->openErrorOn($source, '2026-07-20', structural: true);
        $this->openErrorOn(ScrapeSource::factory()->create(), '2026-07-10');

        $response = $this->withToken($this->token(['reparse']))
            ->getJson('/api/reparse-runs/preview?'.http_build_query(['source' => $source->id, 'from' => '2026-07-01', 'to' => '2026-07-31']))
            ->assertOk()
            ->assertJsonPath('data.open_errors', 2)
            ->assertJsonPath('data.alias_errors', 1)
            ->assertJsonPath('data.payloads', 2)
            ->assertJsonPath('data.dates', 2)
            ->assertJsonPath('data.source', $source->id)
            ->assertJsonPath('data.from', '2026-07-01')
            ->assertJsonPath('data.to', '2026-07-31');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $response->json('data.fingerprint'));
        $this->assertArrayNotHasKey('items', $response->json('data'));
    }

    public function test_start_with_the_previewed_fingerprint_creates_and_queues_a_run(): void
    {
        $source = ScrapeSource::factory()->create();
        $this->openErrorOn($source, '2026-07-10');
        $this->openErrorOn(ScrapeSource::factory()->create(), '2026-07-10');
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('agent', ['reparse'])->plainTextToken;
        $scope = ['source' => $source->id, 'from' => '2026-07-01', 'to' => '2026-07-31'];
        $fingerprint = $this->withToken($token)->getJson('/api/reparse-runs/preview?'.http_build_query($scope))->json('data.fingerprint');

        $response = $this->withToken($token)
            ->postJson('/api/reparse-runs', [...$scope, 'fingerprint' => $fingerprint])
            ->assertCreated()
            ->assertJsonPath('meta.created', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.finished', false)
            ->assertJsonPath('data.initial.open_errors', 1)
            ->assertJsonPath('data.progress.total_items', 1)
            ->assertJsonPath('data.requested_by_user_id', $admin->id);

        $run = ParserReparseRun::query()->sole();
        $this->assertSame($run->id, $response->json('data.id'));
        $this->assertSame($admin->id, $run->requested_by_user_id);
        Queue::assertPushed(DispatchParserReparseRunJob::class, fn (DispatchParserReparseRunJob $job): bool => $job->parserReparseRunId === $run->id);
    }

    public function test_start_with_a_stale_fingerprint_is_rejected_and_creates_nothing(): void
    {
        $this->openErrorOn(ScrapeSource::factory()->create(), '2026-07-10');
        $token = $this->token(['reparse']);
        $fingerprint = $this->withToken($token)->getJson('/api/reparse-runs/preview')->json('data.fingerprint');
        $this->openErrorOn(ScrapeSource::factory()->create(), '2026-07-11');

        $this->withToken($token)
            ->postJson('/api/reparse-runs', ['fingerprint' => $fingerprint])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fingerprint');

        $this->assertSame(0, ParserReparseRun::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_start_requires_a_64_character_fingerprint(): void
    {
        $token = $this->token(['reparse']);

        $this->withToken($token)->postJson('/api/reparse-runs')->assertUnprocessable()->assertJsonValidationErrors('fingerprint');
        $this->withToken($token)->postJson('/api/reparse-runs', ['fingerprint' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('fingerprint');
        $this->withToken($token)->postJson('/api/reparse-runs', ['fingerprint' => str_repeat('a', 64), 'source' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('source');
    }

    public function test_start_while_a_run_is_active_returns_that_run_without_queueing_another(): void
    {
        $active = ParserReparseRun::query()->create(['status' => ParserReparseRunStatus::Running]);
        $token = $this->token(['reparse']);
        $fingerprint = $this->withToken($token)->getJson('/api/reparse-runs/preview')->json('data.fingerprint');

        $this->withToken($token)
            ->postJson('/api/reparse-runs', ['fingerprint' => $fingerprint])
            ->assertOk()
            ->assertJsonPath('meta.created', false)
            ->assertJsonPath('data.id', $active->id)
            ->assertJsonPath('data.status', 'running');

        Queue::assertNothingPushed();
    }

    /**
     * @param  list<string>  $abilities
     */
    private function token(array $abilities): string
    {
        return User::factory()->admin()->create()->createToken('agent', $abilities)->plainTextToken;
    }

    private function openErrorOn(ScrapeSource $source, string $targetDate, bool $structural = false): ParserError
    {
        $factory = ParserError::factory()->for(RawScrapePayload::factory()->for($source)->state(['target_date' => $targetDate]));

        return ($structural ? $factory->structural() : $factory)->create();
    }
}
