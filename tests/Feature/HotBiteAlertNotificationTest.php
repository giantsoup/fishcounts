<?php

namespace Tests\Feature;

use App\Enums\AlertEventStatus;
use App\Enums\AlertEventType;
use App\Enums\ScoreLevel;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\ScoreResult;
use App\Models\ScoreRun;
use App\Models\Species;
use App\Models\User;
use App\Notifications\HotBiteAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HotBiteAlertNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_hot_bite_alert_email_renders_structured_score_summary(): void
    {
        $user = User::factory()->create();
        $species = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        $rule = AlertRule::query()->create([
            'user_id' => $user->id,
            'name' => 'LelloTail',
            'species_id' => $species->id,
            'minimum_score' => 70,
        ]);
        $scoreRun = ScoreRun::query()->create(['run_date' => '2026-06-20']);
        $scoreResult = ScoreResult::query()->create([
            'score_run_id' => $scoreRun->id,
            'alert_rule_id' => $rule->id,
            'score_date' => '2026-06-20',
            'score' => 93,
            'level' => ScoreLevel::WideOpen,
            'total_count' => 153,
            'total_anglers' => 162,
            'count_per_angler' => 0.94,
            'boat_count' => 4,
            'landing_count' => 3,
            'explanation' => [],
        ]);
        $alertEvent = AlertEvent::query()->create([
            'user_id' => $user->id,
            'alert_rule_id' => $rule->id,
            'score_result_id' => $scoreResult->id,
            'event_type' => AlertEventType::ThresholdCrossed,
            'event_date' => '2026-06-20',
            'level' => ScoreLevel::WideOpen,
            'score' => 93,
            'status' => AlertEventStatus::Pending,
        ]);

        $html = (string) (new HotBiteAlertNotification($alertEvent))
            ->toMail($user)
            ->render();

        $this->assertStringContainsString('Hot bite threshold crossed', $html);
        $this->assertStringContainsString('LelloTail', $html);
        $this->assertStringContainsString('Yellowtail', $html);
        $this->assertStringContainsString('Wide Open', $html);
        $this->assertStringContainsString('Threshold', $html);
        $this->assertStringContainsString('Total fish', $html);
        $this->assertStringContainsString('Boats reporting', $html);
        $this->assertStringContainsString('Fish / angler', $html);
        $this->assertStringNotContainsString('wide_open', $html);
        $this->assertStringNotContainsString('Thanks,', $html);
    }
}
