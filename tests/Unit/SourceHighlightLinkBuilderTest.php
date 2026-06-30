<?php

namespace Tests\Unit;

use App\Services\Notifications\SourceHighlightLinkBuilder;
use PHPUnit\Framework\TestCase;

class SourceHighlightLinkBuilderTest extends TestCase
{
    public function test_it_appends_text_fragment_after_query_string(): void
    {
        $url = (new SourceHighlightLinkBuilder)->build(
            'https://www.sportfishingreport.com/dock_totals/boats.php?date=2026-06-28',
            'Mission Belle',
            '14 Yellowtail',
        );

        $this->assertSame(
            'https://www.sportfishingreport.com/dock_totals/boats.php?date=2026-06-28#:~:text=14%20Yellowtail',
            $url,
        );
    }

    public function test_it_keeps_non_sportfishing_report_fragments_boat_specific(): void
    {
        $url = (new SourceHighlightLinkBuilder)->build(
            'https://example.test/fishcounts.php?date=2026-06-28',
            'New Lo-An',
            '60 Yellowtail',
        );

        $this->assertSame(
            'https://example.test/fishcounts.php?date=2026-06-28#:~:text=New%20Lo-An',
            $url,
        );
    }

    public function test_it_encodes_fragment_text_and_strips_existing_fragment(): void
    {
        $url = (new SourceHighlightLinkBuilder)->build(
            'https://example.test/fishcounts.php?date=2026-06-28#old-section',
            'New Lo-An',
        );

        $this->assertSame(
            'https://example.test/fishcounts.php?date=2026-06-28#:~:text=New%20Lo-An',
            $url,
        );
    }

    public function test_it_falls_back_to_boat_text_for_sportfishing_report_when_target_count_text_is_missing(): void
    {
        $url = (new SourceHighlightLinkBuilder)->build(
            'https://www.sportfishingreport.com/dock_totals/boats.php?date=2026-06-28',
            'San Diego',
        );

        $this->assertSame(
            'https://www.sportfishingreport.com/dock_totals/boats.php?date=2026-06-28#:~:text=San%20Diego',
            $url,
        );
    }

    public function test_it_falls_back_when_source_url_or_boat_text_is_missing(): void
    {
        $builder = new SourceHighlightLinkBuilder;

        $this->assertNull($builder->build(null, 'Mission Belle'));
        $this->assertSame(
            'https://example.test/fishcounts.php#existing',
            $builder->build('https://example.test/fishcounts.php#existing', null),
        );
    }
}
