<?php

namespace Tests\Feature\Api;

use App\Models\Boat;
use App\Models\Landing;
use App\Models\Region;
use App\Models\ScrapeSource;
use App\Models\Species;
use App\Models\TripReport;
use App\Models\TripType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TripReportApiTest extends TestCase
{
    use RefreshDatabase;

    private ?string $token = null;

    public function test_lists_trip_reports_with_relations_and_species_counts(): void
    {
        $region = Region::query()->create(['name' => 'San Diego', 'slug' => 'san-diego']);
        $landing = Landing::query()->create(['region_id' => $region->id, 'name' => 'H&M Landing', 'slug' => 'hm-landing']);
        $boat = $this->boat('Fortune', $landing);
        $tripType = TripType::query()->create(['name' => 'Full Day', 'slug' => 'full-day']);
        $yellowtail = Species::query()->create(['name' => 'Yellowtail', 'slug' => 'yellowtail']);
        $report = $this->report(ScrapeSource::factory()->create(), '2026-07-04', $boat, [
            'landing_id' => $landing->id,
            'trip_type_id' => $tripType->id,
            'anglers' => 30,
            'raw_fish_count_text' => '45 Yellowtail',
        ]);
        $report->speciesCounts()->create(['species_id' => $yellowtail->id, 'count' => 45, 'raw_species_name' => 'Yellowtail']);

        $this->withToken($this->readToken())
            ->getJson('/api/trip-reports')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $report->id)
            ->assertJsonPath('data.0.trip_date', '2026-07-04')
            ->assertJsonPath('data.0.anglers', 30)
            ->assertJsonPath('data.0.raw_fish_count_text', '45 Yellowtail')
            ->assertJsonPath('data.0.boat', ['id' => $boat->id, 'name' => 'Fortune'])
            ->assertJsonPath('data.0.landing', ['id' => $landing->id, 'name' => 'H&M Landing'])
            ->assertJsonPath('data.0.trip_type', ['id' => $tripType->id, 'name' => 'Full Day'])
            ->assertJsonPath('data.0.species_counts.0.species', ['id' => $yellowtail->id, 'name' => 'Yellowtail'])
            ->assertJsonPath('data.0.species_counts.0.count', 45)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 50);
    }

    public function test_filters_by_date_range_source_and_boat(): void
    {
        $source = ScrapeSource::factory()->create();
        $otherSource = ScrapeSource::factory()->create();
        $fortune = $this->boat('Fortune');
        $liberty = $this->boat('Liberty');
        $july3 = $this->report($source, '2026-07-03', $fortune);
        $july4 = $this->report($source, '2026-07-04', $liberty);
        $july4Other = $this->report($otherSource, '2026-07-04', $fortune);
        $july6 = $this->report($otherSource, '2026-07-06', $liberty);

        $this->assertListedIds(['date' => '2026-07-04'], [$july4Other->id, $july4->id]);
        $this->assertListedIds(['from' => '2026-07-04', 'to' => '2026-07-05'], [$july4Other->id, $july4->id]);
        $this->assertListedIds(['from' => '2026-07-04'], [$july6->id, $july4Other->id, $july4->id]);
        $this->assertListedIds(['to' => '2026-07-03'], [$july3->id]);
        $this->assertListedIds(['source' => $source->id], [$july4->id, $july3->id]);
        $this->assertListedIds(['boat' => $fortune->id], [$july4Other->id, $july3->id]);
        $this->assertListedIds(['date' => '2026-07-04', 'source' => $otherSource->id, 'boat' => $fortune->id], [$july4Other->id]);
    }

    public function test_paginates_and_caps_per_page(): void
    {
        $source = ScrapeSource::factory()->create();
        foreach (['2026-07-01', '2026-07-02', '2026-07-03'] as $date) {
            $this->report($source, $date);
        }

        $this->withToken($this->readToken())
            ->getJson('/api/trip-reports?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);

        $this->withToken($this->readToken())
            ->getJson('/api/trip-reports?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function invalidFilters(): array
    {
        return [
            'non-date date' => [['date' => 'today'], 'date'],
            'non-date from' => [['from' => '07/01/2026'], 'from'],
            'to before from' => [['from' => '2026-07-10', 'to' => '2026-07-01'], 'to'],
            'non-integer source' => [['source' => 'seaforth'], 'source'],
            'non-integer boat' => [['boat' => '1 or 1=1'], 'boat'],
            'zero per_page' => [['per_page' => '0'], 'per_page'],
        ];
    }

    /**
     * @param  array<string, string>  $query
     */
    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_get_json_422(array $query, string $field): void
    {
        $this->withToken($this->readToken())
            ->getJson('/api/trip-reports?'.http_build_query($query))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    public function test_list_requires_the_read_ability(): void
    {
        $token = User::factory()->admin()->create()->createToken('agent', ['errors'])->plainTextToken;

        $this->withToken($token)->getJson('/api/trip-reports')->assertForbidden();
    }

    private function boat(string $name, ?Landing $landing = null): Boat
    {
        return Boat::query()->create(['landing_id' => $landing?->id, 'name' => $name, 'slug' => Str::slug($name)]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function report(ScrapeSource $source, string $date, ?Boat $boat = null, array $attributes = []): TripReport
    {
        return TripReport::query()->create([
            'source_id' => $source->id,
            'boat_id' => $boat?->id,
            'trip_date' => $date,
            'dedupe_key' => Str::uuid()->toString(),
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, string|int>  $query
     * @param  list<int>  $expectedIds  Newest trip date first, then newest id.
     */
    private function assertListedIds(array $query, array $expectedIds): void
    {
        $this->app['auth']->forgetGuards();

        $ids = $this->withToken($this->readToken())
            ->getJson('/api/trip-reports?'.http_build_query($query))
            ->assertOk()
            ->json('data.*.id');

        $this->assertSame($expectedIds, $ids, http_build_query($query));
    }

    private function readToken(): string
    {
        return $this->token ??= User::factory()->admin()->create()->createToken('agent', ['read'])->plainTextToken;
    }
}
