<?php

namespace App\Services\Parsing;

use Illuminate\Support\Str;

class SpeciesCountAssumptions
{
    private const array SPECIES = [
        'Yellowtail', 'Bonito', 'Dorado', 'Halibut', 'Lingcod', 'Sculpin', 'Sheephead',
        'Whitefish', 'Rockfish', 'Barracuda', 'Opah', 'Cabezon', 'Halfmoon', 'Pilotfish',
        'Tuna', 'Bluefin Tuna', 'Yellowfin Tuna', 'Bullet Tuna', 'Skipjack Tuna', 'Albacore',
        'Marlin', 'Striped Marlin', 'Stripped Marlin', 'Blue Marlin', 'Black Marlin',
        'Spearfish', 'Shortbill Spearfish', 'Calico Bass', 'Sand Bass', 'Barred Sand Bass',
        'White Seabass', 'Mako Shark', 'Thresher Shark', 'Squid', 'Giant Squid', 'Pacific Mackerel',
    ];

    public function normalize(string $text): string
    {
        $yellow = $this->yellowSpecies($text);
        $text = preg_replace_callback(
            '/(?<![\\d.#])\\b(?<count>\\d+)\\s+Yellow(?<released>\\s+Released)?(?=\\s*(?:[,.;!]|$)|\\s+(?:and|for|with)\\b)/i',
            fn (array $match): string => $yellow === null ? '' : "{$match['count']} {$yellow}".($match['released'] ?? ''),
            $text,
        ) ?? $text;
        $text = preg_replace('/\\bStripped\\s+Marlin\\b/i', 'Striped Marlin', $text) ?? $text;

        return preg_replace_callback(
            $this->unnumberedSpeciesPattern(),
            fn (array $match): string => "{$match['count']} ".$this->speciesName($match['first']).', 1 '.$this->speciesName($match['second']).($match['released'] ?? ''),
            $text,
        ) ?? $text;
    }

    public function yellowSpecies(string $text): ?string
    {
        $tripPattern = '/\\b(?:(?:AM|PM)\\s+Half\\s+Day|Half\\s+Day(?:\\s+(?:AM|PM))?|(?:1\\/2|3\\/4|\\d+(?:\\.\\d+)?)\\s*Day(?:\\s+(?:AM|PM))?|Full\\s*Day|Overnight|Twilight|AM|PM)\\b/i';
        if (preg_match_all($tripPattern, $text) > 1) {
            return null;
        }

        $text = preg_replace('/\\blimits\\s+of\\s+(Yellowtail|Yellowfin\\s+Tuna)\\s*\\(\\s*(\\d+)\\s*\\)/i', '$2 $1', $text) ?? $text;
        $text = preg_replace('/\\blimits\\s*\\(\\s*(\\d+)\\s*\\)\\s+of\\s+(Yellowtail|Yellowfin\\s+Tuna)\\b/i', '$1 $2', $text) ?? $text;
        $ending = '(?=\\s*(?:[,.;!()]|$)|\\s+(?:and|for|with|released)\\b)';
        $yellowtail = preg_match('/(?<![\\d.#])\\b\\d+\\s+(?:Yellowtail|YT|Yellows|Yelowtail)'.$ending.'/i', $text) === 1;
        $yellowfin = preg_match('/(?<![\\d.#])\\b\\d+\\s+(?:Yellowfin(?:\\s+Tuna)?|YFT)'.$ending.'/i', $text) === 1;

        return $yellowtail === $yellowfin ? null : ($yellowtail ? 'Yellowfin Tuna' : 'Yellowtail');
    }

    public function speciesName(string $name): string
    {
        return Str::of($name)
            ->replaceMatches('/\\b(Yellowtail|Bonito|Marlin|Shark|Dorado)s\\b/i', '$1')
            ->replaceMatches('/\\bStripped\\s+Marlin\\b/i', 'Striped Marlin')
            ->squish()
            ->title()
            ->toString();
    }

    /** @return list<string> */
    public function inferredSpecies(string $text): array
    {
        preg_match_all($this->unnumberedSpeciesPattern(), $text, $matches, PREG_SET_ORDER);
        $names = array_map(fn (array $match): string => $this->speciesName($match['second']), $matches);
        $yellow = $this->yellowSpecies($text);
        if ($yellow !== null && preg_match('/\\b\\d+\\s+Yellow\\b/i', $text) === 1) {
            $names[] = $yellow;
        }

        return array_values(array_unique($names));
    }

    private function unnumberedSpeciesPattern(): string
    {
        $species = '(?:'.implode('|', array_map(fn (string $name): string => preg_quote($name, '/'), self::SPECIES)).')s?';

        return '/(?<![\\d.#])\\b(?<count>\\d+)\\s+(?<first>'.$species.')\\s+and\\s+(?<second>'.$species.')(?<released>\\s+Released)?(?=\\s*(?:[,.;!]|$)|\\s+(?:for|with)\\b)/i';
    }
}
