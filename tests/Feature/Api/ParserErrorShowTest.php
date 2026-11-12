<?php

namespace Tests\Feature\Api;

use App\Enums\ParserEngine;
use App\Models\Boat;
use App\Models\Landing;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use App\Models\Region;
use App\Models\ScrapeSource;
use App\Models\Species;
use App\Models\TripReport;
use App\Models\TripType;
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

    public function test_embeds_the_trip_reports_parsed_from_the_errors_payload(): void
    {
        $payload = RawScrapePayload::factory()->create();
        $otherPayload = RawScrapePayload::factory()->create();
        $error = ParserError::factory()->for($payload)->create();
        $region = Region::query()->create(['name' => 'San Diego', 'slug' => 'san-diego']);
        $landing = Landing::query()->create(['region_id' => $region->id, 'name' => 'Seaforth', 'slug' => 'seaforth']);
        $boat = Boat::query()->create(['landing_id' => $landing->id, 'name' => 'Pacific Queen', 'slug' => 'pacific-queen']);
        $tripType = TripType::query()->create(['name' => '1/2 Day AM', 'slug' => 'half-day-am']);
        $calico = Species::query()->create(['name' => 'Calico Bass', 'slug' => 'calico-bass']);
        $report = TripReport::query()->create([
            'source_id' => $payload->scrape_source_id,
            'raw_scrape_payload_id' => $payload->id,
            'landing_id' => $landing->id,
            'boat_id' => $boat->id,
            'trip_type_id' => $tripType->id,
            'trip_date' => '2026-07-01',
            'anglers' => 22,
            'raw_boat_name' => 'Pacific Queeen',
            'dedupe_key' => 'pq-2026-07-01',
        ]);
        $report->speciesCounts()->create(['species_id' => $calico->id, 'count' => 40, 'released_count' => 12, 'raw_species_name' => 'Calico Bass']);
        TripReport::query()->create(['source_id' => $otherPayload->scrape_source_id, 'raw_scrape_payload_id' => $otherPayload->id, 'trip_date' => '2026-07-01', 'dedupe_key' => 'other']);

        $this->withToken($this->readToken())
            ->getJson("/api/parser-errors/{$error->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.trip_reports')
            ->assertJsonPath('data.trip_reports.0.id', $report->id)
            ->assertJsonPath('data.trip_reports.0.trip_date', '2026-07-01')
            ->assertJsonPath('data.trip_reports.0.anglers', 22)
            ->assertJsonPath('data.trip_reports.0.raw_boat_name', 'Pacific Queeen')
            ->assertJsonPath('data.trip_reports.0.boat', ['id' => $boat->id, 'name' => 'Pacific Queen'])
            ->assertJsonPath('data.trip_reports.0.landing', ['id' => $landing->id, 'name' => 'Seaforth'])
            ->assertJsonPath('data.trip_reports.0.trip_type', ['id' => $tripType->id, 'name' => '1/2 Day AM'])
            ->assertJsonPath('data.trip_reports.0.species_counts.0.species', ['id' => $calico->id, 'name' => 'Calico Bass'])
            ->assertJsonPath('data.trip_reports.0.species_counts.0.count', 40)
            ->assertJsonPath('data.trip_reports.0.species_counts.0.released_count', 12);
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
