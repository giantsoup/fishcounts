<?php

namespace Tests\Feature;

use App\Enums\ParserEngine;
use App\Enums\ScoreLevel;
use App\Enums\ScrapeRunStatus;
use App\Enums\ScrapeRunType;
use App\Enums\SourceType;
use App\Jobs\ComputeScoreForRuleJob;
use App\Jobs\ScrapeSourceForDateJob;
use App\Jobs\SendHotBiteAlertJob;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\ParserExecution;
use App\Models\RawScrapePayload;
use App\Models\ScoreResult;
use App\Models\ScoreRun;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Models\Species;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DailyPipelineDateTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_daily_scrape_defaults_to_the_previous_complete_day(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-12 01:00:00', 'America/Los_Angeles'));
        Queue::fake();
        $source = ScrapeSource::query()->create([
            'name' => 'Seaforth',
            'slug' => 'seaforth',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://example.test',
            'is_enabled' => true,
        ]);

        $this->artisan('fish:scrape-daily')->assertSuccessful();

        Queue::assertPushed(
            ScrapeSourceForDateJob::class,
            fn (ScrapeSourceForDateJob $job): bool => $job->sourceId === $source->id && $job->date === '2026-07-11',
        );
    }

    public function test_latest_score_defaults_to_the_previous_complete_day(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-12 01:15:00', 'America/Los_Angeles'));
        Queue::fake();
        $this->completedDailyParse('2026-07-11');
        [$rule] = $this->rule();

        $this->artisan('fish:score-latest')->assertSuccessful();

        $scoreRun = ScoreRun::query()->whereDate('run_date', '2026-07-11')->firstOrFail();
        Queue::assertPushed(
            ComputeScoreForRuleJob::class,
            fn (ComputeScoreForRuleJob $job): bool => $job->alertRuleId === $rule->id
                && $job->date === '2026-07-11',
        );
    }

    public function test_latest_score_stops_when_an_enabled_source_has_not_finished_parsing(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-12 02:00:00', 'America/Los_Angeles'));
        Queue::fake();
        ScrapeSource::query()->create([
            'name' => 'Pending source',
            'slug' => 'pending-source',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://example.test',
            'is_enabled' => true,
        ]);

        $this->artisan('fish:score-latest')
            ->expectsOutputToContain('daily parsing is incomplete for: Pending source')
            ->assertFailed();

        $this->assertDatabaseMissing('score_runs', ['run_date' => '2026-07-11']);
        Queue::assertNotPushed(ComputeScoreForRuleJob::class);
    }

    public function test_hot_alert_dispatch_defaults_to_the_previous_complete_day(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-12 01:25:00', 'America/Los_Angeles'));
        Queue::fake();
        [$rule, $scoreRun] = $this->rule();
        ScoreResult::query()->create([
            'score_run_id' => $scoreRun->id,
            'alert_rule_id' => $rule->id,
            'score_date' => '2026-07-10',
            'score' => 50,
            'level' => ScoreLevel::Cold,
            'total_count' => 10,
            'boat_count' => 1,
            'landing_count' => 1,
            'explanation' => [],
        ]);
        $thresholdScore = ScoreResult::query()->create([
            'score_run_id' => $scoreRun->id,
            'alert_rule_id' => $rule->id,
            'score_date' => '2026-07-11',
            'score' => 82,
            'level' => ScoreLevel::Hot,
            'total_count' => 80,
            'boat_count' => 3,
            'landing_count' => 2,
            'explanation' => [],
        ]);

        $this->artisan('fish:send-hot-alerts')->assertSuccessful();

        Queue::assertPushed(SendHotBiteAlertJob::class);
        $this->assertTrue(AlertEvent::query()
            ->where('score_result_id', $thresholdScore->id)
            ->whereDate('event_date', '2026-07-11')
            ->exists());
    }

    public function test_hot_alert_dispatch_stops_until_every_enabled_rule_has_a_score(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-12 02:15:00', 'America/Los_Angeles'));
        Queue::fake();
        [, $scoreRun] = $this->rule();

        $this->artisan('fish:send-hot-alerts')
            ->expectsOutputToContain('scoring for 2026-07-11 is incomplete')
            ->assertFailed();

        $this->assertSame('pending', $scoreRun->refresh()->status->value);
        Queue::assertNotPushed(SendHotBiteAlertJob::class);
    }

    /** @return array{AlertRule, ScoreRun} */
    private function rule(): array
    {
        $user = User::factory()->create();
        $species = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        $rule = AlertRule::query()->create([
            'user_id' => $user->id,
            'species_id' => $species->id,
            'name' => 'Yellowtail',
            'minimum_score' => 70,
        ]);
        $scoreRun = ScoreRun::query()->create(['run_date' => '2026-07-11']);

        return [$rule, $scoreRun];
    }

    private function completedDailyParse(string $date): void
    {
        $source = ScrapeSource::query()->create([
            'name' => 'Completed source',
            'slug' => 'completed-source',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://example.test',
            'is_enabled' => true,
        ]);
        $run = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => ScrapeRunType::Daily,
            'target_date' => $date,
            'status' => ScrapeRunStatus::Succeeded,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        $payload = RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id,
            'scrape_source_id' => $source->id,
            'target_date' => $date,
            'url' => 'https://example.test/fish-counts',
            'payload' => '<p>No reports</p>',
            'payload_hash' => hash('sha256', 'completed-daily-parse'),
            'fetched_at' => now(),
        ]);
        $execution = ParserExecution::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'scrape_source_id' => $source->id,
            'idempotency_key' => hash('sha256', 'completed-daily-parse-execution'),
            'requested_engine' => ParserEngine::Deterministic,
            'selected_engine' => ParserEngine::Deterministic,
            'status' => 'completed',
            'payload_hash' => $payload->payload_hash,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $payload->update(['authoritative_parser_execution_id' => $execution->id]);
        $run->update(['metadata' => ['raw_scrape_payload_id' => $payload->id]]);
    }
}
