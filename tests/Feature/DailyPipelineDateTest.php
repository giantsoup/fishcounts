<?php

namespace Tests\Feature;

use App\Enums\ScoreLevel;
use App\Enums\SourceType;
use App\Jobs\ComputeScoreForRuleJob;
use App\Jobs\ScrapeSourceForDateJob;
use App\Jobs\SendHotBiteAlertJob;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\ScoreResult;
use App\Models\ScoreRun;
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
        [$rule] = $this->rule();

        $this->artisan('fish:score-latest')->assertSuccessful();

        $scoreRun = ScoreRun::query()->whereDate('run_date', '2026-07-11')->firstOrFail();
        Queue::assertPushed(
            ComputeScoreForRuleJob::class,
            fn (ComputeScoreForRuleJob $job): bool => $job->alertRuleId === $rule->id
                && $job->date === '2026-07-11',
        );
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
}
