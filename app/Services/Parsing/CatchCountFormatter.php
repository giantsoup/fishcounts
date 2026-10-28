<?php

namespace App\Services\Parsing;

use App\Models\SpeciesCount;
use App\Models\TripReport;

final class CatchCountFormatter
{
    public function count(SpeciesCount $count): string
    {
        $components = $count->components ?: [[
            'species' => $count->species->name, 'count' => $count->count,
            'released' => $count->released_count, 'limits' => false,
        ]];

        $limits = array_values(array_filter($components, fn (array $component): bool => (bool) ($component['limits'] ?? false)));
        $reported = array_filter($components, fn (array $component): bool => ! ($component['limits'] ?? false));
        if ($reported !== []) {
            array_unshift($limits, ['species' => $count->species->name, 'count' => array_sum(array_column($reported, 'count')), 'released' => array_sum(array_column($reported, 'released')), 'limits' => false]);
        }

        return implode(', ', array_map(fn (array $component): string => $this->component($component, $count->species->name), $limits));
    }

    /** @param array<string, mixed> $component */
    public function component(array $component, ?string $canonicalName = null): string
    {
        $name = ($component['limits'] ?? false) ? $component['species'] : ($canonicalName ?? $component['species']);
        $limitsLabel = 'Limits';
        if (preg_match('/^(near|nearly|almost|partial)\b/i', $component['raw_text'] ?? '', $qualifier) === 1) {
            $limitsLabel = ucfirst(strtolower($qualifier[1])).' Limits';
        }
        $parts = [];
        if ($component['count'] === null) {
            $parts[] = $limitsLabel.' '.$name.(($component['evidence']['released_limits'] ?? false) ? ' Released' : '');
        } elseif ($component['count'] > 0 || ($component['released'] ?? 0) === 0) {
            $parts[] = number_format($component['count']).' '.$name.(($component['limits'] ?? false) ? ' ('.$limitsLabel.')' : '');
        }
        if (($component['released'] ?? 0) > 0) {
            $parts[] = number_format($component['released']).' '.$name.' Released';
        }

        return implode(', ', $parts);
    }

    public function report(TripReport $report, bool $includeUnmappedLimits = true): string
    {
        $parts = $report->speciesCounts->map(fn (SpeciesCount $count): string => $this->count($count))->all();
        foreach ($includeUnmappedLimits ? ($report->metadata['unmapped_limits'] ?? []) : [] as $component) {
            $parts[] = $this->component($component);
        }

        return implode(', ', $parts);
    }

    public function details(TripReport $report): string
    {
        $details = [];
        foreach ($report->speciesCounts as $count) {
            foreach ($count->components ?? [] as $component) {
                if (isset($component['evidence']['corroborated_releases'])) {
                    $details[] = $component['species'].': released count from matching saved report';
                }
                if (($component['basis'] ?? null) === 'limits_estimated') {
                    $details[] = $component['species'].': estimated from limits; '.implode('; ', $component['evidence']['assumptions'] ?? []);
                } elseif (($component['basis'] ?? null) === 'corroborated') {
                    $details[] = $component['species'].': explicit count from matching saved report';
                }
            }
        }

        return implode('. ', $details);
    }
}
