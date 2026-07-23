<?php

namespace Tests\Feature;

use App\Enums\ParserEngine;
use App\Enums\SourceType;
use App\Models\ParserEngineChange;
use App\Models\ScrapeSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParserEngineAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_change_parser_engine_with_an_audit_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $source = $this->source();

        $this->actingAs($admin)->put(route('admin.sources.update', $source), [
            'priority' => 10,
            'rate_limit_seconds' => 10,
            'parser_engine' => ParserEngine::Ai->value,
            'parser_engine_change_reason' => 'Enable monitored AI parsing.',
        ])->assertRedirect(route('admin.sources.index'));

        $this->assertSame(ParserEngine::Ai, $source->refresh()->parser_engine);
        $change = ParserEngineChange::query()->sole();
        $this->assertSame(ParserEngine::Deterministic, $change->previous_engine);
        $this->assertSame(ParserEngine::Ai, $change->new_engine);
        $this->assertSame($admin->id, $change->user_id);
        $this->assertSame('Enable monitored AI parsing.', $change->reason);
    }

    public function test_engine_change_requires_a_valid_engine_and_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $source = $this->source();

        $this->actingAs($admin)->put(route('admin.sources.update', $source), [
            'priority' => 10,
            'rate_limit_seconds' => 10,
            'parser_engine' => 'unknown',
        ])->assertSessionHasErrors('parser_engine');

        $this->actingAs($admin)->put(route('admin.sources.update', $source), [
            'priority' => 10,
            'rate_limit_seconds' => 10,
            'parser_engine' => ParserEngine::Ai->value,
        ])->assertSessionHasErrors('parser_engine_change_reason');

        $this->assertSame(ParserEngine::Deterministic, $source->refresh()->parser_engine);
        $this->assertDatabaseCount('parser_engine_changes', 0);
    }

    public function test_non_admin_cannot_change_parser_engine(): void
    {
        $source = $this->source();

        $this->actingAs(User::factory()->create())->put(route('admin.sources.update', $source), [
            'priority' => 10,
            'rate_limit_seconds' => 10,
            'parser_engine' => ParserEngine::Ai->value,
            'parser_engine_change_reason' => 'Unauthorized.',
        ])->assertForbidden();
    }

    private function source(): ScrapeSource
    {
        return ScrapeSource::query()->create([
            'name' => 'Test Landing',
            'slug' => 'test_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://example.test',
        ]);
    }
}
