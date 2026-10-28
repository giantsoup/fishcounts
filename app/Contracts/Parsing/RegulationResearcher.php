<?php

namespace App\Contracts\Parsing;

interface RegulationResearcher
{
    /** @return array<string, mixed>|null */
    public function find(string $jurisdiction, string $taxon, string $date): ?array;

    public function research(string $jurisdiction, string $taxon, string $date): void;

    public function fingerprint(): string;
}
