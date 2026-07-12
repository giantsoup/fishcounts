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

    'ai_review' => [
        'enabled' => (bool) env('FISH_AI_REVIEW_ENABLED', false),
        'dispatch_enabled' => (bool) env('FISH_AI_REVIEW_DISPATCH_ENABLED', false),
        'provider' => env('FISH_AI_REVIEW_PROVIDER', 'openai'),
        'model' => env('FISH_AI_REVIEW_MODEL', 'gpt-5.6-luna'),
        'reasoning_effort' => env('FISH_AI_REVIEW_REASONING_EFFORT', 'medium'),
        'store_provider_response' => false,
        'connect_timeout_seconds' => (int) env('FISH_AI_REVIEW_CONNECT_TIMEOUT', 10),
        'timeout_seconds' => (int) env('FISH_AI_REVIEW_TIMEOUT', 60),
        'prompt_version' => env('FISH_AI_REVIEW_PROMPT_VERSION', 'v1'),
        'schema_version' => env('FISH_AI_REVIEW_SCHEMA_VERSION', 'v1'),
        'limits' => [
            'max_candidates' => (int) env('FISH_AI_REVIEW_MAX_CANDIDATES', 100),
            'max_input_tokens' => (int) env('FISH_AI_REVIEW_MAX_INPUT_TOKENS', 12000),
            'max_output_tokens' => (int) env('FISH_AI_REVIEW_MAX_OUTPUT_TOKENS', 2000),
            'max_corrections' => (int) env('FISH_AI_REVIEW_MAX_CORRECTIONS', 20),
            'max_rationale_length' => (int) env('FISH_AI_REVIEW_MAX_RATIONALE_LENGTH', 2000),
            'max_failure_message_length' => (int) env('FISH_AI_REVIEW_MAX_FAILURE_MESSAGE_LENGTH', 1000),
        ],
        'confidence' => [
            'minimum_actionable' => (float) env('FISH_AI_REVIEW_MINIMUM_ACTIONABLE_CONFIDENCE', 0.75),
            'high' => (float) env('FISH_AI_REVIEW_HIGH_CONFIDENCE', 0.90),
        ],
        'budgets' => [
            'daily_limit_micros' => null,
            'monthly_limit_micros' => (int) env('FISH_AI_REVIEW_MONTHLY_LIMIT_MICROS', 50000000),
            'hard_stop' => true,
            'reservation_ttl_minutes' => (int) env('FISH_AI_REVIEW_RESERVATION_TTL_MINUTES', 15),
        ],
        'retention' => [
            'complete_months' => (int) env('FISH_AI_REVIEW_RETENTION_MONTHS', 3),
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
