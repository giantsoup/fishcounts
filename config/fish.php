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
            'www.976-tuna.com',
            '976-tuna.com',
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
