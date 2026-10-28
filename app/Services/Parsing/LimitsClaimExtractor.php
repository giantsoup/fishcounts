<?php

namespace App\Services\Parsing;

use App\DTOs\ParsedSpeciesCountData;
use App\Enums\CatchQuantityBasis;
use Illuminate\Support\Str;

final class LimitsClaimExtractor
{
    /** @return list<ParsedSpeciesCountData> */
    public function extract(string $text): array
    {
        preg_match_all('/\b(?<qualifier>(?:(?:near|nearly|almost|partial)\s+)?(?:boat\s+)?)limits\s+(?:of\s+)?(?<species>[A-Za-z][A-Za-z .\'-]{1,60}?)(?=\s*(?:[,;.!()]|$)|\s+(?:and|on|for|with|in|off|at|near|around|from|today|yesterday|aboard|released|returned)\b)/iu', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $claims = [];
        foreach ($matches as $match) {
            $name = trim($match['species'][0]);
            $tail = substr($text, $match[0][1] + strlen($match[0][0]), 100);
            if (preg_match('/^\s*\(\s*\d+\s*\)/', $tail) === 1) {
                continue;
            }
            $name = preg_match('/^(?:reds?|red\s+rock\s*(?:fish|cod))$/i', $name) === 1 ? 'Red Rock Fish' : Str::title($name);
            preg_match('/^\s+for\s+(?:only\s+)?(?<count>\d+)\s+anglers?\b/i', $tail, $subset);
            $full = preg_match('/near|almost|partial/i', $match['qualifier'][0]) !== 1
                && preg_match('/^\s+for\s+(?:(?:only\s+)?(?:some|a few)\s+anglers?)/i', $tail) !== 1
                && preg_match('/^\s+(?:released|returned)\b/i', $tail) !== 1;
            $claims[] = new ParsedSpeciesCountData(
                speciesName: $name,
                count: null,
                rawText: trim($match[0][0]),
                quantityBasis: CatchQuantityBasis::LimitsUnquantified,
                isLimits: true,
                evidence: ['full_limits' => $full, 'raw_species' => trim($match['species'][0]), 'claim' => trim($match[0][0]), 'source_block' => $text, 'subset_anglers' => isset($subset['count']) ? (int) $subset['count'] : null, 'released_limits' => preg_match('/^\s+(?:released|returned)\b/i', $tail) === 1],
            );
        }

        return $claims;
    }

    public function identity(string $name): string
    {
        $name = Str::lower(trim($name));

        return match ($name) {
            'reds', 'red', 'red rock fish', 'red rockfish', 'red rock cod', 'vermilion rockfish', 'vermilion rock fish', 'sunset rockfish' => 'vermilion_sunset',
            'calico bass', 'kelp bass' => 'kelp_bass',
            'sand bass', 'barred sand bass' => 'barred_sand_bass',
            'spotted sand bass' => 'spotted_sand_bass',
            'bonito', 'pacific bonito' => 'pacific_bonito',
            default => Str::snake(Str::title($name)),
        };
    }

    public function hasExplicitRetainedZero(string $text, string $species): bool
    {
        return preg_match('/\\b0\\s+'.preg_quote($species, '/').'\\b(?!\\s*(?:\\(\\s*)?released\\b)/i', $text) === 1;
    }

    public function hasExplicitReleasedZero(string $text, string $species): bool
    {
        return preg_match('/\\b0\\s+'.preg_quote($species, '/').'\\s*(?:\\(\\s*)?released\\b/i', $text) === 1;
    }

    public function hasExplicitLimits(string $text, string $species): bool
    {
        $patterns = [
            '/\blimits\s*\(\s*\d+\s*\)\s*(?:of\s+)?(?<species>[A-Za-z][A-Za-z .\'-]{1,60}?)(?=\s*(?:[,;.!()]|$)|\s+(?:and|on|for|with|in|off|at|near|around)\b)/iu',
            '/\blimits\s+(?:of\s+)?(?<species>[A-Za-z][A-Za-z .\'-]{1,60}?)\s*\(\s*\d+\s*\)/iu',
            '/\b\d+\s+(?<species>[A-Za-z][A-Za-z .\'-]{1,60}?)\s*\(\s*limits\s*\)/iu',
        ];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $text, $matches);
            foreach ($matches['species'] as $name) {
                if ($this->identity($name) === $this->identity($species)) {
                    return true;
                }
            }
        }

        return false;
    }
}
