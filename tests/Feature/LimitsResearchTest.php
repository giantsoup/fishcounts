<?php

namespace Tests\Feature;

use App\Models\AiBudgetReservation;
use App\Models\RegulationRuleSnapshot;
use App\Services\Parsing\OfficialRegulationResearcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LimitsResearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('limits.enabled', true);
        config()->set('limits.research_enabled', true);
    }

    public function test_historical_lookup_does_not_use_today_or_unverified_and_revoked_rules(): void
    {
        $snapshot = RegulationRuleSnapshot::factory()->create();
        $research = app(OfficialRegulationResearcher::class);
        $this->assertSame(5, $research->find('mexico', 'kelp_bass', '2026-10-07')['daily']);
        $this->assertNull($research->find('mexico', 'kelp_bass', '2025-10-07'));
        $this->assertNull($research->find('mexico', 'rockfish', '2026-10-07'));
        config()->set('limits.revoked_hashes', [$snapshot->snapshot_hash]);
        $this->assertNull($research->find('mexico', 'kelp_bass', '2026-10-07'));
        Http::assertNothingSent();
    }

    public function test_official_research_retains_sources_content_hashes_and_dated_review(): void
    {
        Http::fake(['*' => Http::response('<main>4.9.1 diez cinco 4.9.3 vigente</main>', 200, ['Content-Type' => 'text/html'])]);
        $research = app(OfficialRegulationResearcher::class);
        $research->research('mexico', 'kelp_bass', '2026-10-07');
        $rule = $research->find('mexico', 'kelp_bass', '2026-10-07');
        $this->assertSame(5, $rule['daily']);
        $this->assertCount(2, $rule['sources']);
        $this->assertSame(hash('sha256', '<main>4.9.1 diez cinco 4.9.3 vigente</main>'), $rule['sources'][0]['content_hash']);
        $this->assertSame('2026-10-01', $rule['effective_from']);
        $this->assertNotEmpty($rule['snapshot_hash']);
        $this->assertNull($research->find('mexico', 'kelp_bass', '2026-09-30'));
    }

    public function test_changed_official_text_does_not_become_an_approved_rule(): void
    {
        Http::fake(['*' => Http::response('Proposed rules, not operative')]);
        $this->expectException(\RuntimeException::class);
        try {
            app(OfficialRegulationResearcher::class)->research('mexico', 'kelp_bass', '2026-10-07');
        } finally {
            $this->assertSame(0, RegulationRuleSnapshot::query()->count());
        }
    }

    public function test_unapproved_hosts_and_redirects_cannot_supply_evidence(): void
    {
        config()->set('limits.sources.mexico', ['http://127.0.0.1/private']);
        $this->expectException(\RuntimeException::class);
        try {
            app(OfficialRegulationResearcher::class)->research('mexico', 'kelp_bass', '2026-10-07');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_oversized_or_redirected_responses_do_not_create_rules(): void
    {
        Http::fake(['*' => Http::response('redirect', 302, ['Location' => 'http://127.0.0.1/secret'])]);
        $this->expectException(\RuntimeException::class);
        try {
            app(OfficialRegulationResearcher::class)->research('mexico', 'kelp_bass', '2026-10-07');
        } finally {
            $this->assertSame(0, RegulationRuleSnapshot::query()->count());
            Http::assertSentCount(1);
        }
    }

    public function test_ai_research_is_cited_budgeted_and_does_not_auto_approve_an_unreviewed_rule(): void
    {
        config()->set('services.openai.api_key', 'test-only-not-a-secret');
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');
        $quote = 'A rule needs effective-date and exception review.';
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(['id' => 'resp_fixture', 'status' => 'completed', 'usage' => ['input_tokens' => 100, 'output_tokens' => 100, 'total_tokens' => 200], 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['daily' => null, 'citations' => [['url' => config('limits.sources.mexico')[0], 'quote' => $quote]]])]]]]]),
            '*' => Http::response($quote, 200, ['Content-Type' => 'text/html']),
        ]);
        $research = app(OfficialRegulationResearcher::class);
        $research->research('mexico', 'unresolved_species', '2026-10-07');
        $this->assertNull($research->find('mexico', 'unresolved_species', '2026-10-07'));
        $snapshot = RegulationRuleSnapshot::query()->sole();
        $this->assertNull($snapshot->verified_at);
        $this->assertSame($quote, $snapshot->rule['candidate']['citations'][0]['quote']);
        $this->assertNotNull(AiBudgetReservation::query()->sole()->settled_at);
        $research->research('mexico', 'unresolved_species', '2026-10-07');
        $this->assertSame(1, AiBudgetReservation::query()->count());
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/responses') && $request['model'] === 'gpt-6-luna' && $request['reasoning']['effort'] === 'high' && $request['store'] === false);
    }

    public function test_snapshots_are_immutable_and_hashes_ignore_json_key_order(): void
    {
        $snapshot = RegulationRuleSnapshot::factory()->create();
        $this->assertSame(RegulationRuleSnapshot::fingerprint($snapshot->rule), RegulationRuleSnapshot::fingerprint(array_reverse($snapshot->rule, true)));
        $this->expectException(\LogicException::class);
        $snapshot->update(['rule' => ['daily' => 500]]);
    }
}
