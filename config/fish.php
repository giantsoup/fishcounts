<?php

$defaultFishCountsUserAgent = 'FishCountsBot/1.0 (+https://fish.tayloroyer.com/contact)';
$scraperUserAgent = (string) env('FISH_SCRAPER_USER_AGENT', $defaultFishCountsUserAgent);

if ($scraperUserAgent === '' || str_contains($scraperUserAgent, 'example.com')) {
    $scraperUserAgent = $defaultFishCountsUserAgent;
}

$conditionsUserAgent = (string) env('FISH_CONDITIONS_USER_AGENT', $scraperUserAgent);

if ($conditionsUserAgent === '' || str_contains($conditionsUserAgent, 'example.com')) {
    $conditionsUserAgent = $scraperUserAgent;
}

return [
    'admin' => [
        'name' => env('FISH_ADMIN_NAME', 'Fish Counts Admin'),
        'email' => env('FISH_ADMIN_EMAIL', 'admin@example.com'),
        'password' => env('FISH_ADMIN_PASSWORD', 'password'),
    ],

    'scraping' => [
        'user_agent' => $scraperUserAgent,
        'timeout_seconds' => (int) env('FISH_SCRAPER_TIMEOUT', 20),
        'connect_timeout_seconds' => (int) env('FISH_SCRAPER_CONNECT_TIMEOUT', 10),
        'allowed_hosts' => [
            'www.sandiegofishreports.com',
            'sandiegofishreports.com',
            'www.fishermanslanding.com',
            'fishermanslanding.com',
            'www.seaforthlanding.com',
            'seaforthlanding.com',
            'www.hmlanding.com',
            'hmlanding.com',
            'www.fishcounts.com',
            'fishcounts.com',
            'www.pointlomasportfishing.com',
            'pointlomasportfishing.com',
            'www.sportfishingreport.com',
            'sportfishingreport.com',
        ],
    ],

    'conditions' => [
        'location_profile' => env('FISH_CONDITIONS_LOCATION_PROFILE', 'san_diego_bight'),
        'timezone' => env('FISH_CONDITIONS_TIMEZONE', 'America/Los_Angeles'),
        'latitude' => (float) env('FISH_CONDITIONS_LATITUDE', 32.75),
        'longitude' => (float) env('FISH_CONDITIONS_LONGITUDE', -117.25),
        'user_agent' => $conditionsUserAgent,
        'timeout_seconds' => (int) env('FISH_CONDITIONS_TIMEOUT', 20),
        'connect_timeout_seconds' => (int) env('FISH_CONDITIONS_CONNECT_TIMEOUT', 10),
        'allowed_hosts' => [
            'aa.usno.navy.mil',
            'api.tidesandcurrents.noaa.gov',
            'www.ndbc.noaa.gov',
            'thredds.cdip.ucsd.edu',
        ],
        'sources' => [
            'usno_moon',
            'noaa_coops_la_jolla',
            'noaa_coops_san_diego',
            'ndbc_mission_bay_west',
            'cdip_mission_bay_west',
            'usno_moon_coronado_islands',
            'ndbc_point_loma_south',
            'cdip_point_loma_south',
        ],
        'profiles' => [
            'san_diego_bight' => [
                'label' => 'San Diego',
                'location_type' => 'local',
                'latitude' => (float) env('FISH_CONDITIONS_LATITUDE', 32.75),
                'longitude' => (float) env('FISH_CONDITIONS_LONGITUDE', -117.25),
                'sources' => [
                    'usno_moon',
                    'noaa_coops_la_jolla',
                    'noaa_coops_san_diego',
                    'ndbc_mission_bay_west',
                    'cdip_mission_bay_west',
                ],
            ],
            'coronado_islands' => [
                'label' => 'Coronado Islands (Mexico)',
                'location_type' => 'islands',
                'source_note' => 'Marine readings use the Point Loma South offshore stations as the nearest official proxy.',
                'latitude' => (float) env('FISH_CONDITIONS_CORONADO_LATITUDE', 32.52),
                'longitude' => (float) env('FISH_CONDITIONS_CORONADO_LONGITUDE', -117.43),
                'sources' => [
                    'usno_moon_coronado_islands',
                    'ndbc_point_loma_south',
                    'cdip_point_loma_south',
                ],
            ],
        ],
        'stations' => [
            'coops_la_jolla' => '9410230',
            'coops_san_diego' => '9410170',
            'ndbc_mission_bay_west' => '46258',
            'cdip_mission_bay_west' => '220p1',
            'ndbc_point_loma_south' => '46232',
            'cdip_point_loma_south' => '191p1',
        ],
    ],

    'parsing' => [
        'diagnostics' => [
            'suspicious_enabled' => (bool) env('FISH_SUSPICIOUS_PARSE_DIAGNOSTICS', false),
            'max_paragraph_length' => (int) env('FISH_DIAGNOSTIC_PARAGRAPH_MAX_LENGTH', 2000),
            'max_entity_name_length' => 60,
            'structured_source_keys' => [
                'hm_landing',
                'point_loma_sportfishing',
                'sandiego_fish_reports',
                'sportfishingreport_landing_pages',
            ],
            'source_specific_result_evidence' => [
                'fishermans_landing' => 'narrative_report_paragraph',
                'seaforth_landing' => 'narrative_report_paragraph',
                'hm_landing' => 'structured_report_row',
                'point_loma_sportfishing' => 'structured_report_row',
                'sportfishingreport_landing_pages' => 'party_boat_score_row',
            ],
        ],
    ],

    'scoring' => [
        'targets' => [
            'yellowtail' => [
                'count_full_score' => 100,
                'count_per_angler_full_score' => 1.0,
                'boat_breadth_full_score' => 5,
                'landing_breadth_full_score' => 3,
            ],
            'default' => [
                'count_full_score' => 50,
                'count_per_angler_full_score' => 0.5,
                'boat_breadth_full_score' => 4,
                'landing_breadth_full_score' => 2,
            ],
        ],
    ],
];
