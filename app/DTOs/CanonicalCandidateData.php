<?php

namespace App\DTOs;

use App\Enums\CanonicalEntityType;

final readonly class CanonicalCandidateData
{
    /** @param list<string> $aliases */
    public function __construct(
        public CanonicalEntityType $type,
        public int $id,
        public string $name,
        public array $aliases = [],
    ) {}

    /** @return array{type: string, id: int, name: string, aliases: list<string>} */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'id' => $this->id,
            'name' => $this->name,
            'aliases' => $this->aliases,
        ];
    }
}
