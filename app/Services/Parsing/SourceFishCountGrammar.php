<?php

namespace App\Services\Parsing;

use Illuminate\Support\Str;

class SourceFishCountGrammar
{
    public function normalize(string $line): string
    {
        $line = Str::of($line)
            ->replace("\u{00A0}", ' ')
            ->replace("\u{200B}", '')
            ->replaceMatches('/\bBluefun\s+Tuna\b/i', 'Bluefin Tuna')
            ->replaceMatches('/\bFullday\b/i', 'Full Day')
            ->replaceMatches('/\s*\(\s*(?:up\s+to\s+)?\d+(?:\s*(?:-|to)\s*\d+)?\s*#\s*\)/i', '')
            ->replaceMatches('/\s*\(\s*fishing\s+[A-Za-z .-]+\)/i', '')
            ->replaceMatches('/\s+with\s+\d+\s+between\s+\d+\s*(?:-|to)\s*\d+\s*(?:lbs?|pounds?)\b/i', '')
            ->replaceMatches('/\s+for\s+\(\s*\d+(?:\s*(?:-|to)\s*\d+)?\s*(?:lbs?|pounds?)\.?\s*\)/i', '')
            ->replaceMatches('/\s+on\s+the\s+boat(?=[.!]|$)/i', '')
            ->replaceMatches('/\bCalico\s*\(\s*Kelp\s*\)\s*Bass\b/i', 'Calico Bass')
            ->replaceMatches('/\bfore\s+their\b/i', 'for their')
            ->replaceMatches('/\bfo\s+their\b/i', 'for their')
            ->replaceMatches('/\babd\b/i', 'and')
            ->replaceMatches('/\bans(?=\d)/i', 'and ')
            ->replaceMatches('/\breturened\b/i', 'returned')
            ->replaceMatches('/\bdo\s+far\b/i', 'so far')
            ->replaceMatches('/\b([AP])\s+M\b/i', fn (array $matches): string => Str::upper($matches[1]).'M')
            ->replaceMatches('/(?<=\d\s)quality\s+(?=[A-Za-z])/i', '')
            ->replaceMatches('/\s+so\s+far(?:\s+still\s+fishing)?(?=\s+(?:on|for|with)\b|[.!]|$)/i', '')
            ->replaceMatches('/\s+to\s+start\s+the\s+trip(?=[.!]|$)/i', '')
            ->replaceMatches('/\(\s*\d+\s*@\s*\d+\s+to\s+\d+#\s*\)/i', '')
            ->replaceMatches('/\s+and\s+hooked\s+many\s+more\b/i', '')
            ->toString();

        $line = preg_replace_callback(
            '/(?<retained>\d+)\s+(?<species>[A-Za-z][A-Za-z .\'-]{2,40}?)\s*\(\s*(?<released>\d+)\s+(?<released_species>[A-Za-z][A-Za-z .\'-]{2,40}?)\s+released\s*\)/i',
            fn (array $matches): string => "{$matches['retained']} {$matches['species']}, {$matches['released']} {$matches['released_species']} Released",
            $line,
        ) ?? $line;

        $line = preg_replace_callback(
            '/\blimits\s+of\s+(?<species>[A-Za-z][A-Za-z .\'-]{2,40}?)\s+for\s+(?<anglers>\d+)\s+(?:anglers?|people|passengers?),?\s+so\s+(?<retained>\d+)\s+kept\s+in\s+total,?\s+(?:and\s+)?(?<released>\d+)\s+released\b/i',
            fn (array $matches): string => "{$matches['retained']} {$matches['species']} ({$matches['released']} released) for {$matches['anglers']} anglers",
            $line,
        ) ?? $line;

        $line = preg_replace_callback(
            '/\blimits\s+of\s+(?:nice\s+quality\s+)?(?<species>[A-Za-z][A-Za-z .\'-]{2,40}?)\s*\(\s*(?<count>\d+)\s*\)/i',
            fn (array $matches): string => "{$matches['count']} {$matches['species']}",
            $line,
        ) ?? $line;

        $line = preg_replace_callback(
            '/\blimits\s*\(\s*(?<count>\d+)\s*\)\s*of\s+/i',
            fn (array $matches): string => "{$matches['count']} ",
            $line,
        ) ?? $line;

        $line = preg_replace_callback(
            '/\blimits\s*\(\s*(?<count>\d+)\s*\)\s*/i',
            fn (array $matches): string => "{$matches['count']} ",
            $line,
        ) ?? $line;

        $line = preg_replace('/\s*\(\s*limits\s*\)\s*(?:of\s+)?/i', ' ', $line) ?? $line;

        $line = preg_replace_callback(
            '/\band\s+released\s+(?<released>\d+)\s+(?<species>[A-Za-z][A-Za-z .\'-]{2,40}?)(?=\s*(?:[,.;!]|$)|\s+(?:for|with)\s+\d+\s+(?:anglers?|people|passengers?)\b)/i',
            fn (array $matches): string => ", {$matches['released']} {$matches['species']} Released",
            $line,
        ) ?? $line;

        $line = preg_replace_callback(
            '/(?<retained>\d+)\s+(?<species>[A-Za-z][A-Za-z .\'-]{2,40}?)\s+and\s+released\s+(?<released>\d+)\b(?=\s*(?:[,.;!]|$)|\s+(?:for|with)\s+\d+\s+(?:anglers?|people|passengers?)\b)/i',
            fn (array $matches): string => "{$matches['retained']} {$matches['species']} ({$matches['released']} released)",
            $line,
        ) ?? $line;

        $line = preg_replace_callback(
            '/(?<retained>\d+)\s+(?<species>[A-Za-z][A-Za-z .\'-]{2,40}?)\s+with\s+(?<released>\d+)\s+released\b/i',
            fn (array $matches): string => "{$matches['retained']} {$matches['species']} ({$matches['released']} released)",
            $line,
        ) ?? $line;

        $line = preg_replace_callback(
            '/\band\s+(?<count>\d+)\s*@\s*\d+\s*(?:lbs?|pounds?)\s+(?<species>[A-Za-z][A-Za-z .\'-]{2,40}?)(?=\s*(?:[,.;!]|$)|\s+(?:for|with)\s+\d+\s+(?:anglers?|people|passengers?)\b)/i',
            fn (array $matches): string => ", {$matches['count']} {$matches['species']}",
            $line,
        ) ?? $line;

        $line = preg_replace_callback(
            '/(?<count>\d+)\s+(?<species>[A-Za-z][A-Za-z .\'-]{2,40}?)\s*\([^)]*(?:lbs?|pounds?)\b[^)]*\)\s+and\s+(?<additional>\d+)\s*@\s*\d+\s*(?:lbs?|pounds?)\b(?=\s*(?:[,.;!]|$)|\s+and\s+\d+\s+[A-Za-z]|\s+(?:for|with)\s+\d+\s+(?:anglers?|people|passengers?)\b)/i',
            fn (array $matches): string => ((int) $matches['count'] + (int) $matches['additional'])." {$matches['species']}",
            $line,
        ) ?? $line;

        return preg_replace_callback(
            '/(?<count>\d+)\s+\d+\s*(?:lbs?|pounds?)\s+(?<species>[A-Za-z][A-Za-z .\'-]{2,40}?)(?=\s*(?:,|\.|!|$)|\s+for\b)/i',
            fn (array $matches): string => "{$matches['count']} {$matches['species']}",
            $line,
        ) ?? $line;
    }
}
