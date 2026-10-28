<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use LogicException;

#[Guarded(['id'])]
class RegulationRuleSnapshot extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['rule' => 'array', 'effective_from' => 'immutable_date', 'effective_until' => 'immutable_date', 'verified_at' => 'immutable_datetime', 'retrieved_at' => 'immutable_datetime'];
    }

    /** @param array<string, mixed> $rule */
    public static function fingerprint(array $rule): string
    {
        foreach ($rule['sources'] ?? [] as $index => $source) {
            unset($rule['sources'][$index]['retrieved_at']);
        }

        return hash('sha256', json_encode(Arr::sortRecursive($rule), JSON_THROW_ON_ERROR));
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Regulation snapshots are immutable. Create a new snapshot.'));
        static::deleting(fn () => throw new LogicException('Retain regulation snapshots for historical replay.'));
    }
}
