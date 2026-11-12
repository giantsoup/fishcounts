<?php

namespace Tests\Feature\Api;

use App\Models\ParserError;
use App\Models\RawScrapePayload;
use App\Models\ScrapeSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReparseApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_preview_returns_the_scope_and_a_64_character_fingerprint(): void
    {
        $source = ScrapeSource::factory()->create();
        $this->openErrorOn($source, '2026-07-10');
        $this->openErrorOn($source, '2026-07-20', structural: true);
        $this->openErrorOn(ScrapeSource::factory()->create(), '2026-07-10');

        $response = $this->withToken($this->token(['reparse']))
            ->getJson('/api/reparse-runs/preview?'.http_build_query(['source' => $source->id, 'from' => '2026-07-01', 'to' => '2026-07-31']))
            ->assertOk()
            ->assertJsonPath('data.open_errors', 2)
            ->assertJsonPath('data.alias_errors', 1)
            ->assertJsonPath('data.payloads', 2)
            ->assertJsonPath('data.dates', 2)
            ->assertJsonPath('data.source', $source->id)
            ->assertJsonPath('data.from', '2026-07-01')
            ->assertJsonPath('data.to', '2026-07-31');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $response->json('data.fingerprint'));
        $this->assertArrayNotHasKey('items', $response->json('data'));
    }

    /**
     * @param  list<string>  $abilities
     */
    private function token(array $abilities): string
    {
        return User::factory()->admin()->create()->createToken('agent', $abilities)->plainTextToken;
    }

    private function openErrorOn(ScrapeSource $source, string $targetDate, bool $structural = false): ParserError
    {
        $factory = ParserError::factory()->for(RawScrapePayload::factory()->for($source)->state(['target_date' => $targetDate]));

        return ($structural ? $factory->structural() : $factory)->create();
    }
}
