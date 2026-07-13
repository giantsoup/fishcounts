<?php

namespace App\Actions\Species;

use App\Enums\ParserErrorResolutionType;
use App\Models\ParserError;
use App\Models\Species;
use App\Models\SpeciesAlias;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateSpeciesAlias
{
    public function handle(Species $species, string $alias, string $normalizedAlias, int $resolvedByUserId): SpeciesAlias
    {
        return DB::transaction(function () use ($species, $alias, $normalizedAlias, $resolvedByUserId): SpeciesAlias {
            $species = Species::query()->lockForUpdate()->findOrFail($species->id);
            $speciesAlias = SpeciesAlias::query()
                ->where('normalized_alias', $normalizedAlias)
                ->lockForUpdate()
                ->first();

            if ($speciesAlias !== null && $speciesAlias->species_id !== $species->id) {
                throw ValidationException::withMessages(['alias' => 'This alias already belongs to another species.']);
            }

            $speciesAlias ??= SpeciesAlias::query()->create([
                'species_id' => $species->id,
                'alias' => $alias,
                'normalized_alias' => $normalizedAlias,
            ]);

            $parserErrorIds = ParserError::query()
                ->whereNull('resolved_at')
                ->where('error_type', 'unknown_species_alias')
                ->where('raw_field', 'species')
                ->whereNotNull('raw_value')
                ->get(['id', 'raw_value'])
                ->filter(fn (ParserError $parserError): bool => $this->normalize($parserError->raw_value) === $normalizedAlias)
                ->modelKeys();

            ParserError::query()->whereKey($parserErrorIds)->update([
                'resolved_at' => now(),
                'resolved_by_user_id' => $resolvedByUserId,
                'resolution_type' => ParserErrorResolutionType::Alias->value,
            ]);

            return $speciesAlias;
        }, attempts: 3);
    }

    private function normalize(string $alias): string
    {
        return str($alias)->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }
}
