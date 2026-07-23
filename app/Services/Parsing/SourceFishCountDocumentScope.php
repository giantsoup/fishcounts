<?php

namespace App\Services\Parsing;

use App\DTOs\RawPayloadData;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Throwable;

final class SourceFishCountDocumentScope
{
    public function forPayload(RawPayloadData $payload): string
    {
        return match ($payload->sourceKey) {
            'fishermans_landing' => $this->fishermansLandingTargetDateHtml($payload->body, $payload->targetDate) ?? '',
            'hm_landing' => $this->hmLandingTargetDateHtml($payload->body, $payload->targetDate) ?? '',
            'sportfishingreport_landing_pages' => $this->sportfishingReportSanDiegoPanelHtml($payload->body) ?? '',
            default => $payload->body,
        };
    }

    public function sportfishingReportSanDiegoPanelHtml(string $html): ?string
    {
        $document = $this->document($html);
        if (! $document instanceof DOMDocument) {
            return null;
        }

        $xpath = new DOMXPath($document);
        $panels = $xpath->query(
            '//div[contains(concat(" ", normalize-space(@class), " "), " panel ")]'
            .'[./h2[normalize-space(.) = "San Diego Fish Counts"]]',
        );
        $panel = $panels?->item(0);
        if (! $panel instanceof DOMElement) {
            return null;
        }

        $unsafeNodes = $xpath->query(
            './/script | .//style | .//template | .//noscript | .//iframe | .//form | .//comment()',
            $panel,
        );
        if ($unsafeNodes !== false) {
            $nodesToRemove = [];
            foreach ($unsafeNodes as $unsafeNode) {
                $nodesToRemove[] = $unsafeNode;
            }
            foreach ($nodesToRemove as $unsafeNode) {
                $unsafeNode->parentNode?->removeChild($unsafeNode);
            }
        }

        $panelHtml = '';
        foreach ($panel->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'h2') {
                continue;
            }
            if ($child instanceof DOMNode) {
                $panelHtml .= $document->saveHTML($child);
            }
        }

        return trim($panelHtml) === '' ? null : $panelHtml;
    }

    private function hmLandingTargetDateHtml(string $html, CarbonImmutable $targetDate): ?string
    {
        $document = $this->document($html);
        if (! $document instanceof DOMDocument) {
            return null;
        }

        $xpath = new DOMXPath($document);
        $dateRows = $xpath->query('//tr[td[contains(concat(" ", normalize-space(@class), " "), " HMFishCountBreak ")]]');
        if ($dateRows === false) {
            return null;
        }
        if ($dateRows->length === 0) {
            return null;
        }

        foreach ($dateRows as $dateRow) {
            if (! $dateRow instanceof DOMElement || ! $this->matchesDate($dateRow->textContent, $targetDate)) {
                continue;
            }

            $rows = [$document->saveHTML($dateRow)];
            for ($row = $dateRow->nextSibling; $row !== null; $row = $row->nextSibling) {
                if (! $row instanceof DOMElement || strtolower($row->tagName) !== 'tr') {
                    continue;
                }
                if ($this->isHmDateRow($row)) {
                    break;
                }

                $rows[] = $document->saveHTML($row);
            }

            return '<table><tbody>'.implode('', $rows).'</tbody></table>';
        }

        return null;
    }

    private function fishermansLandingTargetDateHtml(string $html, CarbonImmutable $targetDate): ?string
    {
        $document = $this->document($html);
        if (! $document instanceof DOMDocument) {
            return null;
        }

        $datePattern = sprintf(
            '/(?<!\d)(?:%s|%s)(?!\d)/',
            preg_quote($targetDate->format('n/j/Y'), '/'),
            preg_quote($targetDate->format('m/d/Y'), '/'),
        );
        $xpath = new DOMXPath($document);
        $paragraphs = $xpath->query('//p');
        if ($paragraphs === false) {
            return null;
        }

        foreach ($paragraphs as $paragraph) {
            if (! $paragraph instanceof DOMElement || preg_match($datePattern, $paragraph->textContent) !== 1) {
                continue;
            }

            $paragraphHtml = '';
            foreach ($paragraph->childNodes as $child) {
                $paragraphHtml .= $document->saveHTML($child);
            }

            if (preg_match($datePattern, $paragraphHtml, $targetMatch, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $targetOffset = $targetMatch[0][1];
            $targetLength = strlen($targetMatch[0][0]);
            $targetSection = substr($paragraphHtml, $targetOffset + $targetLength);
            if (preg_match('/(?<!\d)\d{1,2}\/\d{1,2}\/\d{4}(?!\d)/', $targetSection, $nextDate, PREG_OFFSET_CAPTURE) === 1) {
                $targetSection = substr($targetSection, 0, $nextDate[0][1]);
            }

            return '<p>'.$targetMatch[0][0].$targetSection.'</p>';
        }

        return preg_match('/(?<!\d)\d{1,2}\/\d{1,2}\/\d{4}(?!\d)/', $html) === 1 ? null : $html;
    }

    private function document(string $html): ?DOMDocument
    {
        $previousErrors = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        return $loaded ? $document : null;
    }

    private function matchesDate(string $heading, CarbonImmutable $targetDate): bool
    {
        $normalized = preg_replace('/(?<=\d)(?:st|nd|rd|th)\b/i', '', trim($heading)) ?? trim($heading);

        try {
            return CarbonImmutable::parse($normalized)->isSameDay($targetDate);
        } catch (Throwable) {
            return false;
        }
    }

    private function isHmDateRow(DOMElement $row): bool
    {
        foreach ($row->getElementsByTagName('td') as $cell) {
            if (preg_match('/(?:^|\s)HMFishCountBreak(?:\s|$)/', $cell->getAttribute('class')) === 1) {
                return true;
            }
        }

        return false;
    }
}
