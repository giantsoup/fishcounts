<?php

namespace Tests\Feature\Api;

use App\Enums\ParserErrorResolutionType;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use App\Models\ScrapeSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ParserErrorIndexTest extends TestCase
{
    use RefreshDatabase;

    private ?string $token = null;

    public function test_admin_token_lists_open_parser_errors_as_json(): void
    {
        $token = User::factory()->admin()->create()->createToken('agent', ['read'])->plainTextToken;
        $open = ParserError::factory()->create(['raw_value' => 'Pacific Queeen']);
        ParserError::factory()->resolved()->create();

        $this->withToken($token)
            ->getJson('/api/parser-errors')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $open->id)
            ->assertJsonPath('data.0.error_type', 'unknown_boat_alias')
            ->assertJsonPath('data.0.kind', 'alias')
            ->assertJsonPath('data.0.raw_value', 'Pacific Queeen')
            ->assertJsonPath('data.0.status', 'open')
            ->assertJsonPath('data.0.scrape_source.id', $open->scrape_source_id)
            ->assertJsonPath('data.0.scrape_source.parser_engine', 'deterministic')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_status_filter_selects_open_resolved_or_all(): void
    {
        $open = ParserError::factory()->create();
        $resolved = ParserError::factory()->resolved()->create();
        $dismissed = ParserError::factory()->resolved(ParserErrorResolutionType::Dismissed)->create();

        $this->assertListedIds(['status' => 'open'], [$open->id]);
        $this->assertListedIds(['status' => 'resolved'], [$dismissed->id, $resolved->id]);
        $this->assertListedIds(['status' => 'all'], [$dismissed->id, $resolved->id, $open->id]);
    }

    public function test_kind_filter_separates_alias_and_structural_errors(): void
    {
        $alias = ParserError::factory()->create(['error_type' => 'unknown_species_alias']);
        $structural = ParserError::factory()->structural()->create();

        $this->assertListedIds(['kind' => 'alias'], [$alias->id]);
        $this->assertListedIds(['kind' => 'structural'], [$structural->id]);

        $this->withToken($this->readToken())
            ->getJson('/api/parser-errors?kind=structural')
            ->assertJsonPath('data.0.kind', 'structural');
    }

    public function test_type_filter_matches_exact_error_type(): void
    {
        $tripType = ParserError::factory()->create(['error_type' => 'unknown_trip_type_alias']);
        ParserError::factory()->create(['error_type' => 'unknown_boat_alias']);
        ParserError::factory()->structural('prose_captured_as_entity')->create();

        $this->assertListedIds(['type' => 'unknown_trip_type_alias'], [$tripType->id]);
    }

    public function test_source_and_target_date_range_filters(): void
    {
        $source = ScrapeSource::factory()->create();
        $july10 = $this->errorFor($source, '2026-07-10');
        $july20 = $this->errorFor($source, '2026-07-20');
        $otherSourceJuly10 = $this->errorFor(ScrapeSource::factory()->create(), '2026-07-10');

        $this->assertListedIds(['source' => $source->id], [$july20->id, $july10->id]);
        $this->assertListedIds(['source' => $source->id, 'from' => '2026-07-05', 'to' => '2026-07-15'], [$july10->id]);
        $this->assertListedIds(['from' => '2026-07-15'], [$july20->id]);
        $this->assertListedIds(['to' => '2026-07-10'], [$otherSourceJuly10->id, $july10->id]);
    }

    public function test_results_are_paginated_with_totals(): void
    {
        ParserError::factory()->count(5)->create();

        $this->withToken($this->readToken())
            ->getJson('/api/parser-errors?per_page=2&page=3')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 3)
            ->assertJsonPath('meta.last_page', 3);
    }

    public function test_per_page_defaults_to_fifty_and_is_capped_at_one_hundred(): void
    {
        $token = $this->readToken();

        $this->withToken($token)->getJson('/api/parser-errors')->assertJsonPath('meta.per_page', 50);
        $this->withToken($token)->getJson('/api/parser-errors?per_page=100')->assertOk();
        $this->withToken($token)
            ->getJson('/api/parser-errors?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function invalidFilters(): array
    {
        return [
            'unknown status' => [['status' => 'pending'], 'status'],
            'unknown kind' => [['kind' => 'other'], 'kind'],
            'unknown type' => [['type' => 'drop table'], 'type'],
            'non-integer source' => [['source' => 'abc'], 'source'],
            'non-date from' => [['from' => 'yesterday-ish'], 'from'],
            'to before from' => [['from' => '2026-07-10', 'to' => '2026-07-01'], 'to'],
            'zero per_page' => [['per_page' => '0'], 'per_page'],
            'non-integer page' => [['page' => 'x'], 'page'],
        ];
    }

    /**
     * @param  array<string, string>  $query
     */
    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_get_json_422(array $query, string $field): void
    {
        $this->withToken($this->readToken())
            ->getJson('/api/parser-errors?'.http_build_query($query))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    public function test_list_never_exposes_resolver_email_or_context(): void
    {
        $resolver = User::factory()->admin()->create(['email' => 'resolver@example.test']);
        ParserError::factory()->resolved()->create(['resolved_by_user_id' => $resolver->id]);

        $response = $this->withToken($this->readToken())->getJson('/api/parser-errors?status=all')->assertOk();

        $this->assertStringNotContainsString('resolver@example.test', $response->getContent());
        $this->assertArrayNotHasKey('context', $response->json('data.0'));
    }

    /**
     * @param  array<string, string|int>  $query
     * @param  list<int>  $expectedIds  Newest first.
     */
    private function assertListedIds(array $query, array $expectedIds): void
    {
        $this->app['auth']->forgetGuards();

        $ids = $this->withToken($this->readToken())
            ->getJson('/api/parser-errors?'.http_build_query($query))
            ->assertOk()
            ->json('data.*.id');

        $this->assertSame($expectedIds, $ids, http_build_query($query));
    }

    private function errorFor(ScrapeSource $source, string $targetDate): ParserError
    {
        return ParserError::factory()
            ->for(RawScrapePayload::factory()->for($source)->state(['target_date' => $targetDate]))
            ->create();
    }

    private function readToken(): string
    {
        return $this->token ??= User::factory()->admin()->create()->createToken('agent', ['read'])->plainTextToken;
    }
}
