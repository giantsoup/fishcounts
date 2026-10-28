<?php

$finfish = ['all_finfish' => true, 'daily' => 20, 'weights' => []];
$rcg = ['daily' => 10, 'weights' => ['vermilion_sunset' => 1, 'rockfish' => 1, 'cabezon' => 1, 'greenlings' => 1, 'copper_rockfish' => 1, 'canary_rockfish' => 1]];
$bass = ['daily' => 5, 'weights' => ['kelp_bass' => 1, 'barred_sand_bass' => 1, 'spotted_sand_bass' => 1]];
$mexico = ['all_finfish' => true, 'daily' => 10, 'weights' => ['dorado' => 2.5, 'roosterfish' => 2.5, 'tarpon' => 2.5, 'baya' => 2.5, 'marlin' => 5, 'striped_marlin' => 5, 'blue_marlin' => 5, 'black_marlin' => 5, 'sailfish' => 5, 'swordfish' => 5, 'shark' => 5, 'mako_shark' => 5, 'thresher_shark' => 5, 'giant_sea_bass' => 5]];

return [
    'revision' => 'review-2026-10-27-v1',
    'effective_from' => '2026-10-01',
    'effective_until' => '2026-10-27',
    'mexico' => [
        'kelp_bass' => ['daily' => 5, 'max_days' => 3, 'aggregates' => [$mexico], 'section' => 'NOM-017-PESC-1994 4.9.1, 4.9.3, 4.9.5', 'anchors' => ['4.9.1', '4.9.3', 'diez', 'cinco']],
        'pacific_bonito' => ['daily' => 5, 'max_days' => 3, 'aggregates' => [$mexico], 'section' => 'NOM-017-PESC-1994 4.9.1, 4.9.3, 4.9.5', 'anchors' => ['4.9.1', '4.9.3', 'diez', 'cinco']],
        'yellowtail' => ['daily' => 5, 'max_days' => 3, 'aggregates' => [$mexico], 'section' => 'NOM-017-PESC-1994 4.9.1, 4.9.3, 4.9.5', 'anchors' => ['4.9.1', '4.9.3', 'diez', 'cinco']],
    ],
    'california_southern' => [
        'vermilion_sunset' => ['daily' => 2, 'max_days' => 3, 'aggregates' => [$rcg, $finfish], 'section' => 'CCR Title 14 28.55, 27.15, 27.60', 'anchors' => ['Vermilion', '2 fish per person', '27.15', '27.60'], 'restrictions' => ['Shelf/slope species and seasonal depth restrictions; protected areas are not established by trip type']],
        'kelp_bass' => ['daily' => 5, 'max_days' => 3, 'aggregates' => [$bass, $finfish], 'section' => 'CCR Title 14 28.30, 27.15, 27.60', 'anchors' => ['Kelp Bass', 'five fish in any combination', '27.15'], 'restrictions' => ['14 inches total length or 10 inches alternate length']],
        'pacific_bonito' => ['daily' => 10, 'max_days' => 3, 'aggregates' => [$finfish], 'section' => 'CCR Title 14 28.32, 27.15, 27.60', 'anchors' => ['28.32. Pacific Bonito', 'ten fish', '27.15'], 'restrictions' => ['24 inches fork length or five pounds; five smaller fish within the ten-fish bag']],
    ],
];
