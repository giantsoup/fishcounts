<?php

namespace App\DTOs;

use App\Enums\CatchQuantityBasis;
use InvalidArgumentException;

final readonly class ParsedSpeciesCountData
{
    /** @param array<string, mixed> $evidence */
    public function __construct(
        public string $speciesName,
        public ?int $count,
        public int $releasedCount = 0,
        public ?string $rawText = null,
        public ?int $canonicalSpeciesId = null,
        public CatchQuantityBasis $quantityBasis = CatchQuantityBasis::Explicit,
        public bool $isLimits = false,
        public array $evidence = [],
    ) {
        if (($count === null) !== ($quantityBasis === CatchQuantityBasis::LimitsUnquantified)
            || ($count !== null && ($count < 0 || $count > 4294967295)) || $releasedCount < 0 || $releasedCount > 4294967295
            || (in_array($quantityBasis, [CatchQuantityBasis::LimitsUnquantified, CatchQuantityBasis::LimitsEstimated], true) && ! $isLimits)
            || $quantityBasis === CatchQuantityBasis::Mixed) {
            throw new InvalidArgumentException('Invalid catch quantity state.');
        }
    }

    /** @return array<string, mixed> */
    public function component(): array
    {
        return [
            'species' => $this->speciesName,
            'count' => $this->count,
            'released' => $this->releasedCount,
            'basis' => $this->quantityBasis->value,
            'limits' => $this->isLimits,
            'raw_text' => $this->rawText,
            'evidence' => $this->evidence,
        ];
    }
}
