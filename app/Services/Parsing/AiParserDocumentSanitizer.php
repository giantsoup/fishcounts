<?php

namespace App\Services\Parsing;

use App\DTOs\RawPayloadData;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;
use UnexpectedValueException;

final class AiParserDocumentSanitizer
{
    public function __construct(private readonly SourceFishCountDocumentScope $documentScope) {}

    /** @param array<string, mixed> $catalog */
    public function sanitize(RawPayloadData $payload, array $catalog = []): string
    {
        $scopedBody = $this->documentScope->forPayload($payload);
        $body = preg_replace(
            '#<(script|style|head|header|nav|form|noscript|svg|iframe|footer|aside)\b[^>]*>.*?</\1>#is',
            ' ',
            $scopedBody,
        ) ?? $scopedBody;
        $body = $this->fishCountBlocks($body, $catalog, $payload->sourceKey);
        $body = preg_replace(
            '/("?(?:api[_ -]?key|authorization|cookie|password|token)"?\s*:\s*)"[^"]*"/iu',
            '$1"[redacted]"',
            $body,
        ) ?? $body;
        $body = preg_replace('/(?:api[_ -]?key|authorization|cookie|password|token)\s*[:=]\s*\S+/iu', '[redacted]', $body) ?? $body;
        $lines = collect(preg_split('/\R/u', $body) ?: [])
            ->map(fn (string $line): string => trim((string) preg_replace('/[^\S\t]+/u', ' ', $line)))
            ->filter()
            ->values();
        $sanitized = $lines->implode("\n");

        if ($sanitized === '') {
            throw new UnexpectedValueException('The scraped payload contained no public fish-count text.');
        }

        $maximumBytes = max(1, (int) config('fish.ai_parsing.limits.max_input_tokens'));
        if (strlen($sanitized) > $maximumBytes) {
            throw new UnexpectedValueException('The sanitized payload exceeds the configured AI parser input limit.');
        }

        return $sanitized;
    }

    /** @param array<string, mixed> $catalog */
    private function fishCountBlocks(string $body, array $catalog, string $sourceKey): string
    {
        if (! str_contains($body, '<')) {
            return html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $previousErrors = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $isCompleteDocument = preg_match('/<!doctype\s+html|<html\b|<body\b/i', $body) === 1;
        $loaded = $document->loadHTML($isCompleteDocument
            ? $body
            : '<!DOCTYPE html><html><body><div id="ai-parser-root">'.$body.'</div></body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        if (! $loaded) {
            throw new UnexpectedValueException('The scraped payload could not be sanitized safely.');
        }

        $catalogTerms = collect($catalog)
            ->flatten(1)
            ->filter(fn (mixed $entity): bool => is_array($entity))
            ->flatMap(fn (array $entity): array => array_merge(
                isset($entity['name']) ? [(string) $entity['name']] : [],
                is_array($entity['aliases'] ?? null) ? $entity['aliases'] : [],
            ))
            ->map(fn (string $term): string => $this->normalize($term))
            ->filter(fn (string $term): bool => Str::length($term) >= 3)
            ->unique()
            ->values()
            ->all();
        $xpath = new DOMXPath($document);
        $root = $isCompleteDocument ? '//body' : '//*[@id="ai-parser-root"]';
        if ($sourceKey === 'hm_landing') {
            $blocks = $this->hmLandingRows($xpath, $root, $catalogTerms);

            if ($blocks === []) {
                throw new UnexpectedValueException('The scraped payload contained no public fish-count text.');
            }

            return $this->identifiedBlocks($blocks);
        }
        if ($sourceKey === 'sportfishingreport_landing_pages') {
            $blocks = $this->sportfishingReportRows($xpath, $root, $catalogTerms);

            if ($blocks === []) {
                throw new UnexpectedValueException('The scraped payload contained no public fish-count text.');
            }

            return $this->identifiedBlocks($blocks);
        }

        $nodes = $xpath->query(
            $root.'//tr'
            .' | '.$root.'//p'
            .' | '.$root.'//li'
            .' | '.$root.'//div[not(.//p or .//li or .//tr or .//div)]'
            .' | '.$root.'//section[not(.//p or .//li or .//tr or .//section)]'
        );
        $blocks = [];

        if ($nodes !== false) {
            foreach ($nodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                $text = strtolower($node->tagName) === 'tr'
                    ? $this->tableRowText($node)
                    : $this->cleanText($node->textContent);
                if ($this->isFishCountCandidate($text, $catalogTerms, strtolower($node->tagName) === 'tr')) {
                    $blocks[] = $text;
                }
            }
        }

        $blocks = array_values(array_unique(array_filter($blocks)));
        if ($blocks === []) {
            throw new UnexpectedValueException('The scraped payload contained no public fish-count text.');
        }

        return $this->identifiedBlocks($blocks);
    }

    /**
     * @param  list<string>  $catalogTerms
     * @return list<string>
     */
    private function hmLandingRows(DOMXPath $xpath, string $root, array $catalogTerms): array
    {
        $rows = $xpath->query($root.'//tr');
        $blocks = [];

        if ($rows === false) {
            return [];
        }

        foreach ($rows as $row) {
            if (! $row instanceof DOMElement) {
                continue;
            }

            $cells = [];
            foreach ($row->childNodes as $child) {
                if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['td', 'th'], true)) {
                    $cells[] = $this->cleanText($child->textContent);
                }
            }

            $text = implode("\t", array_filter($cells));
            if (count($cells) >= 4 && $this->isFishCountCandidate($text, $catalogTerms, true)) {
                $blocks[] = $text;
            }
        }

        return array_values($blocks);
    }

    /**
     * @param  list<string>  $catalogTerms
     * @return list<string>
     */
    private function sportfishingReportRows(DOMXPath $xpath, string $root, array $catalogTerms): array
    {
        $rows = $xpath->query($root.'//div[contains(concat(" ", normalize-space(@class), " "), " row ")]');
        $blocks = [];

        if ($rows === false) {
            return [];
        }

        foreach ($rows as $row) {
            if (! $row instanceof DOMElement) {
                continue;
            }

            $cells = [];
            foreach ($row->childNodes as $child) {
                if ($child instanceof DOMElement && strtolower($child->tagName) === 'div') {
                    $cells[] = $this->cleanElementText($child);
                }
            }

            $cells = array_values(array_filter($cells, fn (string $cell): bool => $cell !== ''));
            $text = implode("\t", $cells);
            if (count($cells) >= 4 && $this->isFishCountCandidate($text, $catalogTerms, true)) {
                $blocks[] = $text;
            }
        }

        return array_values($blocks);
    }

    private function cleanElementText(DOMElement $element): string
    {
        $html = $element->ownerDocument?->saveHTML($element) ?: $element->textContent;
        $html = preg_replace('#<br\b[^>]*>#i', ' | ', $html) ?? $html;

        return $this->cleanText(strip_tags($html));
    }

    /** @param list<string> $blocks */
    private function identifiedBlocks(array $blocks): string
    {
        return collect($blocks)
            ->values()
            ->map(fn (string $block, int $index): string => sprintf('[block:%04d] %s', $index + 1, $block))
            ->implode("\n");
    }

    private function tableRowText(DOMElement $row): string
    {
        $cells = [];

        foreach ($row->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['td', 'th'], true)) {
                $cells[] = $this->cleanText($child->textContent);
            }
        }

        return implode("\t", array_filter($cells));
    }

    private function cleanText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /** @param list<string> $catalogTerms */
    private function isFishCountCandidate(string $text, array $catalogTerms, bool $tableRow): bool
    {
        if ($text === '' || preg_match('/\d/u', $text) !== 1) {
            return false;
        }

        if ($tableRow) {
            return true;
        }

        $normalized = $this->normalize($text);
        if (preg_match('/\b(angler|passenger|caught|catch|released|returned|trip|fish|count|boat|landing)\w*\b/u', $normalized) === 1) {
            return true;
        }

        return collect($catalogTerms)->contains(
            fn (string $term): bool => str_contains(" {$normalized} ", " {$term} "),
        );
    }

    private function normalize(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }
}
