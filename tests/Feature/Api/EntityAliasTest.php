<?php

namespace Tests\Feature\Api;

use App\Enums\ParserErrorResolutionType;
use App\Models\Boat;
use App\Models\ParserError;
use App\Models\ScrapeSource;
use App\Models\Species;
use App\Models\TripReport;
use App\Models\TripType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class EntityAliasTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = User::factory()->admin()->create();
    }

    public function test_species_alias_resolves_matching_open_errors_as_agent_alias(): void
    {
        $species = Species::factory()->create(['name' => 'Yellowtail']);
        $matching = $this->speciesError('Yellow-Tail');
        $alsoMatching = $this->speciesError('yellow tail');
        $other = $this->speciesError('Bonito');

        $this->withToken($this->token(['aliases']))
            ->postJson("/api/species/{$species->id}/aliases", ['alias' => '  Yellow   Tail ', 'parser_error_id' => $matching->id])
            ->assertCreated()
            ->assertJsonPath('data.alias', 'Yellow Tail')
            ->assertJsonPath('data.normalized_alias', 'yellow tail')
            ->assertJsonPath('meta.resolved_parser_errors', 2)
            ->assertJsonPath('meta.parser_error.id', $matching->id)
            ->assertJsonPath('meta.parser_error.resolved', true);

        foreach ([$matching, $alsoMatching] as $error) {
            $error->refresh();
            $this->assertNotNull($error->resolved_at);
            $this->assertSame('agent_alias', $error->resolution_type->value);
            $this->assertSame($this->agent->id, $error->resolved_by_user_id);
        }

        $this->assertNull($other->refresh()->resolved_at);
        $this->assertDatabaseHas('species_aliases', ['species_id' => $species->id, 'normalized_alias' => 'yellow tail']);
    }

    public function test_trip_type_alias_resolves_matching_open_errors_as_agent_alias(): void
    {
        $tripType = TripType::factory()->create(['name' => 'Overnight Run']);
        $error = $this->aliasError('unknown_trip_type_alias', 'trip_type', 'Over-Night Run');

        $this->withToken($this->token(['aliases']))
            ->postJson("/api/trip-types/{$tripType->id}/aliases", ['alias' => 'Over Night Run'])
            ->assertCreated()
            ->assertJsonPath('data.normalized_alias', 'over night run')
            ->assertJsonPath('meta.resolved_parser_errors', 1)
            ->assertJsonPath('meta.parser_error', null);

        $error->refresh();
        $this->assertSame(ParserErrorResolutionType::AgentAlias, $error->resolution_type);
        $this->assertSame($this->agent->id, $error->resolved_by_user_id);
        $this->assertDatabaseHas('trip_type_aliases', ['trip_type_id' => $tripType->id, 'alias' => 'Over Night Run']);
    }

    public function test_boat_alias_resolves_errors_and_repoints_unmatched_reports(): void
    {
        $source = ScrapeSource::factory()->create();
        $boat = Boat::factory()->create(['name' => 'Dolphin', 'slug' => 'dolphin']);
        $unmatchedReport = TripReport::query()->create([
            'source_id' => $source->id,
            'boat_id' => null,
            'trip_date' => '2026-07-10',
            'raw_boat_name' => 'THE DOLPHIN!',
            'raw_trip_type' => 'Full Day',
            'anglers' => 20,
            'dedupe_key' => 'unmatched-report',
        ]);
        $error = $this->aliasError('unknown_boat_alias', 'boat', 'The Dolphin');

        $this->withToken($this->token(['aliases']))
            ->postJson("/api/boats/{$boat->id}/aliases", ['alias' => 'The Dolphin', 'parser_error_id' => $error->id])
            ->assertCreated()
            ->assertJsonPath('data.normalized_alias', 'the dolphin')
            ->assertJsonPath('meta.resolved_parser_errors', 1)
            ->assertJsonPath('meta.parser_error.resolved', true)
            ->assertJsonPath('meta.parser_error.resolution_type', 'agent_alias');

        $this->assertSame(ParserErrorResolutionType::AgentAlias, $error->refresh()->resolution_type);
        $this->assertSame($this->agent->id, $error->resolved_by_user_id);
        $this->assertSame($boat->id, $unmatchedReport->refresh()->boat_id);
        $this->assertSame('2026-07-10|dolphin|full-day|20', $unmatchedReport->dedupe_key);
    }

    public function test_boat_alias_that_would_merge_another_boat_returns_409_and_changes_nothing(): void
    {
        $source = ScrapeSource::factory()->create();
        $boat = Boat::factory()->create(['name' => 'Dolphin', 'slug' => 'dolphin']);
        $namedVariant = Boat::factory()->create(['name' => 'The Dolphin', 'slug' => 'the-dolphin']);
        $aliasedVariant = Boat::factory()->create(['name' => 'Dolphin II', 'slug' => 'dolphin-ii']);
        $aliasedVariant->aliases()->create(['alias' => 'Dolphin Two', 'normalized_alias' => 'dolphin two']);
        $variantReport = TripReport::query()->create([
            'source_id' => $source->id,
            'boat_id' => $namedVariant->id,
            'trip_date' => '2026-07-09',
            'dedupe_key' => 'variant-report',
        ]);
        $error = $this->aliasError('unknown_boat_alias', 'boat', 'The Dolphin');
        $token = $this->token(['aliases']);

        foreach ([[$namedVariant, 'The Dolphin'], [$aliasedVariant, 'dolphin two']] as [$variant, $alias]) {
            $message = $this->withToken($token)
                ->postJson("/api/boats/{$boat->id}/aliases", ['alias' => $alias])
                ->assertConflict()
                ->json('message');

            $this->assertStringContainsString("\"{$variant->name}\" (id {$variant->id})", $message);
            $this->assertStringContainsString("POST /api/boats/{$boat->id}/merge", $message);
            $this->assertStringContainsString("\"duplicate_boat_id\": {$variant->id}", $message);
            $this->assertTrue($variant->refresh()->is_active);
        }

        $this->assertSame($namedVariant->id, $variantReport->refresh()->boat_id);
        $this->assertSame(1, $aliasedVariant->aliases()->count());
        $this->assertSame(0, $boat->aliases()->count());
        $this->assertNull($error->refresh()->resolved_at);
    }

    public function test_response_says_when_the_named_parser_error_was_not_resolved(): void
    {
        $species = Species::factory()->create(['name' => 'Yellowtail']);
        $unrelated = $this->aliasError('unknown_species_alias', 'species', 'Bonito');

        $this->withToken($this->token(['aliases']))
            ->postJson("/api/species/{$species->id}/aliases", ['alias' => 'Yellow Tail', 'parser_error_id' => $unrelated->id])
            ->assertCreated()
            ->assertJsonPath('meta.resolved_parser_errors', 0)
            ->assertJsonPath('meta.parser_error.id', $unrelated->id)
            ->assertJsonPath('meta.parser_error.resolved', false)
            ->assertJsonPath('meta.parser_error.resolution_type', null);

        $this->assertNull($unrelated->refresh()->resolved_at);
    }

    public function test_named_parser_error_resolved_before_the_call_is_not_reported_as_resolved_by_it(): void
    {
        $species = Species::factory()->create(['name' => 'Yellowtail']);
        $dismissed = ParserError::factory()->resolved(ParserErrorResolutionType::Dismissed)->create([
            'error_type' => 'unknown_species_alias',
            'raw_field' => 'species',
            'raw_value' => 'Yellow Tail',
        ]);
        $alreadyAliased = $this->speciesError('yellowtails');
        $token = $this->token(['aliases']);

        $this->withToken($token)
            ->postJson("/api/species/{$species->id}/aliases", ['alias' => 'Yellow Tail', 'parser_error_id' => $dismissed->id])
            ->assertCreated()
            ->assertJsonPath('meta.resolved_parser_errors', 0)
            ->assertJsonPath('meta.parser_error.id', $dismissed->id)
            ->assertJsonPath('meta.parser_error.resolved', false)
            ->assertJsonPath('meta.parser_error.resolution_type', 'dismissed');

        $this->withToken($token)
            ->postJson("/api/species/{$species->id}/aliases", ['alias' => 'Yellowtails'])
            ->assertCreated()
            ->assertJsonPath('meta.resolved_parser_errors', 1);

        $this->withToken($token)
            ->postJson("/api/species/{$species->id}/aliases", ['alias' => 'Yellowtails', 'parser_error_id' => $alreadyAliased->id])
            ->assertOk()
            ->assertJsonPath('meta.resolved_parser_errors', 0)
            ->assertJsonPath('meta.parser_error.resolved', false)
            ->assertJsonPath('meta.parser_error.resolution_type', 'agent_alias');
    }

    public function test_alias_clashing_with_another_species_or_trip_type_returns_422(): void
    {
        $token = $this->token(['aliases']);
        $species = Species::factory()->create(['name' => 'Yellowtail']);
        Species::factory()->create(['name' => 'Bonito', 'slug' => 'bonito']);
        $other = Species::factory()->create(['name' => 'Bluefin Tuna']);
        $other->aliases()->create(['alias' => 'Bluefin', 'normalized_alias' => 'bluefin']);
        $tripType = TripType::factory()->create(['name' => 'Overnight Run']);
        TripType::factory()->create(['name' => 'Full Day Run', 'slug' => 'full-day-run']);
        $error = $this->aliasError('unknown_species_alias', 'species', 'Bonito');

        $this->withToken($token)
            ->postJson("/api/species/{$species->id}/aliases", ['alias' => 'Bonito'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('alias');
        $this->withToken($token)
            ->postJson("/api/species/{$species->id}/aliases", ['alias' => 'BLUEFIN'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('alias');
        $this->withToken($token)
            ->postJson("/api/trip-types/{$tripType->id}/aliases", ['alias' => 'Full Day Run'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('alias');

        $this->assertNull($error->refresh()->resolved_at);
        $this->assertSame(0, $species->aliases()->count());
        $this->assertSame(0, $tripType->aliases()->count());
    }

    public function test_invalid_alias_input_returns_422(): void
    {
        $token = $this->token(['aliases']);
        $species = Species::factory()->create();
        $inactive = Boat::factory()->inactive()->create();

        $cases = [
            ["/api/species/{$species->id}/aliases", [], 'alias'],
            ["/api/species/{$species->id}/aliases", ['alias' => '!!!'], 'alias'],
            ["/api/species/{$species->id}/aliases", ['alias' => str_repeat('a', 256)], 'alias'],
            ["/api/species/{$species->id}/aliases", ['alias' => ['x']], 'alias'],
            ["/api/species/{$species->id}/aliases", ['alias' => 'Yellow Tail', 'parser_error_id' => 999], 'parser_error_id'],
            ["/api/species/{$species->id}/aliases", ['alias' => 'Yellow Tail', 'parser_error_id' => 'abc'], 'parser_error_id'],
            ["/api/boats/{$inactive->id}/aliases", ['alias' => 'Old Name'], 'alias'],
        ];

        foreach ($cases as [$path, $body, $field]) {
            $this->withToken($token)->postJson($path, $body)->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseCount('species_aliases', 0);
        $this->assertDatabaseCount('boat_aliases', 0);
    }

    public function test_aliases_are_listed_per_record(): void
    {
        $boat = Boat::factory()->create();
        $boat->aliases()->create(['alias' => 'Zeta', 'normalized_alias' => 'zeta']);
        $boat->aliases()->create(['alias' => 'Alpha', 'normalized_alias' => 'alpha']);
        Boat::factory()->create()->aliases()->create(['alias' => 'Other', 'normalized_alias' => 'other']);
        $species = Species::factory()->create();
        $species->aliases()->create(['alias' => 'Yellows', 'normalized_alias' => 'yellows']);
        $tripType = TripType::factory()->create();
        $tripType->aliases()->create(['alias' => 'Overnite', 'normalized_alias' => 'overnite']);
        $token = $this->token(['read']);

        $this->withToken($token)
            ->getJson("/api/boats/{$boat->id}/aliases")
            ->assertOk()
            ->assertJsonPath('data.*.alias', ['Alpha', 'Zeta'])
            ->assertJsonPath('meta.total', 2);
        $this->withToken($token)->getJson("/api/species/{$species->id}/aliases")->assertJsonPath('data.*.alias', ['Yellows']);
        $this->withToken($token)->getJson("/api/trip-types/{$tripType->id}/aliases")->assertJsonPath('data.*.alias', ['Overnite']);
        $this->withToken($token)->getJson('/api/boats/999/aliases')->assertNotFound();
    }

    public function test_aliases_can_be_deleted(): void
    {
        $boatAlias = Boat::factory()->create()->aliases()->create(['alias' => 'Pac Q', 'normalized_alias' => 'pac q']);
        $speciesAlias = Species::factory()->create()->aliases()->create(['alias' => 'Yellows', 'normalized_alias' => 'yellows']);
        $tripTypeAlias = TripType::factory()->create()->aliases()->create(['alias' => 'Overnite', 'normalized_alias' => 'overnite']);
        $token = $this->token(['aliases']);

        $this->withToken($token)->deleteJson("/api/boats/aliases/{$boatAlias->id}")->assertNoContent();
        $this->withToken($token)->deleteJson("/api/species/aliases/{$speciesAlias->id}")->assertNoContent();
        $this->withToken($token)->deleteJson("/api/trip-types/aliases/{$tripTypeAlias->id}")->assertNoContent();
        $this->withToken($token)->deleteJson("/api/boats/aliases/{$boatAlias->id}")->assertNotFound();

        $this->assertModelMissing($boatAlias);
        $this->assertModelMissing($speciesAlias);
        $this->assertModelMissing($tripTypeAlias);
    }

    public function test_alias_writes_need_the_aliases_ability(): void
    {
        $species = Species::factory()->create();
        $alias = $species->aliases()->create(['alias' => 'Yellows', 'normalized_alias' => 'yellows']);
        $token = $this->token(['read']);

        $this->withToken($token)->postJson("/api/species/{$species->id}/aliases", ['alias' => 'Yellow Tail'])->assertForbidden();
        $this->withToken($token)->deleteJson("/api/species/aliases/{$alias->id}")->assertForbidden();

        $this->assertModelExists($alias);
        $this->assertSame(1, $species->aliases()->count());
    }

    public function test_alias_writes_log_one_audit_line_without_the_token(): void
    {
        Log::spy();
        $species = Species::factory()->create();
        $token = $this->token(['aliases']);

        $aliasId = $this->withToken($token)
            ->postJson("/api/species/{$species->id}/aliases", ['alias' => 'Yellow Tail'])
            ->assertCreated()
            ->json('data.id');
        $this->withToken($token)->deleteJson("/api/species/aliases/{$aliasId}")->assertNoContent();

        foreach (['species-alias.create', 'species-alias.delete'] as $action) {
            Log::shouldHaveReceived('info')->with('api.write', \Mockery::on(fn (array $context): bool => $context === [
                'user_id' => $this->agent->id,
                'token_name' => 'agent',
                'action' => $action,
                'target_id' => $aliasId,
            ]))->once();
        }
    }

    private function aliasError(string $errorType, string $rawField, string $rawValue): ParserError
    {
        return ParserError::factory()->create([
            'error_type' => $errorType,
            'raw_field' => $rawField,
            'raw_value' => $rawValue,
        ]);
    }

    private function speciesError(string $rawValue): ParserError
    {
        return ParserError::factory()->create([
            'error_type' => 'unknown_species_alias',
            'raw_field' => 'species',
            'raw_value' => $rawValue,
        ]);
    }

    /**
     * @param  list<string>  $abilities
     */
    private function token(array $abilities): string
    {
        return $this->agent->createToken('agent', $abilities)->plainTextToken;
    }
}
