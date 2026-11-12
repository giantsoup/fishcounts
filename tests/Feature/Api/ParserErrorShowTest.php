<?php

namespace Tests\Feature\Api;

use App\Enums\ParserEngine;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use App\Models\ScrapeSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParserErrorShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_one_parser_error_with_its_redacted_context_payload_metadata_and_engine(): void
    {
        $source = ScrapeSource::factory()->create(['parser_engine' => ParserEngine::Ai]);
        $payload = RawScrapePayload::factory()->for($source)->create([
            'target_date' => '2026-07-14',
            'url' => 'https://example.test/counts?date=2026-07-14&api_key=secret123',
            'http_status' => 200,
            'content_type' => 'text/html',
            'payload' => '<p>full raw body that must not appear</p>',
            'parser_version' => 'deterministic-v9',
        ]);
        $error = ParserError::factory()->for($payload)->create([
            'raw_value' => 'Pacific Queeen',
            'context' => [
                'source' => $source->slug,
                'date' => '2026-07-14',
                'url' => 'https://example.test/counts',
                'parser_version' => 'deterministic-v9',
                'format' => 'paragraph',
                'report_index' => 2,
                'source_identifier' => null,
                'sanitized_paragraph' => 'Pacific Queeen 1/2 Day AM 22 anglers 40 Calico Bass.',
                'extracted_fields' => ['boat' => 'Pacific Queeen', 'anglers' => 22],
                'evidence' => ['candidates' => ['Pacific Queen']],
                'unlisted_key' => 'should not be returned',
            ],
        ]);

        $response = $this->withToken($this->readToken())
            ->getJson("/api/parser-errors/{$error->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $error->id)
            ->assertJsonPath('data.raw_value', 'Pacific Queeen')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.context.paragraph', 'Pacific Queeen 1/2 Day AM 22 anglers 40 Calico Bass.')
            ->assertJsonPath('data.context.extracted_fields', ['boat' => 'Pacific Queeen', 'anglers' => 22])
            ->assertJsonPath('data.context.evidence', ['candidates' => ['Pacific Queen']])
            ->assertJsonPath('data.context.report_index', 2)
            ->assertJsonPath('data.raw_scrape_payload.id', $payload->id)
            ->assertJsonPath('data.raw_scrape_payload.target_date', '2026-07-14')
            ->assertJsonPath('data.raw_scrape_payload.url', 'https://example.test/counts')
            ->assertJsonPath('data.raw_scrape_payload.http_status', 200)
            ->assertJsonPath('data.raw_scrape_payload.content_type', 'text/html')
            ->assertJsonPath('data.raw_scrape_payload.parser_version', 'deterministic-v9')
            ->assertJsonPath('data.scrape_source.id', $source->id)
            ->assertJsonPath('data.scrape_source.parser_engine', 'ai');

        $this->assertArrayNotHasKey('unlisted_key', $response->json('data.context'));
        $this->assertArrayNotHasKey('payload', $response->json('data.raw_scrape_payload'));
        $this->assertStringNotContainsString('full raw body', $response->getContent());
        $this->assertStringNotContainsString('secret123', $response->getContent());
    }

    public function test_missing_parser_error_returns_json_404(): void
    {
        $this->withToken($this->readToken())
            ->getJson('/api/parser-errors/999999')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }

    public function test_show_requires_the_read_ability(): void
    {
        $error = ParserError::factory()->create();
        $token = User::factory()->admin()->create()->createToken('agent', ['errors'])->plainTextToken;

        $this->withToken($token)->getJson("/api/parser-errors/{$error->id}")->assertForbidden();
    }

    private function readToken(): string
    {
        return User::factory()->admin()->create()->createToken('agent', ['read'])->plainTextToken;
    }
}
