<?php

return [
    'enabled' => (bool) env('FISH_LIMITS_ENABLED', true),
    'version' => 'limits-v1',
    'jurisdiction_version' => 'duration-mexico-local-override-v2',
    'duration_version' => 'floor-minimum-one-v1',
    'research_enabled' => (bool) env('FISH_LIMITS_RESEARCH_ENABLED', false),
    'research_version' => 'official-reviewed-v1',
    'research_model' => env('FISH_LIMITS_RESEARCH_MODEL', 'gpt-6-luna'),
    'research_reasoning_effort' => 'high',
    'research_reservation_micros' => 500000,
    'max_response_bytes' => 2_000_000,
    'max_source_characters' => 60000,
    'allowed_hosts' => ['wildlife.ca.gov', 'nrm.dfg.ca.gov', 'fgc.ca.gov', 'dof.gob.mx', 'www.dof.gob.mx', 'platiica.economia.gob.mx', 'www.gob.mx', 'www.fisheries.noaa.gov', 'www.govinfo.gov'],
    'revoked_hashes' => [],
    'exceptions' => [],
    'sources' => [
        'mexico' => [
            'https://dof.gob.mx/nota_detalle_popup.php?codigo=5323155',
            'https://platiica.economia.gob.mx/normalizacion/NOM-017-PESC-1994/',
        ],
        'california_southern' => [
            'https://wildlife.ca.gov/Fishing/Ocean/Regulations/Groundfish-Summary',
            'https://wildlife.ca.gov/Fishing/Ocean/Regulations/Sport-Fishing/General-Ocean-Fishing-Regs',
            'https://wildlife.ca.gov/Fishing/Ocean/Regulations/Fishing-Map/Southern',
        ],
    ],
];
