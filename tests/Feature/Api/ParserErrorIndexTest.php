<?php

namespace Tests\Feature\Api;

use App\Models\ParserError;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParserErrorIndexTest extends TestCase
{
    use RefreshDatabase;

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
}
