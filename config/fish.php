<?php

return [
    'admin' => [
        'name' => env('FISH_ADMIN_NAME', 'Fish Counts Admin'),
        'email' => env('FISH_ADMIN_EMAIL', 'admin@example.com'),
        'password' => env('FISH_ADMIN_PASSWORD', 'password'),
    ],

    'scraping' => [
        'user_agent' => env('FISH_SCRAPER_USER_AGENT', 'FishCountsBot/1.0 (+https://example.com/contact)'),
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
        'user_agent' => env('FISH_CONDITIONS_USER_AGENT', env('FISH_SCRAPER_USER_AGENT', 'FishCountsBot/1.0 (+https://example.com/contact)')),
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
        ],
        'stations' => [
            'coops_la_jolla' => '9410230',
            'coops_san_diego' => '9410170',
            'ndbc_mission_bay_west' => '46258',
            'cdip_mission_bay_west' => '220p1',
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
