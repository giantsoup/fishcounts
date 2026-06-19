<?php

namespace Tests\Feature;

use App\DTOs\RawPayloadData;
use App\Enums\ScrapeRunType;
use App\Enums\SourceType;
use App\Models\Boat;
use App\Models\Landing;
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
use App\Services\Parsing\SourceSpecificFishCountParser;
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

    public function test_landing_source_reports_store_source_landing_and_half_day_trip_type(): void
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
            'payload' => '<p>The <strong>Dolphin</strong> AM trip caught 78 Rockfish and 2 Calico Bass for 55 anglers.</p>',
            'payload_hash' => hash('sha256', 'dolphin-am-fixture'),
            'fetched_at' => now(),
        ]);
        $region = Region::query()->create(['name' => 'San Diego', 'slug' => 'san-diego']);
        $landing = Landing::query()->create(['region_id' => $region->id, 'name' => 'Fisherman\'s Landing', 'slug' => 'fishermans-landing']);
        $boat = Boat::query()->create(['name' => 'Dolphin', 'slug' => 'dolphin']);
        $rockfish = Species::query()->create(['name' => 'Rockfish', 'slug' => 'rockfish']);
        SpeciesAlias::query()->create(['species_id' => $rockfish->id, 'alias' => 'Rockfish', 'normalized_alias' => 'rockfish']);
        $calicoBass = Species::query()->create(['name' => 'Calico Bass', 'slug' => 'calico-bass']);
        SpeciesAlias::query()->create(['species_id' => $calicoBass->id, 'alias' => 'Calico Bass', 'normalized_alias' => 'calico bass']);
        $tripType = TripType::query()->create(['name' => '1/2 Day AM', 'slug' => '1-2-day-am']);
        TripTypeAlias::query()->create(['trip_type_id' => $tripType->id, 'alias' => '1/2 Day AM', 'normalized_alias' => '1 2 day am']);

        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: $source->slug,
            targetDate: CarbonImmutable::parse('2026-06-18'),
            url: $payload->url,
            body: $payload->payload,
        ));

        app(TripReportNormalizer::class)->replaceForPayload($payload, $parsed);

        $report = TripReport::query()->firstOrFail();

        $this->assertSame($landing->id, $report->landing_id);
        $this->assertSame($boat->id, $report->boat_id);
        $this->assertSame($tripType->id, $report->trip_type_id);
        $this->assertSame('Fisherman\'s Landing', $report->raw_landing_name);
        $this->assertSame('Dolphin', $report->raw_boat_name);
        $this->assertSame('1/2 Day AM', $report->raw_trip_type);
        $this->assertSame($landing->id, $boat->refresh()->landing_id);
    }

    public function test_dock_total_reports_are_labeled_as_aggregate_rows(): void
    {
        $source = ScrapeSource::query()->create([
            'name' => 'Sportfishing Report Landing Pages',
            'slug' => 'sportfishingreport_landing_pages',
            'source_type' => SourceType::ReportFeed,
            'base_url' => 'https://www.sportfishingreport.com',
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
            'url' => 'https://www.sportfishingreport.com/dock_totals/?date=2026-06-18&region_id=7',
            'payload' => <<<'HTML'
                <div style='background-color: #F3F9FD; padding: 10px; border-top: 1px solid #dedede;'>
                    <div class="row">
                        <div class="col-xs-12 col-md-4"><a href="/landings/seaforth-sportfishing.php">Seaforth Sportfishing</a><br>San Diego, CA<br></div>
                        <div class="col-xs-5 col-md-2 col-md-offset-0 col-md-push-3">9 Boats / 10 Trips</div>
                        <div class="col-xs-4 col-md-2 col-md-push-3">258 Anglers</div>
                        <div class="col-xs-3 col-md-1 col-md-push-3">&nbsp;</div>
                        <div class="col-xs-12 col-md-3 col-md-offset-0 col-md-pull-5"><br>194 Rockfish, 159 Calico Bass, 80 Calico Bass <span style='color: red'>Released</span>, 91 Yellowtail</div>
                    </div>
                </div>
            HTML,
            'payload_hash' => hash('sha256', 'dock-total-sanity-fixture'),
            'fetched_at' => now(),
        ]);
        $region = Region::query()->create(['name' => 'San Diego', 'slug' => 'san-diego']);
        Landing::query()->create(['region_id' => $region->id, 'name' => 'Seaforth Sportfishing', 'slug' => 'seaforth-sportfishing']);
        foreach (['Rockfish', 'Calico Bass', 'Yellowtail'] as $name) {
            $species = Species::query()->create(['name' => $name, 'slug' => str($name)->slug()->toString()]);
            SpeciesAlias::query()->create(['species_id' => $species->id, 'alias' => $name, 'normalized_alias' => str($name)->lower()->squish()->toString()]);
        }

        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: $source->slug,
            targetDate: CarbonImmutable::parse('2026-06-18'),
            url: $payload->url,
            body: $payload->payload,
        ));

        app(TripReportNormalizer::class)->replaceForPayload($payload, $parsed);

        $report = TripReport::query()->firstOrFail();

        $this->assertNull($report->boat_id);
        $this->assertNull($report->trip_type_id);
        $this->assertSame('Dock Total', $report->raw_boat_name);
        $this->assertSame('All Trips', $report->raw_trip_type);
        $this->assertSame(258, $report->anglers);
    }
}
