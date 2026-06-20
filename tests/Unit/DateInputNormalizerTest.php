<?php

namespace Tests\Unit;

use App\Support\DateInputNormalizer;
use PHPUnit\Framework\TestCase;

class DateInputNormalizerTest extends TestCase
{
    public function test_it_converts_american_dates_to_iso_dates(): void
    {
        $this->assertSame('2026-01-05', DateInputNormalizer::toIsoDate('01/05/2026'));
        $this->assertSame('2026-01-05', DateInputNormalizer::toIsoDate('1/5/2026'));
    }

    public function test_it_keeps_valid_iso_dates_as_iso_dates(): void
    {
        $this->assertSame('2026-01-05', DateInputNormalizer::toIsoDate('2026-01-05'));
    }

    public function test_it_leaves_invalid_dates_unchanged_for_validation(): void
    {
        $this->assertSame('02/31/2026', DateInputNormalizer::toIsoDate('02/31/2026'));
        $this->assertSame('2026-02-31', DateInputNormalizer::toIsoDate('2026-02-31'));
        $this->assertSame('', DateInputNormalizer::toIsoDate(''));
    }
}
