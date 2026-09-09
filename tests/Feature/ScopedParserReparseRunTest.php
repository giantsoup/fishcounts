<?php

namespace Tests\Feature;

use App\Actions\Parsing\RefreshParserReparseRunProgress;
use App\Actions\Parsing\StartParserReparseRun;
use App\Enums\ParserReparseItemStatus;
use App\Enums\ParserReparseRunStatus;
use App\Enums\ScrapeRunType;
use App\Enums\SourceType;
use App\Models\ParserError;
use App\Models\ParserReparseRun;
use App\Models\RawScrapePayload;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Models\TripReport;
use App\Models\User;
use App\Services\Parsing\ParserReparsePlanner;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScopedParserReparseRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_is_read_only_and_includes_only_the_selected_source_and_dates(): void
    {
        $admin = User::factory()->admin()->create();
        $source = $this->source();
        $selected = $this->payload($source, '2026-08-30', 'selected');
        $outsideDate = $this->payload($source, '2026-08-29', 'outside date');
        $outsideSource = $this->payload($this->source('other'), '2026-08-30', 'outside source');
        foreach ([$selected, $outsideDate, $outsideSource] as $payload) {
            $this->parserError($payload, 'unknown_species_alias');
        }
        TripReport::query()->create([
            'source_id' => $source->id,
            'raw_scrape_payload_id' => $selected->id,
            'trip_date' => '2026-08-30',
            'raw_boat_name' => 'Thu, Jan 1',
            'dedupe_key' => 'bogus-test-trip',
        ]);
        Queue::fake();
        $response = $this->actingAs($admin)->get(route('admin.parser-errors.reparse-runs.preview', [
            'preview' => 1, 'source_id' => $source->id, 'from' => '2026-08-30', 'to' => '2026-08-30',
        ]));
        $response->assertOk()->assertSee('1 stored trips will be reevaluated.');
        $plan = $response->viewData('plan');
        $this->assertSame(1, $plan['open_errors']);
        $this->assertSame([$selected->id], $plan['items']->pluck('raw_scrape_payload_id')->all());
        $this->assertDatabaseCount('parser_reparse_runs', 0);
        $this->assertDatabaseCount('parser_errors', 3);
        Queue::assertNothingPushed();
    }

    public function test_scoped_submission_and_remaining_counts_exclude_unrelated_errors(): void
    {
        $admin = User::factory()->admin()->create();
        $source = $this->source();
        $selected = $this->payload($source, '2026-08-30', 'selected');
        $this->parserError($selected, 'unknown_species_alias');
        $this->parserError($this->payload($this->source('other'), '2026-08-30', 'other'), 'unknown_boat_alias');
        $this->parserError($this->payload($source, '2026-08-29', 'earlier'), 'unknown_boat_alias');
        Queue::fake();
        $plan = app(ParserReparsePlanner::class)->preview($source->id, '2026-08-30', '2026-08-30');
        $this->actingAs($admin)->post(route('admin.parser-errors.reparse-runs.store'), [
            'source_id' => $source->id, 'from' => '2026-08-30', 'to' => '2026-08-30', 'fingerprint' => $plan['fingerprint'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $run = ParserReparseRun::query()->sole();
        $this->assertSame([$selected->id], $run->items()->pluck('raw_scrape_payload_id')->all());
        $this->assertSame(1, $run->initial_open_errors);
        $run->items()->update(['status' => ParserReparseItemStatus::Succeeded, 'date_deduplicated_at' => now()]);
        $run = app(RefreshParserReparseRunProgress::class)->handle($run->id);
        $this->assertSame(ParserReparseRunStatus::Succeeded, $run->status);
        $this->assertSame(1, $run->remaining_open_errors);
        $this->assertDatabaseCount('parser_errors', 3);
    }

    public function test_new_authoritative_payload_requires_a_fresh_preview(): void
    {
        $admin = User::factory()->admin()->create();
        $source = $this->source();
        $old = $this->payload($source, '2026-08-30', 'old', now()->subMinute());
        $this->parserError($old, 'unknown_species_alias');
        $plan = app(ParserReparsePlanner::class)->preview($source->id);
        $new = $this->payload($source, '2026-08-30', 'new');
        Queue::fake();
        $this->actingAs($admin)->post(route('admin.parser-errors.reparse-runs.store'), [
            'source_id' => $source->id, 'fingerprint' => $plan['fingerprint'],
        ])->assertSessionHasErrors('fingerprint');
        $this->assertDatabaseCount('parser_reparse_runs', 0);
        Queue::assertNothingPushed();
        $newPlan = app(ParserReparsePlanner::class)->preview($source->id);
        $this->assertSame([$old->id, $new->id], $newPlan['items']->pluck('raw_scrape_payload_id')->all());
    }

    public function test_payload_changes_and_filter_changes_invalidate_the_preview(): void
    {
        $admin = User::factory()->admin()->create();
        $source = $this->source();
        $payload = $this->payload($source, '2026-08-30', 'old');
        $this->parserError($payload, 'unknown_species_alias');
        $plan = app(ParserReparsePlanner::class)->preview($source->id);
        $payload->update(['payload' => 'changed', 'payload_hash' => hash('sha256', 'changed')]);
        Queue::fake();
        $this->actingAs($admin)->post(route('admin.parser-errors.reparse-runs.store'), [
            'source_id' => $source->id, 'fingerprint' => $plan['fingerprint'],
        ])->assertSessionHasErrors('fingerprint');
        $newPlan = app(ParserReparsePlanner::class)->preview($source->id);
        $this->actingAs($admin)->post(route('admin.parser-errors.reparse-runs.store'), [
            'fingerprint' => $newPlan['fingerprint'],
        ])->assertSessionHasErrors('fingerprint');
        $this->assertDatabaseCount('parser_reparse_runs', 0);
    }

    public function test_preview_and_submission_validate_scope_and_authorization(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.parser-errors.reparse-runs.preview', [
            'source_id' => 999999, 'from' => '2026-09-01', 'to' => '2026-08-01',
        ]))->assertSessionHasErrors(['source_id', 'to']);
        $this->actingAs($admin)->post(route('admin.parser-errors.reparse-runs.store'), [
            'from' => 'yesterday',
        ])->assertSessionHasErrors(['from', 'fingerprint']);
        $this->actingAs(User::factory()->create())->get(route('admin.parser-errors.reparse-runs.preview'))->assertForbidden();
        $this->assertDatabaseCount('parser_reparse_runs', 0);
    }

    public function test_empty_preview_cannot_queue_a_batch_from_the_page(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.parser-errors.reparse-runs.preview', ['preview' => 1]))
            ->assertOk()->assertSee('No saved payloads match this selection.')
            ->assertDontSee('name="fingerprint"', false);
    }

    public function test_orphan_errors_are_reported_as_skipped_and_not_counted_as_repaired(): void
    {
        $source = $this->source();
        $payload = $this->payload($source, '2026-08-30', 'selected');
        $this->parserError($payload, 'unknown_species_alias');
        foreach (['2026-08-30', '2026-08-31'] as $date) {
            ParserError::query()->create(['scrape_source_id' => $source->id, 'target_date' => $date, 'error_type' => 'orphan', 'message' => 'Missing saved payload']);
        }
        $plan = app(ParserReparsePlanner::class)->preview($source->id);
        $this->assertSame(1, $plan['open_errors']);
        $this->assertSame(2, $plan['skipped_errors']);
        Queue::fake();
        $run = app(StartParserReparseRun::class)->handle(User::factory()->admin()->create(), $source->id)->run;
        $run->items()->update(['status' => ParserReparseItemStatus::Succeeded, 'date_deduplicated_at' => now()]);
        $this->assertSame(1, app(RefreshParserReparseRunProgress::class)->handle($run->id)->remaining_open_errors);
        $this->assertDatabaseCount('parser_errors', 3);
    }

    public function test_preview_discloses_other_source_trips_in_date_deduplication(): void
    {
        $source = $this->source();
        $payload = $this->payload($source, '2026-08-30', 'selected');
        $this->parserError($payload, 'unknown_species_alias');
        $other = $this->source('other');
        TripReport::query()->create(['source_id' => $other->id, 'trip_date' => '2026-08-30', 'raw_boat_name' => 'Other boat', 'dedupe_key' => 'other-boat']);
        $response = $this->actingAs(User::factory()->admin()->create())->get(route('admin.parser-errors.reparse-runs.preview', ['preview' => 1, 'source_id' => $source->id]));
        $response->assertOk()->assertSee('1 trip from other sources on these dates will participate in deduplication.');
        $this->assertSame(0, $response->viewData('plan')['trips']);
        $this->assertSame(1, $response->viewData('plan')['related_trips']);
    }

    public function test_progress_polling_does_not_consume_the_batch_start_rate_limit(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = $this->payload($this->source(), '2026-08-30', 'selected');
        $this->parserError($payload, 'unknown_species_alias');
        $finished = ParserReparseRun::query()->create(['status' => ParserReparseRunStatus::Succeeded]);
        Queue::fake();
        for ($poll = 0; $poll < 4; $poll++) {
            $this->actingAs($admin)->getJson(route('admin.parser-errors.reparse-runs.poll', $finished))->assertOk();
        }
        $plan = app(ParserReparsePlanner::class)->preview();
        for ($submission = 0; $submission < 3; $submission++) {
            $this->actingAs($admin)->post(route('admin.parser-errors.reparse-runs.store'), ['fingerprint' => $plan['fingerprint']])
                ->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->actingAs($admin)->post(route('admin.parser-errors.reparse-runs.store'), ['fingerprint' => $plan['fingerprint']])->assertStatus(429);
        $this->assertSame(1, ParserReparseRun::query()->where('status', ParserReparseRunStatus::Pending)->count());
    }

    private function source(string $slug = 'test_parser_reparse_source'): ScrapeSource
    {
        return ScrapeSource::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name' => str($slug)->headline(),
                'source_type' => SourceType::Landing,
                'base_url' => 'https://example.test/'.$slug,
            ],
        );
    }

    private function payload(ScrapeSource $source, string $date, string $body, DateTimeInterface|string|null $fetchedAt = null): RawScrapePayload
    {
        $run = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => ScrapeRunType::Manual,
            'target_date' => $date,
        ]);

        return RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id,
            'scrape_source_id' => $source->id,
            'target_date' => $date,
            'url' => $source->base_url,
            'payload' => $body,
            'payload_hash' => hash('sha256', $body),
            'fetched_at' => $fetchedAt ?? now(),
        ]);
    }

    private function parserError(RawScrapePayload $payload, string $type): ParserError
    {
        return ParserError::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'scrape_source_id' => $payload->scrape_source_id,
            'target_date' => $payload->target_date,
            'error_type' => $type,
            'raw_field' => 'payload',
            'raw_value' => $payload->payload,
            'message' => str($type)->headline(),
            'diagnostic_fingerprint' => hash('sha256', $payload->id.$type),
        ]);
    }
}
