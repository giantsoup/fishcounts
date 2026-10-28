<?php

namespace Tests\Feature;

use App\Contracts\Parsing\RegulationResearcher;
use App\DTOs\RawPayloadData;
use App\Enums\CatchQuantityBasis;
use App\Models\Boat;
use App\Models\Landing;
use App\Models\RawScrapePayload;
use App\Models\ScrapeSource;
use App\Models\TripType;
use App\Services\Parsing\AiParsedCollectionFactory;
use App\Services\Parsing\AiParserCatalog;
use App\Services\Parsing\LimitsClaimExtractor;
use App\Services\Parsing\LimitsResolver;
use App\Services\Parsing\OpenAiFishCountParser;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('openai-live')]
class LiveLimitsOpenAiTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_luna_high_preserves_limits_trip_types_and_catch_locations(): void
    {
        $key = getenv('FISH_LIVE_OPENAI_API_KEY');
        if (getenv('FISH_RUN_LIVE_OPENAI_TESTS') !== '1' || ! is_string($key) || trim($key) === '') {
            $this->markTestSkipped('Live API check requires explicit opt-in and a process-injected credential.');
        }
        config()->set('services.openai.api_key', $key);
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');
        config()->set('fish.ai_parsing.model', 'gpt-6-luna');
        config()->set('fish.ai_parsing.reasoning_effort', 'high');
        config()->set('fish.ai_parsing.service_tier', 'default');
        config()->set('fish.ai_parsing.timeout_seconds', 120);
        config()->set('fish.ai_parsing.limits.max_output_tokens', 12000);
        unset($key);
        putenv('FISH_LIVE_OPENAI_API_KEY');
        $this->seed(DatabaseSeeder::class);
        $landing = Landing::query()->where('slug', 'fishermans-landing')->firstOrFail();
        foreach (['Dolphin', 'Liberty', 'Pacific Queen', 'Tomahawk', 'Pegasus', 'Apollo', 'Constitution', 'Searcher', 'Excel', 'Condor', 'Polaris Supreme', 'Pacific Dawn'] as $boat) {
            Boat::query()->create(['name' => $boat, 'slug' => str($boat)->slug()->toString(), 'landing_id' => $landing->id]);
        }
        $document = <<<'TEXT'
            [block:0001] The Dolphin caught limits of Calico Bass and 17 Yellowtail on their 3/4 Day trip for 25 anglers.
            [block:0002] The Liberty 1.5 Day trip finished with 16 Bluefin Tuna and limits of reds.
            [block:0003] The Pacific Queen caught limits of Bonito for 20 anglers.
            [block:0004] The Tomahawk caught limits (80) of Calico Bass on their Local Full Day trip for 16 anglers.
            [block:0005] The Pegasus caught limits of Bonito on their Local 3/4 Day trip for 20 anglers.
            [block:0006] The Apollo caught limits of Bonito on their Full Day Coronado Islands trip for 18 anglers.
            [block:0007] The Constitution caught limits of Calico Bass on their Catalina Full Day trip for 10 anglers.
            [block:0008] The Searcher caught limits of Bonito in California waters on their 1.5 Day trip for 16 anglers.
            [block:0009] The Excel caught limits of Calico Bass in Mexican waters on their 1/2 Day trip for 8 anglers.
            [block:0010] The Condor caught limits of Bonito in Mexican waters on their Local Full Day trip for 20 anglers.
            [block:0011] The Polaris Supreme caught limits of Calico Bass in U.S. waters on their 2.5 Day trip for 12 anglers.
            [block:0012] The Pacific Dawn caught limits of Bonito in both Mexican waters and California waters on their 1.5 Day trip for 10 anglers.
            TEXT;
        $catalog = app(AiParserCatalog::class)->active();
        $response = app(OpenAiFishCountParser::class)->parse($document, $catalog, '2026-10-07', 'fish-limits-live-'.str()->uuid());
        $this->assertStringStartsWith('gpt-6-luna', $response->model);
        $source = ScrapeSource::query()->where('slug', 'fishermans_landing')->firstOrFail();
        $payload = new RawScrapePayload(['target_date' => '2026-10-07', 'payload_hash' => hash('sha256', $document)]);
        $payload->setRelation('scrapeSource', $source);
        $raw = new RawPayloadData('fishermans_landing', CarbonImmutable::parse('2026-10-07'), 'https://example.test/synthetic', $document);
        $parsed = app(AiParsedCollectionFactory::class)->make($payload, $raw, $document, $response->result, $catalog);
        $reports = $parsed->tripReports->keyBy('boatName');
        $this->assertCount(12, $reports);
        $calico = collect($reports['Dolphin']->speciesCounts)->firstWhere('speciesName', 'Calico Bass');
        $this->assertNull($calico->count);
        $this->assertSame(CatchQuantityBasis::LimitsUnquantified, $calico->quantityBasis);
        $this->assertSame(17, collect($reports['Dolphin']->speciesCounts)->firstWhere('speciesName', 'Yellowtail')->count);
        $this->assertNull($reports['Liberty']->anglers);
        $this->assertNull(collect($reports['Liberty']->speciesCounts)->firstWhere('speciesName', 'Red Rock Fish')->count);
        $this->assertNull($reports['Pacific Queen']->tripTypeName);
        $this->assertNull($reports['Pacific Queen']->speciesCounts[0]->count);
        $this->assertSame(80, $reports['Tomahawk']->speciesCounts[0]->count);
        $this->assertStringContainsString('Local Full Day', $reports['Tomahawk']->tripTypeName);
        $resolver = new LimitsResolver(app(LimitsClaimExtractor::class), $this->createStub(RegulationResearcher::class));
        $expectedContexts = [
            'Dolphin' => ['mexico', 1],
            'Liberty' => ['mexico', 1],
            'Tomahawk' => ['california_southern', 1],
            'Pegasus' => ['california_southern', 1],
            'Apollo' => ['mexico', 1],
            'Constitution' => ['california_southern', 1],
            'Searcher' => ['california_southern', 1],
            'Excel' => ['mexico', 1],
            'Condor' => ['mexico', 1],
            'Polaris Supreme' => ['california_southern', 2],
        ];
        foreach ($expectedContexts as $boat => [$jurisdiction, $days]) {
            $context = $resolver->context($reports[$boat]);
            $this->assertNotNull($context, $boat.' must retain its trip and location evidence.');
            $this->assertSame($jurisdiction, $context['jurisdiction'], $boat);
            $this->assertSame($days, $context['days'], $boat);
        }
        foreach (['Tomahawk' => 'Full Day', 'Pegasus' => '3/4 Day', 'Apollo' => 'Full Day Coronado Islands', 'Constitution' => 'Full Day', 'Searcher' => '1.5 Day', 'Excel' => '1/2 Day', 'Condor' => 'Full Day', 'Polaris Supreme' => '2.5 Day', 'Pacific Dawn' => '1.5 Day'] as $boat => $canonicalType) {
            $this->assertSame(TripType::query()->where('name', $canonicalType)->value('id'), $reports[$boat]->canonicalTripTypeId, $boat.' must map to the correct canonical trip type.');
        }
        $this->assertNull($resolver->context($reports['Pacific Queen']));
        $this->assertNull($resolver->context($reports['Pacific Dawn']));
        $this->assertStringContainsString('Local', $reports['Pegasus']->tripTypeName);
        foreach (['Pegasus', 'Apollo', 'Constitution', 'Searcher', 'Excel', 'Condor', 'Polaris Supreme', 'Pacific Dawn'] as $boat) {
            $this->assertNull($reports[$boat]->speciesCounts[0]->count, $boat.' must not manufacture a quantity during extraction.');
        }
    }
}
