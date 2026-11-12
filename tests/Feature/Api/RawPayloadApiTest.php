<?php

namespace Tests\Feature\Api;

use App\Enums\ParserEngine;
use App\Models\ParserExecution;
use App\Models\RawScrapePayload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RawPayloadApiTest extends TestCase
{
    use RefreshDatabase;

    private ?string $token = null;

    public function test_metadata_includes_source_timestamps_parser_version_error_and_authoritative_execution(): void
    {
        $payload = RawScrapePayload::factory()->create([
            'url' => 'https://landing.example.test/counts',
            'fetched_at' => '2026-07-01 06:00:00',
            'parsed_at' => '2026-07-01 06:05:00',
            'parser_version' => 'source-specific-fishermans_landing-v16',
            'error_message' => 'Parse failed on row 3.',
        ]);
        $execution = ParserExecution::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'scrape_source_id' => $payload->scrape_source_id,
            'idempotency_key' => str_repeat('a', 64),
            'requested_engine' => ParserEngine::Deterministic,
            'selected_engine' => ParserEngine::Deterministic,
            'status' => 'succeeded',
            'payload_hash' => $payload->payload_hash,
            'parser_version' => 'source-specific-fishermans_landing-v16',
            'deterministic_snapshot' => ['reports' => []],
            'started_at' => '2026-07-01 06:04:00',
            'completed_at' => '2026-07-01 06:05:00',
        ]);
        $payload->update(['authoritative_parser_execution_id' => $execution->id]);

        $response = $this->withToken($this->readToken())
            ->getJson("/api/raw-payloads/{$payload->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $payload->id)
            ->assertJsonPath('data.scrape_source.id', $payload->scrape_source_id)
            ->assertJsonPath('data.scrape_source.parser_engine', 'deterministic')
            ->assertJsonPath('data.url', 'https://landing.example.test/counts')
            ->assertJsonPath('data.target_date', '2026-07-01')
            ->assertJsonPath('data.fetched_at', '2026-07-01T06:00:00+00:00')
            ->assertJsonPath('data.parsed_at', '2026-07-01T06:05:00+00:00')
            ->assertJsonPath('data.parser_version', 'source-specific-fishermans_landing-v16')
            ->assertJsonPath('data.error_message', 'Parse failed on row 3.')
            ->assertJsonPath('data.authoritative_parser_execution.id', $execution->id)
            ->assertJsonPath('data.authoritative_parser_execution.status', 'succeeded')
            ->assertJsonPath('data.authoritative_parser_execution.selected_engine', 'deterministic');

        $this->assertArrayNotHasKey('payload', $response->json('data'));
        $this->assertArrayNotHasKey('body', $response->json('data'));
        $this->assertArrayNotHasKey('deterministic_snapshot', $response->json('data.authoritative_parser_execution'));
    }

    public function test_metadata_for_unknown_payload_is_json_404(): void
    {
        $this->withToken($this->readToken())
            ->getJson('/api/raw-payloads/999999')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }

    public function test_body_is_returned_with_secrets_redacted(): void
    {
        $body = "<html><head><script>var cfg = {api_key: \"sk-live-123\"};</script></head>\n"
            ."<p>Authorization: Bearer abc.def.ghi</p>\n"
            ."<p>Cookie: session=xyz; remember=1</p>\n"
            ."<p>password=hunter2</p>\n"
            .'<p>The Dolphin caught 17 Yellowtail.</p></html>';
        $payload = RawScrapePayload::factory()->create(['payload' => $body]);

        $response = $this->withToken($this->readToken())
            ->getJson("/api/raw-payloads/{$payload->id}/body")
            ->assertOk()
            ->assertJsonPath('data.id', $payload->id)
            ->assertJsonPath('data.truncated', false)
            ->assertJsonPath('data.original_bytes', strlen($body));

        $returned = $response->json('data.body');
        $this->assertStringContainsString('<p>The Dolphin caught 17 Yellowtail.</p>', $returned);
        $this->assertStringContainsString('Authorization=[redacted]', $returned);
        $this->assertStringContainsString('password=[redacted]', $returned);
        foreach (['sk-live-123', 'abc.def.ghi', 'session=xyz', 'hunter2'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_body_is_capped_at_the_configured_size_and_reports_truncation(): void
    {
        config()->set('fish.api.raw_payload_body_max_bytes', 100);
        $body = str_repeat('a', 99).'é'.str_repeat('b', 400);
        $payload = RawScrapePayload::factory()->create(['payload' => $body]);

        $response = $this->withToken($this->readToken())
            ->getJson("/api/raw-payloads/{$payload->id}/body")
            ->assertOk()
            ->assertJsonPath('data.truncated', true)
            ->assertJsonPath('data.original_bytes', 501);

        $this->assertSame(str_repeat('a', 99), $response->json('data.body'));
    }

    public function test_body_at_exactly_the_cap_is_not_truncated(): void
    {
        config()->set('fish.api.raw_payload_body_max_bytes', 100);
        $payload = RawScrapePayload::factory()->create(['payload' => str_repeat('a', 100)]);

        $this->withToken($this->readToken())
            ->getJson("/api/raw-payloads/{$payload->id}/body")
            ->assertOk()
            ->assertJsonPath('data.truncated', false)
            ->assertJsonPath('data.body', str_repeat('a', 100));
    }

    public function test_body_cap_defaults_to_about_200_kb(): void
    {
        $payload = RawScrapePayload::factory()->create(['payload' => str_repeat('x', 250_000)]);

        $response = $this->withToken($this->readToken())
            ->getJson("/api/raw-payloads/{$payload->id}/body")
            ->assertOk()
            ->assertJsonPath('data.truncated', true)
            ->assertJsonPath('data.original_bytes', 250_000);

        $this->assertSame(200_000, strlen($response->json('data.body')));
    }

    private function readToken(): string
    {
        return $this->token ??= User::factory()->admin()->create()->createToken('agent', ['read'])->plainTextToken;
    }
}
