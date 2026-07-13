<?php

namespace App\Actions\TripTypes;

use App\Enums\ParserErrorResolutionType;
use App\Models\ParserError;
use App\Models\TripType;
use App\Models\TripTypeAlias;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateTripTypeAlias
{
    public function handle(
        TripType $tripType,
        string $alias,
        string $normalizedAlias,
        ?int $resolvedByUserId,
        ParserErrorResolutionType $resolutionType = ParserErrorResolutionType::Alias,
    ): TripTypeAlias {
        return DB::transaction(function () use ($tripType, $alias, $normalizedAlias, $resolvedByUserId, $resolutionType): TripTypeAlias {
            $tripType = TripType::query()->lockForUpdate()->findOrFail($tripType->id);
            $tripTypeAlias = TripTypeAlias::query()
                ->where('normalized_alias', $normalizedAlias)
                ->lockForUpdate()
                ->first();

            if ($tripTypeAlias !== null && $tripTypeAlias->trip_type_id !== $tripType->id) {
                throw ValidationException::withMessages(['alias' => 'This alias already belongs to another trip type.']);
            }

            $tripTypeAlias ??= TripTypeAlias::query()->create([
                'trip_type_id' => $tripType->id,
                'alias' => $alias,
                'normalized_alias' => $normalizedAlias,
            ]);

            $parserErrorIds = ParserError::query()
                ->whereNull('resolved_at')
                ->where('error_type', 'unknown_trip_type_alias')
                ->where('raw_field', 'trip_type')
                ->whereNotNull('raw_value')
                ->get(['id', 'raw_value'])
                ->filter(fn (ParserError $parserError): bool => $this->normalize($parserError->raw_value) === $normalizedAlias)
                ->modelKeys();

            ParserError::query()->whereKey($parserErrorIds)->update([
                'resolved_at' => now(),
                'resolved_by_user_id' => $resolvedByUserId,
                'resolution_type' => $resolutionType->value,
            ]);

            return $tripTypeAlias;
        }, attempts: 3);
    }

    private function normalize(string $alias): string
    {
        return str($alias)->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }
}
