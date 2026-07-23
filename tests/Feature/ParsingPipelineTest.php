<?php

namespace Tests\Feature;

use App\DTOs\ParsedFishCountCollection;
use App\DTOs\ParsedSpeciesCountData;
use App\DTOs\ParsedTripReportData;
use App\DTOs\RawPayloadData;
use App\Enums\ParserErrorResolutionType;
use App\Enums\ScrapeRunType;
use App\Enums\SourceType;
use App\Models\Boat;
use App\Models\BoatAlias;
use App\Models\Landing;
use App\Models\ParserError;
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
use Database\Seeders\LandingSeeder;
use Database\Seeders\RegionSeeder;
use Database\Seeders\SpeciesAliasSeeder;
use Database\Seeders\SpeciesSeeder;
use Database\Seeders\TripTypeAliasSeeder;
use Database\Seeders\TripTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParsingPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_parser_does_not_treat_an_angler_count_before_returned_with_as_a_species(): void
    {
        $counts = app(GenericFishCountParser::class)->parseSpeciesCounts(
            'The Dolphin AM 22 anglers returned with 43 Calico bass (100 released) 7 Sheephead 1 Cabazon 6 White sea bass (released)',
        );

        $this->assertFalse($counts->contains(fn (ParsedSpeciesCountData $count): bool => $count->speciesName === 'Returned With'));
        $this->assertSame(
            ['Calico Bass', 'Sheephead', 'Cabazon', 'White Sea Bass'],
            $counts->pluck('speciesName')->all(),
        );
        $whiteSeaBass = $counts->firstWhere('speciesName', 'White Sea Bass');

        $this->assertNotNull($whiteSeaBass);
        $this->assertSame(0, $whiteSeaBass->count);
        $this->assertSame(6, $whiteSeaBass->releasedCount);
    }

    public function test_parser_attributes_a_parenthetical_released_count_after_a_comma(): void
    {
        $counts = app(GenericFishCountParser::class)->parseSpeciesCounts(
            'The Dolphin PM trip caught 39 Calico Bass, (100 Released), 57 Bonito for 46 anglers.',
        );

        $this->assertSame(['Calico Bass', 'Bonito'], $counts->pluck('speciesName')->all());
        $this->assertSame(39, $counts->first()->count);
        $this->assertSame(100, $counts->first()->releasedCount);
    }

    public function test_parser_attributes_a_released_count_from_a_follow_up_sentence(): void
    {
        $counts = app(GenericFishCountParser::class)->parseSpeciesCounts(
            'The Sea Watch caught 26 Calico Bass. 100 were released.',
        );

        $this->assertSame(['Calico Bass'], $counts->pluck('speciesName')->all());
        $this->assertSame(26, $counts->first()->count);
        $this->assertSame(100, $counts->first()->releasedCount);
    }

    public function test_parser_removes_trip_context_after_a_species_count(): void
    {
        $counts = app(GenericFishCountParser::class)->parseSpeciesCounts(
            'The Liberty caught 1 Yellowtail, 1 Lingcod, 12 Rockfish, and 2 Bonito on their full day trip with 26 anglers.',
        );

        $bonito = $counts->firstWhere('speciesName', 'Bonito');

        $this->assertNotNull($bonito);
        $this->assertSame(2, $bonito->count);
        $this->assertFalse($counts->contains(fn (ParsedSpeciesCountData $count): bool => $count->speciesName === 'Bonito On Their Full Day Trip With'));
    }

    public function test_parser_removes_compact_full_day_trip_context_after_a_species_count(): void
    {
        $compactTripCounts = app(GenericFishCountParser::class)->parseSpeciesCounts(
            '1 Baracuda on their fullday trip.',
        );
        $spacedTripCounts = app(GenericFishCountParser::class)->parseSpeciesCounts(
            '2 Bonito on their full day trip with 26 anglers.',
        );

        $this->assertSame(['Baracuda'], $compactTripCounts->pluck('speciesName')->all());
        $this->assertSame(1, $compactTripCounts->first()->count);
        $this->assertSame(['Bonito'], $spacedTripCounts->pluck('speciesName')->all());
        $this->assertSame(2, $spacedTripCounts->first()->count);
    }

    public function test_parser_does_not_treat_fractional_trip_or_tackle_numbers_as_fish_counts(): void
    {
        $tripCounts = app(GenericFishCountParser::class)->parseSpeciesCounts(
            'The Sea Watch local 3/4 Day trip finished up with 12 Yellowtail, 85 Barracuda, 55 Bonito, and 25 Calico Bass. The Sea Watch is a definite run for their 3/4 day trips through Friday!',
        );
        $tackleCounts = app(GenericFishCountParser::class)->parseSpeciesCounts(
            'The San Diego finished with 21 Yellowtail and 53 Barracuda. Bring an assortment of sinkers with #2 hooks.',
        );

        $this->assertSame(['Yellowtail', 'Barracuda', 'Bonito', 'Calico Bass'], $tripCounts->pluck('speciesName')->all());
        $this->assertFalse($tripCounts->contains(fn (ParsedSpeciesCountData $count): bool => in_array($count->speciesName, ['Day Trip Finished Up With', 'Day Trips Through Friday'], true)));
        $this->assertSame(['Yellowtail', 'Barracuda'], $tackleCounts->pluck('speciesName')->all());
        $this->assertFalse($tackleCounts->contains(fn (ParsedSpeciesCountData $count): bool => $count->speciesName === 'Hooks'));
    }

    public function test_parser_does_not_treat_decimal_trip_durations_or_angler_counts_as_fish_counts(): void
    {
        $oneAndHalfDay = app(GenericFishCountParser::class)->parseSpeciesCounts(
            'The Tribute 1.5 Day trip finished up with 2 Bluefin Tuna and 10 Yellowtail.',
        );
        $twoAndHalfDay = app(GenericFishCountParser::class)->parseSpeciesCounts(
            'The Pacific Dawn returned from a 2.5 Day private charter where 18 anglers caught 25 Bluefin Tuna.',
        );
        $threeAndHalfDay = app(GenericFishCountParser::class)->parseSpeciesCounts(
            'The Polaris Supreme returned from their 3.5 Day trip with 5 Bluefin Tuna.',
        );

        $this->assertSame(['Bluefin Tuna', 'Yellowtail'], $oneAndHalfDay->pluck('speciesName')->all());
        $this->assertSame(['Bluefin Tuna'], $twoAndHalfDay->pluck('speciesName')->all());
        $this->assertSame(25, $twoAndHalfDay->first()->count);
        $this->assertSame(['Bluefin Tuna'], $threeAndHalfDay->pluck('speciesName')->all());
    }

    public function test_parser_removes_duration_before_a_trailing_angler_count(): void
    {
        $counts = app(GenericFishCountParser::class)->parseSpeciesCounts(
            'The Liberty just called in with 70 Bluefin Tuna (up to 200lbs) for their 2.5 day for 24 anglers.',
        );

        $this->assertSame(['Bluefin Tuna'], $counts->pluck('speciesName')->all());
        $this->assertSame(70, $counts->first()->count);
    }

    public function test_parser_removes_day_progress_context_after_a_species_count(): void
    {
        $counts = app(GenericFishCountParser::class)->parseSpeciesCounts(
            'The Constitution just called in with 30 Bluefin Tuna (up to 60 lbs.) for Day 1 of a 3 day trip, with 20 anglers.',
        );

        $this->assertSame(['Bluefin Tuna'], $counts->pluck('speciesName')->all());
        $this->assertSame(30, $counts->first()->count);
    }

    public function test_parser_removes_trailing_half_day_and_multi_day_progress_context(): void
    {
        $halfDayCounts = app(GenericFishCountParser::class)->parseSpeciesCounts(
            'The Dolphin just called in with 48 Calico Bass, 37 Bonito, 34 rockfish, 4 Sheephead, 4 Whitefish, and 4 Yellowtail for their AM half day trip 38 anglers.',
        );
        $multiDayCounts = app(GenericFishCountParser::class)->parseSpeciesCounts(
            'The Constitution called in with LIMITS (60) of Bluefin Tuna (up to 120 lbs.) for 15 anglers for 2 days of their 3 day trip.',
        );

        $this->assertSame(
            ['Calico Bass', 'Bonito', 'Rockfish', 'Sheephead', 'Whitefish', 'Yellowtail'],
            $halfDayCounts->pluck('speciesName')->all(),
        );
        $this->assertSame([48, 37, 34, 4, 4, 4], $halfDayCounts->pluck('count')->all());
        $this->assertSame(['Bluefin Tuna'], $multiDayCounts->pluck('speciesName')->all());
        $this->assertSame(60, $multiDayCounts->first()->count);

        $variants = [
            ['The Dolphin called in with 4 Yellowtail for their 1/2 Day AM trip 20 anglers.', 'Yellowtail', 4],
            ['The Dolphin called in with 4 Yellowtail for their AM half day trip with 20 anglers.', 'Yellowtail', 4],
            ['The Dolphin called in with 4 Yellowtail on their PM half day trip with 20 anglers.', 'Yellowtail', 4],
            ['The Constitution called in with LIMITS (60) of Bluefin Tuna for 2 days of their 3 day trip with 15 anglers.', 'Bluefin Tuna', 60],
        ];

        foreach ($variants as [$line, $species, $expectedCount]) {
            $counts = app(GenericFishCountParser::class)->parseSpeciesCounts($line);

            $this->assertSame([$species], $counts->pluck('speciesName')->all(), $line);
            $this->assertSame($expectedCount, $counts->first()->count, $line);
        }
    }

    public function test_parser_normalizes_hyphenated_half_day_context(): void
    {
        $payload = new RawPayloadData(
            sourceKey: 'fishermans_landing',
            targetDate: CarbonImmutable::parse('2026-07-22'),
            url: 'https://www.fishermanslanding.com/fishcounts.php',
            body: '',
        );
        $cases = [
            ['The Dolphin called in with 4 Yellowtail for their AM half-day trip with 20 anglers.', '1/2 Day AM'],
            ['The Dolphin called in with 4 Yellowtail on their half-day PM trip with 20 anglers.', '1/2 Day PM'],
        ];

        foreach ($cases as [$line, $expectedTripType]) {
            $report = app(GenericFishCountParser::class)->parseLine($payload, $line);

            $this->assertNotNull($report, $line);
            $this->assertSame($expectedTripType, $report->tripTypeName, $line);
            $this->assertSame(['Yellowtail'], collect($report->speciesCounts)->pluck('speciesName')->all(), $line);
            $this->assertSame(4, $report->speciesCounts[0]->count, $line);
        }
    }

    public function test_parser_handles_production_limit_count_placements(): void
    {
        $cases = [
            ['The Pacific Dawn called in with LIMITS (96) of Bluefin Tuna and 1 Yellowfin Tuna.', 'Bluefin Tuna', 96],
            ['The Pegasus returned with LIMITS (76) Bluefin Tuna (80lbs - 150lbs) and 1 @ 200lbs.', 'Bluefin Tuna', 77],
            ['The Aztec finished with limits of nice quality Bluefin(90), 5 Dorado.', 'Bluefin', 90],
            ['The Lucky B caught Limits of Yellowfin Tuna (15), 6 Yellowtail.', 'Yellowfin Tuna', 15],
            ['The Pacific Dawn called in with 26 Dorado (limits), 15 Yellowtail.', 'Dorado', 26],
            ['The Fortune called in with 40 (limits) of Bluefin for 10 anglers.', 'Bluefin', 40],
        ];

        foreach ($cases as [$line, $speciesName, $expectedCount]) {
            $count = app(GenericFishCountParser::class)
                ->parseSpeciesCounts($line)
                ->firstWhere('speciesName', $speciesName);

            $this->assertNotNull($count, $line);
            $this->assertSame($expectedCount, $count->count, $line);
        }
    }

    public function test_parser_handles_production_release_and_weight_annotations(): void
    {
        $releasedCounts = app(GenericFishCountParser::class)->parseSpeciesCounts(
            'The Dolphin caught 44 Calico (Kelp) Bass and released 70, 7 Barracuda and released 75.',
        );
        $limitCounts = app(GenericFishCountParser::class)->parseSpeciesCounts(
            'The Dolphin caught limits of Calico (Kelp) Bass for 22 anglers, so 105 kept in total, and 200 released, 20 Barracuda.',
        );
        $weightCounts = app(GenericFishCountParser::class)->parseSpeciesCounts(
            'The Pacific Queen returned with 31 Bluefin Tuna (70 to 170), and 1 20lbs Yellowtail.',
        );

        $this->assertSame(['Calico Bass', 'Barracuda'], $releasedCounts->pluck('speciesName')->all());
        $this->assertSame([44, 7], $releasedCounts->pluck('count')->all());
        $this->assertSame([70, 75], $releasedCounts->pluck('releasedCount')->all());
        $this->assertSame(105, $limitCounts->first()->count);
        $this->assertSame(200, $limitCounts->first()->releasedCount);
        $this->assertSame(['Bluefin Tuna', 'Yellowtail'], $weightCounts->pluck('speciesName')->all());
        $this->assertSame([31, 1], $weightCounts->pluck('count')->all());
    }

    public function test_parser_keeps_release_and_weight_annotations_with_named_species_separate(): void
    {
        $releasedCounts = app(GenericFishCountParser::class)->parseSpeciesCounts(
            'The Dolphin caught 2 Bluefin Tuna and released 1 Yellowtail for 10 anglers.',
        );
        $weightCounts = app(GenericFishCountParser::class)->parseSpeciesCounts(
            'The Dolphin caught 2 Bluefin Tuna (80lbs) and 1 @ 200lbs Yellowtail.',
        );

        $this->assertSame(['Bluefin Tuna', 'Yellowtail'], $releasedCounts->pluck('speciesName')->all());
        $this->assertSame([2, 0], $releasedCounts->pluck('count')->all());
        $this->assertSame([0, 1], $releasedCounts->pluck('releasedCount')->all());
        $this->assertSame(['Bluefin Tuna', 'Yellowtail'], $weightCounts->pluck('speciesName')->all());
        $this->assertSame([2, 1], $weightCounts->pluck('count')->all());
    }

    public function test_generic_parser_normalizes_non_breaking_spaces_in_narrative_reports(): void
    {
        $parsed = app(GenericFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: 'fishermans_landing',
            targetDate: CarbonImmutable::parse('2026-07-18'),
            url: 'https://www.fishermanslanding.com/fishcounts.php',
            body: '<p>The <strong>Dolphin</strong> 1/2 Day PM caught 84 Rockfish and 2 Yellowtail&nbsp;for 56&nbsp;anglers.</p>',
        ));

        $report = $parsed->tripReports->first();

        $this->assertCount(1, $parsed->tripReports);
        $this->assertSame('The Dolphin', $report->boatName);
        $this->assertSame('1/2 Day PM', $report->tripTypeName);
        $this->assertSame(56, $report->anglers);
        $this->assertSame(['Rockfish', 'Yellowtail'], collect($report->speciesCounts)->pluck('speciesName')->all());
    }

    public function test_reparse_replaces_stale_errors_and_persists_confirmed_counts_and_trip_types(): void
    {
        $this->seed([
            RegionSeeder::class,
            LandingSeeder::class,
            SpeciesSeeder::class,
            SpeciesAliasSeeder::class,
            TripTypeSeeder::class,
            TripTypeAliasSeeder::class,
        ]);

        $source = ScrapeSource::query()->create([
            'name' => "Fisherman's Landing",
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
        ]);
        $run = ScrapeRun::query()->create([
            'scrape_source_id' => $source->id,
            'run_type' => ScrapeRunType::Manual,
            'target_date' => '2026-07-10',
        ]);
        $payload = RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-07-10',
            'url' => 'https://www.fishermanslanding.com/fishcounts.php',
            'payload' => '<p>The Dolphin returned from a 4 Day trip with 2 Bontio, 3 Cabazon, 4 Yelowtail, 6 White sea bass (released), and 1 Baracuda on their fullday trip for 22 anglers.</p>',
            'payload_hash' => hash('sha256', 'corrected-parser-errors'),
            'fetched_at' => now(),
        ]);
        $landing = Landing::query()->where('slug', 'fishermans-landing')->firstOrFail();
        Boat::query()->create(['landing_id' => $landing->id, 'name' => 'Dolphin', 'slug' => 'dolphin']);
        ParserError::query()->create([
            'raw_scrape_payload_id' => $payload->id,
            'scrape_source_id' => $source->id,
            'target_date' => $payload->target_date,
            'error_type' => 'unknown_species_alias',
            'raw_field' => 'species',
            'raw_value' => 'Baracuda',
            'message' => 'Unknown species alias [Baracuda].',
        ]);

        $parsed = app(SourceSpecificFishCountParser::class)->parse(new RawPayloadData(
            sourceKey: $source->slug,
            targetDate: CarbonImmutable::parse($payload->target_date),
            url: $payload->url,
            body: $payload->payload,
        ));

        app(TripReportNormalizer::class)->replaceForPayload($payload, $parsed);

        $report = TripReport::query()->where('raw_scrape_payload_id', $payload->id)->firstOrFail();
        $expectedCounts = [
            'barracuda' => [1, 0],
            'bonito' => [2, 0],
            'cabezon' => [3, 0],
            'yellowtail' => [4, 0],
            'white-seabass' => [0, 6],
        ];

        foreach ($expectedCounts as $speciesSlug => [$retainedCount, $releasedCount]) {
            $species = Species::query()->where('slug', $speciesSlug)->firstOrFail();

            $this->assertDatabaseHas('species_counts', [
                'trip_report_id' => $report->id,
                'species_id' => $species->id,
                'count' => $retainedCount,
                'released_count' => $releasedCount,
            ]);
        }

        $this->assertDatabaseMissing('parser_errors', ['raw_value' => 'Baracuda']);
        $this->assertDatabaseMissing('parser_errors', ['raw_value' => 'Baracuda On Their Fullday Trip']);
        $this->assertDatabaseMissing('parser_errors', ['raw_value' => '4 Day']);
        $this->assertSame('Long Range', $report->tripType?->name);
    }

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
        $dismissedError = ParserError::query()->where('raw_value', 'Calico Bass')->firstOrFail();
        $dismissedAt = now()->startOfSecond();
        $dismissedError->update([
            'resolved_at' => $dismissedAt,
            'resolution_type' => ParserErrorResolutionType::Dismissed,
        ]);

        app(TripReportNormalizer::class)->replaceForPayload($payload, $parsed);

        $this->assertSame(1, $created);
        $this->assertSame(1, TripReport::query()->count());
        $this->assertDatabaseHas('species_counts', [
            'species_id' => $yellowtail->id,
            'count' => 40,
        ]);
        $this->assertDatabaseHas('parser_errors', [
            'id' => $dismissedError->id,
            'error_type' => 'unknown_species_alias',
            'raw_value' => 'Calico Bass',
            'resolved_at' => $dismissedAt,
            'resolution_type' => ParserErrorResolutionType::Dismissed->value,
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

    public function test_boat_alias_resolves_to_canonical_boat_without_creating_a_duplicate(): void
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
            'target_date' => '2026-07-09',
        ]);
        $payload = RawScrapePayload::query()->create([
            'scrape_run_id' => $run->id,
            'scrape_source_id' => $source->id,
            'target_date' => '2026-07-09',
            'url' => 'https://www.fishermanslanding.com/fishcounts.php',
            'payload' => 'fixture',
            'payload_hash' => hash('sha256', 'boat-alias-fixture'),
            'fetched_at' => now(),
        ]);
        $boat = Boat::query()->create(['name' => 'Pacific Queen', 'slug' => 'pacific-queen']);
        BoatAlias::query()->create(['boat_id' => $boat->id, 'alias' => 'The Pacific Queen', 'normalized_alias' => 'the pacific queen']);
        $species = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        SpeciesAlias::query()->create(['species_id' => $species->id, 'alias' => 'Yellowtail', 'normalized_alias' => 'yellowtail']);
        $tripType = TripType::query()->create(['name' => 'Full Day', 'slug' => 'full-day']);
        TripTypeAlias::query()->create(['trip_type_id' => $tripType->id, 'alias' => 'Full Day', 'normalized_alias' => 'full day']);

        $parsed = new ParsedFishCountCollection(collect([
            new ParsedTripReportData(
                sourceKey: $source->slug,
                tripDate: CarbonImmutable::parse('2026-07-09'),
                regionName: 'San Diego',
                landingName: 'Fisherman\'s Landing',
                boatName: 'The Pacific Queen',
                tripTypeName: 'Full Day',
                anglers: 20,
                rawFishCountText: '10 Yellowtail',
                speciesCounts: [new ParsedSpeciesCountData('Yellowtail', 10, 0, '10 Yellowtail')],
                metadata: ['parser' => 'test'],
            ),
        ]));

        app(TripReportNormalizer::class)->replaceForPayload($payload, $parsed);

        $this->assertSame(1, Boat::query()->count());
        $this->assertSame($boat->id, TripReport::query()->firstOrFail()->boat_id);
        $this->assertDatabaseMissing('parser_errors', ['error_type' => 'unknown_boat_alias']);
    }

    public function test_normalizer_skips_parsed_reports_without_individual_boats(): void
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
            'payload' => 'dock total',
            'payload_hash' => hash('sha256', 'parsed-dock-total-fixture'),
            'fetched_at' => now(),
        ]);
        $parsed = new ParsedFishCountCollection(collect([
            new ParsedTripReportData(
                sourceKey: $source->slug,
                tripDate: CarbonImmutable::parse('2026-06-18'),
                regionName: 'San Diego',
                landingName: 'Fisherman\'s Landing',
                boatName: null,
                tripTypeName: null,
                anglers: 115,
                rawFishCountText: '159 Rockfish',
                speciesCounts: [
                    new ParsedSpeciesCountData(speciesName: 'Rockfish', count: 159, rawText: '159 Rockfish'),
                ],
                metadata: ['parser' => 'test-parser'],
            ),
        ]));

        $created = app(TripReportNormalizer::class)->replaceForPayload($payload, $parsed);

        $this->assertSame(0, $created);
        $this->assertSame(0, TripReport::query()->count());
        $this->assertDatabaseCount('species_counts', 0);
    }

    public function test_dock_total_reports_are_omitted_from_parsing_and_storage(): void
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

        $created = app(TripReportNormalizer::class)->replaceForPayload($payload, $parsed);

        $this->assertCount(0, $parsed->tripReports);
        $this->assertSame(0, $created);
        $this->assertSame(0, TripReport::query()->count());
        $this->assertDatabaseCount('species_counts', 0);
    }

    public function test_sportfishing_report_fallback_rows_are_saved_without_direct_landing_match(): void
    {
        $this->seedFallbackReferenceData();

        $source = $this->sportfishingReportSource();
        $payload = $this->payloadForSource($source, '2026-06-17');
        $parsed = $this->parsedDolphinReport($source->slug, '2026-06-17', 19, 25, ['source_role' => 'fallback']);

        $created = app(TripReportNormalizer::class)->replaceForPayload($payload, $parsed);

        $report = TripReport::query()->firstOrFail();

        $this->assertSame(1, $created);
        $this->assertSame($source->id, $report->source_id);
        $this->assertSame(10, $report->source_confidence);
        $this->assertSame('fallback', $report->metadata['source_role']);
        $this->assertDatabaseHas('species_counts', [
            'trip_report_id' => $report->id,
            'count' => 19,
            'released_count' => 50,
        ]);
    }

    public function test_sportfishing_report_fallback_rows_are_skipped_when_direct_landing_match_exists(): void
    {
        $context = $this->seedFallbackReferenceData();
        $directSource = $this->landingSource();

        TripReport::query()->create([
            'source_id' => $directSource->id,
            'region_id' => $context['region']->id,
            'landing_id' => $context['landing']->id,
            'boat_id' => $context['boat']->id,
            'trip_type_id' => $context['tripType']->id,
            'trip_date' => '2026-06-17',
            'anglers' => 55,
            'raw_boat_name' => 'Dolphin',
            'raw_landing_name' => "Fisherman's Landing",
            'raw_trip_type' => '1/2 Day',
            'raw_fish_count_text' => '78 Calico Bass',
            'dedupe_key' => 'direct-dolphin',
            'source_confidence' => 90,
        ]);

        $source = $this->sportfishingReportSource();
        $payload = $this->payloadForSource($source, '2026-06-17');
        $parsed = $this->parsedDolphinReport($source->slug, '2026-06-17', 19, 25, ['source_role' => 'fallback']);

        $created = app(TripReportNormalizer::class)->replaceForPayload($payload, $parsed);

        $this->assertSame(0, $created);
        $this->assertSame(1, TripReport::query()->count());
        $this->assertDatabaseHas('trip_reports', [
            'source_id' => $directSource->id,
            'raw_fish_count_text' => '78 Calico Bass',
        ]);
    }

    public function test_later_direct_landing_import_removes_matching_sportfishing_report_fallback_rows(): void
    {
        $this->seedFallbackReferenceData();

        $fallbackSource = $this->sportfishingReportSource();
        $fallbackPayload = $this->payloadForSource($fallbackSource, '2026-06-17');
        $fallbackParsed = $this->parsedDolphinReport($fallbackSource->slug, '2026-06-17', 19, 25, ['source_role' => 'fallback']);

        app(TripReportNormalizer::class)->replaceForPayload($fallbackPayload, $fallbackParsed);

        $fallbackReportId = TripReport::query()->firstOrFail()->id;
        $directSource = $this->landingSource();
        $directPayload = $this->payloadForSource($directSource, '2026-06-17');
        $directParsed = $this->parsedDolphinReport($directSource->slug, '2026-06-17', 78, 55);

        $created = app(TripReportNormalizer::class)->replaceForPayload($directPayload, $directParsed);

        $report = TripReport::query()->firstOrFail();

        $this->assertSame(1, $created);
        $this->assertSame(1, TripReport::query()->count());
        $this->assertSame($directSource->id, $report->source_id);
        $this->assertSame(55, $report->anglers);
        $this->assertDatabaseMissing('trip_reports', ['source_id' => $fallbackSource->id]);
        $this->assertDatabaseMissing('species_counts', ['trip_report_id' => $fallbackReportId]);
        $this->assertDatabaseHas('species_counts', [
            'trip_report_id' => $report->id,
            'count' => 78,
            'released_count' => 50,
        ]);
    }

    public function test_sportfishing_report_generic_half_day_rows_are_skipped_when_direct_half_day_variant_exists(): void
    {
        $context = $this->seedFallbackReferenceData();
        $directSource = $this->landingSource();

        TripReport::query()->create([
            'source_id' => $directSource->id,
            'region_id' => $context['region']->id,
            'landing_id' => $context['landing']->id,
            'boat_id' => $context['boat']->id,
            'trip_type_id' => $context['halfDayAmTripType']->id,
            'trip_date' => '2026-06-18',
            'anglers' => 55,
            'raw_boat_name' => 'Dolphin',
            'raw_landing_name' => "Fisherman's Landing",
            'raw_trip_type' => '1/2 Day AM',
            'raw_fish_count_text' => '78 Rockfish',
            'dedupe_key' => 'direct-dolphin-am',
            'source_confidence' => 90,
        ]);

        $source = $this->sportfishingReportSource();
        $payload = $this->payloadForSource($source, '2026-06-18');
        $parsed = $this->parsedDolphinReport($source->slug, '2026-06-18', 2, 55, ['source_role' => 'fallback']);

        $created = app(TripReportNormalizer::class)->replaceForPayload($payload, $parsed);

        $this->assertSame(0, $created);
        $this->assertSame(1, TripReport::query()->count());
        $this->assertDatabaseHas('trip_reports', [
            'source_id' => $directSource->id,
            'raw_trip_type' => '1/2 Day AM',
        ]);
    }

    public function test_later_direct_half_day_variant_removes_generic_sportfishing_report_half_day_fallback_rows(): void
    {
        $this->seedFallbackReferenceData();

        $fallbackSource = $this->sportfishingReportSource();
        $fallbackPayload = $this->payloadForSource($fallbackSource, '2026-06-18');
        $fallbackParsed = $this->parsedDolphinReport($fallbackSource->slug, '2026-06-18', 2, 55, ['source_role' => 'fallback']);

        app(TripReportNormalizer::class)->replaceForPayload($fallbackPayload, $fallbackParsed);

        $fallbackReportId = TripReport::query()->firstOrFail()->id;
        $directSource = $this->landingSource();
        $directPayload = $this->payloadForSource($directSource, '2026-06-18');
        $directParsed = $this->parsedDolphinReport(
            sourceKey: $directSource->slug,
            date: '2026-06-18',
            retainedCalicoBass: 78,
            anglers: 55,
            tripTypeName: '1/2 Day AM',
        );

        $created = app(TripReportNormalizer::class)->replaceForPayload($directPayload, $directParsed);

        $report = TripReport::query()->firstOrFail();

        $this->assertSame(1, $created);
        $this->assertSame(1, TripReport::query()->count());
        $this->assertSame($directSource->id, $report->source_id);
        $this->assertSame('1/2 Day AM', $report->raw_trip_type);
        $this->assertDatabaseMissing('trip_reports', ['source_id' => $fallbackSource->id]);
        $this->assertDatabaseMissing('species_counts', ['trip_report_id' => $fallbackReportId]);
    }

    /** @return array{region: Region, landing: Landing, boat: Boat, tripType: TripType, halfDayAmTripType: TripType} */
    private function seedFallbackReferenceData(): array
    {
        $region = Region::query()->create(['name' => 'San Diego', 'slug' => 'san-diego']);
        $landing = Landing::query()->create(['region_id' => $region->id, 'name' => 'Fisherman\'s Landing', 'slug' => 'fishermans-landing']);
        $boat = Boat::query()->create(['landing_id' => $landing->id, 'name' => 'Dolphin', 'slug' => 'dolphin']);
        $tripType = TripType::query()->create(['name' => '1/2 Day', 'slug' => '1-2-day']);
        TripTypeAlias::query()->create(['trip_type_id' => $tripType->id, 'alias' => '1/2 Day', 'normalized_alias' => '1 2 day']);
        $halfDayAmTripType = TripType::query()->create(['name' => '1/2 Day AM', 'slug' => '1-2-day-am']);
        TripTypeAlias::query()->create(['trip_type_id' => $halfDayAmTripType->id, 'alias' => '1/2 Day AM', 'normalized_alias' => '1 2 day am']);

        $calicoBass = Species::query()->create(['name' => 'Calico Bass', 'slug' => 'calico-bass']);
        SpeciesAlias::query()->create(['species_id' => $calicoBass->id, 'alias' => 'Calico Bass', 'normalized_alias' => 'calico bass']);

        return compact('region', 'landing', 'boat', 'tripType', 'halfDayAmTripType');
    }

    private function sportfishingReportSource(): ScrapeSource
    {
        return ScrapeSource::query()->create([
            'name' => 'SportfishingReport Party Boat Scores',
            'slug' => 'sportfishingreport_landing_pages',
            'source_type' => SourceType::Fallback,
            'base_url' => 'https://www.sportfishingreport.com',
            'priority' => 90,
        ]);
    }

    private function landingSource(): ScrapeSource
    {
        return ScrapeSource::query()->create([
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans_landing',
            'source_type' => SourceType::Landing,
            'base_url' => 'https://www.fishermanslanding.com',
            'priority' => 10,
        ]);
    }

    private function payloadForSource(ScrapeSource $source, string $date): RawScrapePayload
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
            'payload' => 'parsed dto fixture',
            'payload_hash' => hash('sha256', $source->slug.$date),
            'fetched_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function parsedDolphinReport(string $sourceKey, string $date, int $retainedCalicoBass, int $anglers, array $metadata = [], string $tripTypeName = '1/2 Day'): ParsedFishCountCollection
    {
        return new ParsedFishCountCollection(collect([
            new ParsedTripReportData(
                sourceKey: $sourceKey,
                tripDate: CarbonImmutable::parse($date),
                regionName: 'San Diego',
                landingName: 'Fisherman\'s Landing',
                boatName: 'Dolphin',
                tripTypeName: $tripTypeName,
                anglers: $anglers,
                rawFishCountText: "{$retainedCalicoBass} Calico Bass, 50 Calico Bass Released",
                speciesCounts: [
                    new ParsedSpeciesCountData(speciesName: 'Calico Bass', count: $retainedCalicoBass, releasedCount: 50, rawText: "{$retainedCalicoBass} Calico Bass"),
                ],
                metadata: array_merge(['parser' => 'test-parser'], $metadata),
            ),
        ]));
    }
}
