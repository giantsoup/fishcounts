<?php

namespace App\Services\Notifications;

use Illuminate\Support\Str;

class SourceHighlightLinkBuilder
{
    public function build(?string $sourceUrl, ?string $boatName, ?string $targetCountText = null): ?string
    {
        if (! filled($sourceUrl)) {
            return null;
        }

        $highlightText = $this->highlightText($sourceUrl, $boatName, $targetCountText);

        if ($highlightText === null) {
            return $sourceUrl;
        }

        return Str::before($sourceUrl, '#').'#:~:text='.$this->encode($highlightText);
    }

    private function fragmentText(?string $text): ?string
    {
        if (! filled($text)) {
            return null;
        }

        return Str::of($text)
            ->replace("\u{00A0}", ' ')
            ->squish()
            ->toString();
    }

    private function highlightText(string $sourceUrl, ?string $boatName, ?string $targetCountText): ?string
    {
        $targetCount = $this->fragmentText($targetCountText);

        if ($targetCount !== null && $this->isSportfishingReportBoatScoresUrl($sourceUrl)) {
            return $targetCount;
        }

        $boatText = $this->fragmentText($boatName);

        if ($boatText === null) {
            return null;
        }

        return $boatText;
    }

    private function encode(string $text): string
    {
        return rawurlencode($text);
    }

    private function isSportfishingReportBoatScoresUrl(string $sourceUrl): bool
    {
        return Str::of($sourceUrl)
            ->lower()
            ->contains('sportfishingreport.com/dock_totals/boats.php');
    }
}
