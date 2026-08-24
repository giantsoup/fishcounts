<?php

namespace Tests\Feature;

use App\Enums\ParserErrorResolutionType;
use App\Enums\SourceType;
use App\Models\Boat;
use App\Models\Landing;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use App\Models\Region;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Models\Species;
use App\Models\TripType;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_source_operational_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $source = ScrapeSource::query()->create([
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.sources.update', $source), [
                'priority' => 5,
                'rate_limit_seconds' => 30,
                'is_enabled' => '1',
                'supports_historical_dates' => '1',
                'supports_landing_filter' => '1',
                'notes' => 'Verified source.',
            ])
            ->assertRedirect(route('admin.sources.index'));

        $this->assertDatabaseHas('scrape_sources', [
            'id' => $source->id,
            'priority' => 5,
            'rate_limit_seconds' => 30,
            'is_enabled' => true,
            'supports_historical_dates' => true,
            'supports_landing_filter' => true,
            'notes' => 'Verified source.',
        ]);
    }

    public function test_admin_can_update_boat_booking_url(): void
    {
        $admin = User::factory()->admin()->create();
        $region = Region::query()->create(['name' => 'San Diego', 'slug' => 'san-diego']);
        $landing = Landing::query()->create(['region_id' => $region->id, 'name' => 'Fisherman\'s Landing', 'slug' => 'fishermans-landing']);
        $boat = Boat::query()->create(['landing_id' => $landing->id, 'name' => 'Pacific Queen', 'slug' => 'pacific-queen']);

        $this->actingAs($admin)
            ->get(route('admin.boats.index'))
            ->assertOk()
            ->assertSee('Pacific Queen')
            ->assertSee('booking_url', false);

        $this->actingAs($admin)
            ->put(route('admin.boats.update', $boat), [
                'booking_url' => 'https://booking.example.test/pacific-queen',
            ])
            ->assertRedirect(route('admin.boats.index'));

        $this->assertDatabaseHas('boats', [
            'id' => $boat->id,
            'booking_url' => 'https://booking.example.test/pacific-queen',
        ]);
    }

    public function test_boat_booking_url_validation_error_only_renders_on_submitted_boat_row(): void
    {
        $admin = User::factory()->admin()->create();
        $region = Region::query()->create(['name' => 'San Diego', 'slug' => 'san-diego']);
        $landing = Landing::query()->create(['region_id' => $region->id, 'name' => 'Fisherman\'s Landing', 'slug' => 'fishermans-landing']);
        $pacificQueen = Boat::query()->create(['landing_id' => $landing->id, 'name' => 'Pacific Queen', 'slug' => 'pacific-queen']);
        $dolphin = Boat::query()->create([
            'landing_id' => $landing->id,
            'name' => 'Dolphin',
            'slug' => 'dolphin',
            'booking_url' => 'https://booking.example.test/dolphin',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.boats.index'))
            ->put(route('admin.boats.update', $pacificQueen), [
                'boat_id' => $pacificQueen->id,
                'booking_url' => 'not-a-url',
            ])
            ->assertSessionHasErrors('booking_url')
            ->assertRedirect(route('admin.boats.index'));

        $response = $this->actingAs($admin)
            ->get(route('admin.boats.index'))
            ->assertOk()
            ->assertSee('not-a-url')
            ->assertSee('https://booking.example.test/dolphin');

        $this->assertSame(1, substr_count($response->getContent(), 'not-a-url'));
        $this->assertSame('https://booking.example.test/dolphin', $dolphin->fresh()->booking_url);
    }

    public function test_admin_dashboard_distinguishes_parser_warnings_from_scrape_status(): void
    {
        $admin = User::factory()->admin()->create();
        $source = ScrapeSource::query()->create([
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
        ]);
        $scrapeRun = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => 'daily',
            'target_date' => '2026-06-20',
            'status' => 'succeeded',
        ]);
        $payload = RawScrapePayload::query()->create([
            'scrape_run_id' => $scrapeRun->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-06-20',
            'url' => 'https://www.fishermanslanding.com/fishcounts.php',
            'http_status' => 200,
            'payload' => 'fixture',
            'payload_hash' => hash('sha256', 'fixture'),
            'fetched_at' => now(),
        ]);

        ParserError::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-06-20',
            'error_type' => 'unknown_species_alias',
            'raw_field' => 'species',
            'raw_value' => 'Mixed Rockfish',
            'message' => 'Unknown species alias [Mixed Rockfish].',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('succeeded')
            ->assertSee(route('admin.scrape-runs.show', $scrapeRun))
            ->assertSee('1 parser warning needs alias review')
            ->assertSee(route('admin.backfills.index'))
            ->assertSee(route('admin.scrape-runs.index'))
            ->assertSee(route('admin.parser-errors.index'));
    }

    public function test_backfill_form_renders_shared_multi_select_without_fixed_height(): void
    {
        $admin = User::factory()->admin()->create();

        ScrapeSource::query()->create([
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.backfills.create'))
            ->assertOk()
            ->assertSee('name="source_ids[]"', false)
            ->assertSee('data-select-mode="multiple"', false)
            ->assertSee('data-placeholder="Select sources"', false)
            ->assertDontSee('min-h-40', false);
    }

    public function test_admin_can_view_failed_jobs(): void
    {
        $admin = User::factory()->admin()->create();

        DB::table('failed_jobs')->insert([
            'uuid' => 'failed-job-uuid',
            'connection' => 'redis',
            'queue' => 'scraping',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ScrapeSourceForDateJob'], JSON_THROW_ON_ERROR),
            'exception' => "RuntimeException: Failed source\nStack trace",
            'failed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.failed-jobs.index'))
            ->assertOk()
            ->assertSee('App\\Jobs\\ScrapeSourceForDateJob')
            ->assertSee('RuntimeException: Failed source')
            ->assertSee(route('admin.failed-jobs.destroy', 'failed-job-uuid'))
            ->assertSee('data-confirm=', false)
            ->assertSee('name="_token"', false)
            ->assertSee('name="_method" value="DELETE"', false)
            ->assertSee('aria-label="Dismiss failed job failed-job-uuid"', false)
            ->assertSee('Dismiss');
    }

    public function test_admin_can_dismiss_only_the_selected_failed_job(): void
    {
        $admin = User::factory()->admin()->create();

        DB::table('failed_jobs')->insert([
            [
                'uuid' => 'selected-failed-job-uuid',
                'connection' => 'redis',
                'queue' => 'scraping',
                'payload' => json_encode(['displayName' => 'App\\Jobs\\ScrapeSourceForDateJob'], JSON_THROW_ON_ERROR),
                'exception' => 'RuntimeException: Selected failure',
                'failed_at' => now(),
            ],
            [
                'uuid' => 'retained-failed-job-uuid',
                'connection' => 'redis',
                'queue' => 'scraping',
                'payload' => json_encode(['displayName' => 'App\\Jobs\\ScrapeSourceForDateJob'], JSON_THROW_ON_ERROR),
                'exception' => 'RuntimeException: Retained failure',
                'failed_at' => now(),
            ],
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.failed-jobs.destroy', 'selected-failed-job-uuid'))
            ->assertRedirect(route('admin.failed-jobs.index'))
            ->assertSessionHas('status', 'Failed job dismissed.');

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => 'selected-failed-job-uuid']);
        $this->assertDatabaseHas('failed_jobs', ['uuid' => 'retained-failed-job-uuid']);

        $this->actingAs($admin)
            ->delete(route('admin.failed-jobs.destroy', 'selected-failed-job-uuid'))
            ->assertRedirect(route('admin.failed-jobs.index'))
            ->assertSessionHas('status', 'This failed job was already dismissed.');

        $this->assertDatabaseHas('failed_jobs', ['uuid' => 'retained-failed-job-uuid']);
    }

    public function test_dismissing_a_missing_failed_job_is_idempotent(): void
    {
        $admin = User::factory()->admin()->create();

        DB::table('failed_jobs')->insert([
            'uuid' => 'retained-failed-job-uuid',
            'connection' => 'redis',
            'queue' => 'scraping',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ScrapeSourceForDateJob'], JSON_THROW_ON_ERROR),
            'exception' => 'RuntimeException: Retained failure',
            'failed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.failed-jobs.destroy', 'missing-failed-job-uuid'))
            ->assertRedirect(route('admin.failed-jobs.index'))
            ->assertSessionHas('status', 'This failed job was already dismissed.');

        $this->assertDatabaseHas('failed_jobs', ['uuid' => 'retained-failed-job-uuid']);
    }

    public function test_dismissing_a_failed_job_preserves_the_current_page(): void
    {
        $admin = User::factory()->admin()->create();
        $failedJobs = [];

        foreach (range(1, 27) as $index) {
            $failedJobs[] = [
                'uuid' => "paginated-failed-job-{$index}",
                'connection' => 'redis',
                'queue' => 'scraping',
                'payload' => json_encode(['displayName' => 'App\\Jobs\\ScrapeSourceForDateJob'], JSON_THROW_ON_ERROR),
                'exception' => "RuntimeException: Paginated failure {$index}",
                'failed_at' => now()->subMinutes($index),
            ];
        }

        DB::table('failed_jobs')->insert($failedJobs);

        $destroyUrl = route('admin.failed-jobs.destroy', [
            'failedJob' => 'paginated-failed-job-26',
            'page' => 2,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.failed-jobs.index', ['page' => 2]))
            ->assertOk()
            ->assertSee($destroyUrl);

        $this->actingAs($admin)
            ->delete($destroyUrl)
            ->assertRedirect(route('admin.failed-jobs.index', ['page' => 2]))
            ->assertSessionHas('status', 'Failed job dismissed.');

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => 'paginated-failed-job-26']);
        $this->assertDatabaseHas('failed_jobs', ['uuid' => 'paginated-failed-job-27']);
    }

    public function test_normal_user_cannot_dismiss_a_failed_job(): void
    {
        $user = User::factory()->create();

        DB::table('failed_jobs')->insert([
            'uuid' => 'protected-failed-job-uuid',
            'connection' => 'redis',
            'queue' => 'scraping',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ScrapeSourceForDateJob'], JSON_THROW_ON_ERROR),
            'exception' => 'RuntimeException: Protected failure',
            'failed_at' => now(),
        ]);

        $this->actingAs($user)
            ->delete(route('admin.failed-jobs.destroy', 'protected-failed-job-uuid'))
            ->assertForbidden();

        $this->assertDatabaseHas('failed_jobs', ['uuid' => 'protected-failed-job-uuid']);
    }

    public function test_guest_cannot_dismiss_a_failed_job(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => 'guest-protected-failed-job-uuid',
            'connection' => 'redis',
            'queue' => 'scraping',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ScrapeSourceForDateJob'], JSON_THROW_ON_ERROR),
            'exception' => 'RuntimeException: Guest protected failure',
            'failed_at' => now(),
        ]);

        $this->delete(route('admin.failed-jobs.destroy', 'guest-protected-failed-job-uuid'))
            ->assertRedirect(route('login'));

        $this->assertDatabaseHas('failed_jobs', ['uuid' => 'guest-protected-failed-job-uuid']);
    }

    public function test_admin_can_navigate_from_parser_error_to_raw_payload_and_scrape_run(): void
    {
        $admin = User::factory()->admin()->create();
        $source = ScrapeSource::query()->create([
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
        ]);
        $scrapeRun = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => 'daily',
            'target_date' => '2026-06-20',
            'status' => 'succeeded',
        ]);
        $payload = RawScrapePayload::query()->create([
            'scrape_run_id' => $scrapeRun->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-06-20',
            'url' => 'https://www.fishermanslanding.com/fishcounts.php',
            'http_status' => 200,
            'payload' => 'fixture',
            'payload_hash' => hash('sha256', 'fixture'),
            'fetched_at' => now(),
        ]);

        $parserError = ParserError::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-06-20',
            'error_type' => 'unknown_species_alias',
            'raw_field' => 'species',
            'raw_value' => 'Mixed Rockfish',
            'message' => 'Unknown species alias [Mixed Rockfish].',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.parser-errors.index'))
            ->assertOk()
            ->assertSee(route('admin.raw-payloads.show', $payload))
            ->assertSee(route('admin.parser-errors.dismiss', $parserError))
            ->assertSee('Dismiss error');

        $this->actingAs($admin)
            ->get(route('admin.raw-payloads.show', $payload))
            ->assertOk()
            ->assertSee(route('admin.scrape-runs.show', $scrapeRun))
            ->assertSee(route('admin.scrape-runs.index'));
    }

    public function test_parser_error_alias_selects_require_an_explicit_existing_item_selection(): void
    {
        $admin = User::factory()->admin()->create();
        $source = ScrapeSource::query()->create([
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
        ]);

        Boat::query()->create(['name' => 'Dolphin', 'slug' => 'dolphin']);
        Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        TripType::query()->create(['name' => 'Full Day', 'slug' => 'full-day']);

        foreach ([
            ['unknown_boat_alias', 'boat', 'The Dolphin'],
            ['unknown_species_alias', 'species', 'Yellows'],
            ['unknown_trip_type_alias', 'trip_type', 'All Day'],
        ] as [$errorType, $rawField, $rawValue]) {
            ParserError::query()->create([
                'scrape_source_id' => $source->id,
                'target_date' => '2026-07-10',
                'error_type' => $errorType,
                'raw_field' => $rawField,
                'raw_value' => $rawValue,
                'message' => "Unknown {$rawField} alias [{$rawValue}].",
            ]);
        }

        $response = $this->actingAs($admin)
            ->get(route('admin.parser-errors.index'))
            ->assertOk();
        $document = new DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);

        foreach ([
            'boat_id' => 'Select existing boat',
            'species_id' => 'Select existing species',
            'trip_type_id' => 'Select existing trip type',
        ] as $field => $placeholder) {
            $selects = $xpath->query("//select[@name='{$field}']");

            $this->assertNotFalse($selects);
            $this->assertCount(1, $selects);
            $select = $selects->item(0);
            $this->assertInstanceOf(DOMElement::class, $select);
            $this->assertTrue($select->hasAttribute('required'));

            $options = $select->getElementsByTagName('option');
            $this->assertGreaterThan(1, $options->count());
            $placeholderOption = $options->item(0);
            $this->assertInstanceOf(DOMElement::class, $placeholderOption);
            $this->assertSame('', $placeholderOption->getAttribute('value'));
            $this->assertSame($placeholder, str($placeholderOption->textContent)->trim()->toString());

            $selectedCanonicalOptions = $xpath->query("//select[@name='{$field}']/option[@value != '' and @selected]");

            $this->assertNotFalse($selectedCanonicalOptions);
            $this->assertCount(0, $selectedCanonicalOptions);
        }
    }

    public function test_admin_can_dismiss_parser_error_without_creating_an_alias(): void
    {
        $admin = User::factory()->admin()->create();
        $parserError = $this->createParserError();
        $aliasCounts = [
            'boat_aliases' => DB::table('boat_aliases')->count(),
            'species_aliases' => DB::table('species_aliases')->count(),
            'trip_type_aliases' => DB::table('trip_type_aliases')->count(),
        ];

        $this->actingAs($admin)
            ->from(route('admin.parser-errors.index'))
            ->patch(route('admin.parser-errors.dismiss', $parserError))
            ->assertRedirect(route('admin.parser-errors.index'))
            ->assertSessionHas('status', 'Parser error dismissed without creating an alias.');

        $parserError->refresh();

        $this->assertNotNull($parserError->resolved_at);
        $this->assertSame($admin->id, $parserError->resolved_by_user_id);
        $this->assertSame(ParserErrorResolutionType::Dismissed, $parserError->resolution_type);

        foreach ($aliasCounts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count());
        }

        $this->actingAs($admin)
            ->get(route('admin.parser-errors.index'))
            ->assertOk()
            ->assertDontSee($parserError->raw_value);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('parser warning needs alias review');

        $this->actingAs($admin)
            ->get(route('admin.parser-errors.index', ['status' => 'all']))
            ->assertOk()
            ->assertSee($parserError->raw_value)
            ->assertSee('Dismissed')
            ->assertSee("by {$admin->name}")
            ->assertDontSee(route('admin.parser-errors.dismiss', $parserError));
    }

    public function test_non_admin_cannot_dismiss_parser_error(): void
    {
        $user = User::factory()->create();
        $parserError = $this->createParserError();

        $this->patch(route('admin.parser-errors.dismiss', $parserError))
            ->assertRedirect(route('login'));

        $this->actingAs($user)
            ->patch(route('admin.parser-errors.dismiss', $parserError))
            ->assertForbidden();

        $this->assertNull($parserError->fresh()->resolved_at);
    }

    public function test_dismissing_an_already_resolved_parser_error_preserves_its_audit_fields(): void
    {
        $originalAdmin = User::factory()->admin()->create();
        $retryingAdmin = User::factory()->admin()->create();
        $resolvedAt = now()->subHour()->startOfSecond();
        $parserError = $this->createParserError([
            'resolved_at' => $resolvedAt,
            'resolved_by_user_id' => $originalAdmin->id,
            'resolution_type' => ParserErrorResolutionType::Alias,
        ]);

        $this->actingAs($retryingAdmin)
            ->patch(route('admin.parser-errors.dismiss', $parserError))
            ->assertSessionHas('status', 'Parser error was already resolved.');

        $parserError->refresh();

        $this->assertTrue($resolvedAt->equalTo($parserError->resolved_at));
        $this->assertSame($originalAdmin->id, $parserError->resolved_by_user_id);
        $this->assertSame(ParserErrorResolutionType::Alias, $parserError->resolution_type);
    }

    /** @param array<string, mixed> $overrides */
    private function createParserError(array $overrides = []): ParserError
    {
        $source = ScrapeSource::query()->create([
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
        ]);

        return ParserError::query()->create(array_merge([
            'scrape_source_id' => $source->id,
            'target_date' => '2026-07-10',
            'error_type' => 'unknown_species_alias',
            'raw_field' => 'species',
            'raw_value' => 'Baracuda On Their Fullday Trip',
            'message' => 'Unknown species alias [Baracuda On Their Fullday Trip].',
        ], $overrides));
    }
}
