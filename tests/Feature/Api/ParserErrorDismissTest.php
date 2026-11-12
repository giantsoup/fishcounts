<?php

namespace Tests\Feature\Api;

use App\Enums\ParserErrorResolutionType;
use App\Models\ParserError;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ParserErrorDismissTest extends TestCase
{
    use RefreshDatabase;

    public function test_dismissing_an_open_error_records_the_token_user_as_resolver(): void
    {
        $agent = User::factory()->admin()->create();
        $token = $agent->createToken('parser-agent', ['errors'])->plainTextToken;
        $error = ParserError::factory()->create();
        $logged = $this->captureLogs();

        $this->withToken($token)
            ->patchJson("/api/parser-errors/{$error->id}/dismiss")
            ->assertOk()
            ->assertJsonPath('data.id', $error->id)
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.resolution_type', 'dismissed')
            ->assertJsonPath('data.resolved_by_user_id', $agent->id);

        $error->refresh();
        $this->assertSame(ParserErrorResolutionType::Dismissed, $error->resolution_type);
        $this->assertSame($agent->id, $error->resolved_by_user_id);
        $this->assertNotNull($error->resolved_at);

        $writes = array_values(array_filter($logged->getArrayCopy(), fn (MessageLogged $event): bool => $event->message === 'api.write'));
        $this->assertCount(1, $writes);
        $this->assertSame([
            'user_id' => $agent->id,
            'token_name' => 'parser-agent',
            'action' => 'parser-error.dismiss',
            'target_id' => $error->id,
        ], $writes[0]->context);
    }

    public function test_dismissing_an_already_resolved_error_returns_409_and_keeps_the_original_resolver(): void
    {
        $originalResolver = User::factory()->admin()->create();
        $error = ParserError::factory()->resolved()->create(['resolved_by_user_id' => $originalResolver->id]);
        $token = User::factory()->admin()->create()->createToken('parser-agent', ['errors'])->plainTextToken;
        $logged = $this->captureLogs();

        $this->withToken($token)
            ->patchJson("/api/parser-errors/{$error->id}/dismiss")
            ->assertConflict()
            ->assertJsonPath('message', 'Parser error was already resolved.');

        $error->refresh();
        $this->assertSame(ParserErrorResolutionType::Alias, $error->resolution_type);
        $this->assertSame($originalResolver->id, $error->resolved_by_user_id);
        $this->assertCount(0, array_filter($logged->getArrayCopy(), fn (MessageLogged $event): bool => $event->message === 'api.write'));
    }

    public function test_dismiss_requires_the_errors_ability(): void
    {
        $error = ParserError::factory()->create();
        $token = User::factory()->admin()->create()->createToken('parser-agent', ['read'])->plainTextToken;

        $this->withToken($token)->patchJson("/api/parser-errors/{$error->id}/dismiss")->assertForbidden();

        $this->assertNull($error->refresh()->resolved_at);
    }

    public function test_dismissing_a_missing_error_returns_404(): void
    {
        $token = User::factory()->admin()->create()->createToken('parser-agent', ['errors'])->plainTextToken;

        $this->withToken($token)->patchJson('/api/parser-errors/999999/dismiss')->assertNotFound();
    }

    /**
     * @return \ArrayObject<int, MessageLogged>
     */
    private function captureLogs(): \ArrayObject
    {
        $logged = new \ArrayObject;
        Event::listen(MessageLogged::class, function (MessageLogged $event) use ($logged): void {
            $logged->append($event);
        });

        return $logged;
    }
}
