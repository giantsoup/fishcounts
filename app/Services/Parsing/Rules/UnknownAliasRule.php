<?php

namespace App\Services\Parsing\Rules;

use App\Contracts\Parsing\ParsedReportDiagnosticRule;
use App\DTOs\ParsedReportValidationData;
use App\DTOs\ParserDiagnosticFindingData;
use App\Enums\ParserDiagnosticType;
use App\Models\Boat;
use App\Models\BoatAlias;
use App\Models\Species;
use App\Models\SpeciesAlias;
use App\Models\TripType;
use App\Models\TripTypeAlias;
use App\Services\Parsing\BoatNameNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class UnknownAliasRule implements ParsedReportDiagnosticRule
{
    public function inspect(ParsedReportValidationData $data): array
    {
        if ($data->report === null) {
            return [];
        }

        $findings = [];

        if ($data->report->boatName !== null && ! $this->knownBoat($data->report->boatName)) {
            $findings[] = $this->finding('boat', $data->report->boatName);
        }

        if ($data->report->tripTypeName !== null && ! $this->knownTripType($data->report->tripTypeName)) {
            $findings[] = $this->finding('trip_type', $data->report->tripTypeName);
        }

        foreach ($data->report->speciesCounts as $speciesCount) {
            if (! $this->knownSpecies($speciesCount->speciesName)) {
                $findings[] = $this->finding('species', $speciesCount->speciesName);
            }
        }

        return $findings;
    }

    private function knownBoat(string $value): bool
    {
        $normalized = BoatNameNormalizer::normalize($value);

        return BoatAlias::query()
            ->whereHas('boat', fn (Builder $query): Builder => $query->where('is_active', true))
            ->whereIn('normalized_alias', [$normalized, $this->simpleNormalization($value)])
            ->exists()
            || Boat::query()->where('slug', Str::slug($value))->where('is_active', true)->exists();
    }

    private function knownTripType(string $value): bool
    {
        return TripTypeAlias::query()
            ->whereIn('normalized_alias', [$this->normalization($value), $this->simpleNormalization($value)])
            ->exists()
            || TripType::query()->where('slug', Str::slug($value))->exists();
    }

    private function knownSpecies(string $value): bool
    {
        return SpeciesAlias::query()
            ->whereIn('normalized_alias', [$this->normalization($value), $this->simpleNormalization($value)])
            ->exists()
            || Species::query()->where('slug', Str::slug($value))->exists();
    }

    private function finding(string $field, string $value): ParserDiagnosticFindingData
    {
        return new ParserDiagnosticFindingData(
            type: ParserDiagnosticType::UnknownAlias,
            field: $field,
            rawValue: $value,
            message: "Unknown {$field} alias [{$value}].",
            evidence: ['lookup' => 'canonical_and_alias_tables', 'matched' => false],
        );
    }

    private function normalization(string $value): string
    {
        return Str::of($value)->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }

    private function simpleNormalization(string $value): string
    {
        return Str::of($value)->lower()->squish()->toString();
    }
}
