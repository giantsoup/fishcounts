<?php

namespace Database\Seeders;

use App\Enums\SourceType;
use App\Models\ScrapeSource;
use Illuminate\Database\Seeder;

class ScrapeSourceSeeder extends Seeder
{
    public function run(): void
    {
        $sources = [
            ['San Diego Fish Reports', 'sandiego_fish_reports', SourceType::Aggregator, 'https://www.sandiegofishreports.com', 20, true],
            ["Fisherman's Landing", 'fishermans_landing', SourceType::Landing, 'https://www.fishermanslanding.com', 10, true],
            ['Seaforth Sportfishing', 'seaforth_landing', SourceType::Landing, 'https://www.seaforthlanding.com', 10, true],
            ['H&M Landing', 'hm_landing', SourceType::Landing, 'https://www.hmlanding.com', 10, true],
            ['Point Loma Sportfishing', 'point_loma_sportfishing', SourceType::Landing, 'https://www.pointlomasportfishing.com', 10, true],
            ['SportfishingReport Party Boat Scores', 'sportfishingreport_landing_pages', SourceType::Fallback, 'https://www.sportfishingreport.com', 90, true],
        ];

        foreach ($sources as [$name, $slug, $type, $baseUrl, $priority, $enabled]) {
            ScrapeSource::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'source_type' => $type,
                    'base_url' => $baseUrl,
                    'priority' => $priority,
                    'is_enabled' => $enabled,
                    'supports_historical_dates' => true,
                    'rate_limit_seconds' => 10,
                ],
            );
        }
    }
}
