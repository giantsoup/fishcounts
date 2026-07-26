<?php

namespace App\Actions\Species;

use App\Enums\ParserErrorResolutionType;
use App\Models\ParserError;
use App\Models\Species;
use App\Models\SpeciesAlias;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateSpeciesAlias
{
    public function handle(
        Species $species,
        string $alias,
        string $normalizedAlias,
        ?int $resolvedByUserId,
        ParserErrorResolutionType $resolutionType = ParserErrorResolutionType::Alias,
    ): SpeciesAlias {
        $alias = Str::squish($alias);

        if (trim($normalizedAlias) === '') {
            throw ValidationException::withMessages(['alias' => 'The alias must contain letters or numbers.']);
        }

        return DB::transaction(function () use ($species, $alias, $normalizedAlias, $resolvedByUserId, $resolutionType): SpeciesAlias {
            $species = Species::query()->lockForUpdate()->findOrFail($species->id);
            $canonicalSpecies = Species::query()
                ->where('slug', Str::slug($normalizedAlias))
                ->lockForUpdate()
                ->first();

            if ($canonicalSpecies !== null && ! $canonicalSpecies->is($species)) {
                throw ValidationException::withMessages(['alias' => 'This name is already used by another canonical species.']);
            }

            $normalizedAliases = [
                $normalizedAlias,
                Str::of($alias)->lower()->squish()->toString(),
            ];
            $speciesAlias = SpeciesAlias::query()
                ->whereIn('normalized_alias', $normalizedAliases)
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
                'resolution_type' => $resolutionType->value,
            ]);

            return $speciesAlias;
        }, attempts: 3);
    }

    private function normalize(string $alias): string
    {
        return str($alias)->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }
}
