<?php

namespace Tests\Feature;

use App\DTOs\RawPayloadData;
use App\Enums\ScrapeRunType;
use App\Enums\SourceType;
use App\Models\RawScrapePayload;
use App\Models\Region;
use App\Models\ScrapeRun;
use App\Models\ScrapeSource;
use App\Models\Species;
use App\Models\SpeciesAlias;
use App\Models\TripReport;
use App\Models\TripType;
use App\Models\TripTypeAlias;
use App\Services\Parsing\GenericFishCountParser;
use App\Services\Parsing\TripReportNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParsingPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_parser_normalizes_payload_and_replaces_existing_reports_idempotently(): void
    {
        $source = ScrapeSource::query()->create([
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
        ]);
        $run = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => ScrapeRunType::Manual,
            'target_date' => '2026-01-05',
        ]);
        $payload = RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-01-05',
            'url' => 'https://www.fishermanslanding.com/fish-counts.php?date=2026-01-05',
            'payload' => '<p>Dolphin Full Day 20 anglers 40 Yellowtail, 5 Calico Bass</p>',
            'payload_hash' => hash('sha256', 'fixture'),
            'fetched_at' => now(),
        ]);
        $yellowtail = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        SpeciesAlias::query()->create(['species_id' => $yellowtail->id, 'alias' => 'Yellowtail', 'normalized_alias' => 'yellowtail']);
        $tripType = TripType::query()->create(['name' => 'Full Day', 'slug' => 'full-day']);
        TripTypeAlias::query()->create(['trip_type_id' => $tripType->id, 'alias' => 'Full Day', 'normalized_alias' => 'full day']);
        Region::query()->create(['name' => 'San Diego', 'slug' => 'san-diego']);

        $parsed = app(GenericFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: $source->slug,
            targetDate: CarbonImmutable::parse('2026-01-05'),
            url: $payload->url,
            body: $payload->payload,
        ));

        $created = app(TripReportNormalizer::class)->replaceForPayload($payload, $parsed);
        app(TripReportNormalizer::class)->replaceForPayload($payload, $parsed);

        $this->assertSame(1, $created);
        $this->assertSame(1, TripReport::query()->count());
        $this->assertDatabaseHas('species_counts', [
            'species_id' => $yellowtail->id,
            'count' => 40,
        ]);
        $this->assertDatabaseHas('parser_errors', [
            'error_type' => 'unknown_species_alias',
            'raw_value' => 'Calico Bass',
        ]);
    }

    public function test_normalizer_aggregates_multiple_aliases_for_the_same_species_in_one_report(): void
    {
        $source = ScrapeSource::query()->create([
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
        ]);
        $run = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => ScrapeRunType::Manual,
            'target_date' => '2026-06-18',
        ]);
        $payload = RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-06-18',
            'url' => 'https://www.fishermanslanding.com/fishcounts.php',
            'payload' => '<p>The Poseidon called in with 151 Misc. Rockfish, and 71 Vermillion Rockfish for 23 anglers on a 2 day trip.</p>',
            'payload_hash' => hash('sha256', 'rockfish-fixture'),
            'fetched_at' => now(),
        ]);
        $rockfish = Species::query()->create(['name' => 'Rockfish', 'slug' => 'rockfish']);
        SpeciesAlias::query()->create(['species_id' => $rockfish->id, 'alias' => 'Misc Rockfish', 'normalized_alias' => 'misc rockfish']);
        SpeciesAlias::query()->create(['species_id' => $rockfish->id, 'alias' => 'Vermillion Rockfish', 'normalized_alias' => 'vermillion rockfish']);
        $tripType = TripType::query()->create(['name' => '2 Day', 'slug' => '2-day']);
        TripTypeAlias::query()->create(['trip_type_id' => $tripType->id, 'alias' => '2 Day', 'normalized_alias' => '2 day']);
        Region::query()->create(['name' => 'San Diego', 'slug' => 'san-diego']);

        $parsed = app(GenericFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: $source->slug,
            targetDate: CarbonImmutable::parse('2026-06-18'),
            url: $payload->url,
            body: $payload->payload,
        ));

        app(TripReportNormalizer::class)->replaceForPayload($payload, $parsed);

        $this->assertSame(1, TripReport::query()->count());
        $this->assertDatabaseHas('species_counts', [
            'species_id' => $rockfish->id,
            'count' => 222,
            'raw_species_name' => 'Misc Rockfish, Vermillion Rockfish',
        ]);
    }
}
