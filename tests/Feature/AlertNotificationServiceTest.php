<?php

namespace Tests\Feature;

use App\Enums\ScoreLevel;
use App\Jobs\SendHotBiteAlertJob;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\ScoreResult;
use App\Models\ScoreRun;
use App\Models\Species;
use App\Models\User;
use App\Services\Notifications\AlertNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AlertNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_score_at_or_above_threshold_creates_an_alert(): void
    {
        Queue::fake();
        [$rule, $scoreRun] = $this->rule();
        $score = $this->score($rule, $scoreRun, '2026-07-11', 70);

        $dispatched = app(AlertNotificationService::class)->dispatchThresholdCrossings(
            CarbonImmutable::parse('2026-07-11'),
        );

        $this->assertSame(1, $dispatched);
        $event = AlertEvent::query()->where('score_result_id', $score->id)->firstOrFail();
        $this->assertTrue($event->event_date->isSameDay($score->score_date));
        Queue::assertPushed(SendHotBiteAlertJob::class);
    }

    public function test_below_to_above_threshold_transition_creates_an_alert_for_the_score_date(): void
    {
        Queue::fake();
        [$rule, $scoreRun] = $this->rule();
        $this->score($rule, $scoreRun, '2026-07-10', 69);
        $score = $this->score($rule, $scoreRun, '2026-07-11', 82);

        $dispatched = app(AlertNotificationService::class)->dispatchThresholdCrossings(
            CarbonImmutable::parse('2026-07-11'),
        );

        $this->assertSame(1, $dispatched);
        $event = AlertEvent::query()->where('score_result_id', $score->id)->firstOrFail();
        $this->assertTrue($event->event_date->isSameDay('2026-07-11'));
    }

    public function test_remaining_above_threshold_does_not_create_a_repeat_alert(): void
    {
        Queue::fake();
        [$rule, $scoreRun] = $this->rule();
        $this->score($rule, $scoreRun, '2026-07-10', 75);
        $this->score($rule, $scoreRun, '2026-07-11', 82);

        $dispatched = app(AlertNotificationService::class)->dispatchThresholdCrossings(
            CarbonImmutable::parse('2026-07-11'),
        );

        $this->assertSame(0, $dispatched);
        $this->assertSame(0, AlertEvent::query()->count());
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
            'email_enabled' => true,
            'discord_enabled' => false,
        ]);
        $scoreRun = ScoreRun::query()->create(['run_date' => '2026-07-11']);

        return [$rule, $scoreRun];
    }

    private function score(AlertRule $rule, ScoreRun $scoreRun, string $date, int $score): ScoreResult
    {
        return ScoreResult::query()->create([
            'score_run_id' => $scoreRun->id,
            'alert_rule_id' => $rule->id,
            'score_date' => $date,
            'score' => $score,
            'level' => ScoreLevel::fromScore($score),
            'total_count' => $score,
            'boat_count' => 1,
            'landing_count' => 1,
            'explanation' => [],
        ]);
    }
}
