<?php

namespace App\Actions\Boats;

use App\Enums\ParserErrorResolutionType;
use App\Models\AlertRule;
use App\Models\Boat;
use App\Models\BoatAlias;
use App\Models\ParserError;
use App\Models\TripReport;
use App\Services\Parsing\BoatNameNormalizer;
use App\Services\Parsing\TripReportNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConsolidateBoatAlias
{
    public function __construct(private readonly TripReportNormalizer $tripReportNormalizer) {}

    public function handle(Boat $canonicalBoat, string $alias, string $normalizedAlias, int $resolvedByUserId): BoatAlias
    {
        return DB::transaction(function () use ($canonicalBoat, $alias, $normalizedAlias, $resolvedByUserId): BoatAlias {
            $canonicalBoat = Boat::query()->lockForUpdate()->findOrFail($canonicalBoat->id);
            $boatAlias = BoatAlias::query()
                ->where('normalized_alias', $normalizedAlias)
                ->lockForUpdate()
                ->first();
            $variantBoat = $boatAlias?->boat_id !== null && $boatAlias->boat_id !== $canonicalBoat->id
                ? Boat::query()->lockForUpdate()->find($boatAlias->boat_id)
                : Boat::query()
                    ->whereKeyNot($canonicalBoat->id)
                    ->where('slug', Str::slug($alias))
                    ->lockForUpdate()
                    ->first();

            if ($boatAlias === null) {
                $boatAlias = BoatAlias::query()->create([
                    'boat_id' => $canonicalBoat->id,
                    'alias' => $alias,
                    'normalized_alias' => $normalizedAlias,
                ]);
            }

            if ($variantBoat !== null) {
                $canonicalBoat->update([
                    'landing_id' => $canonicalBoat->landing_id ?? $variantBoat->landing_id,
                    'booking_url' => $canonicalBoat->booking_url ?? $variantBoat->booking_url,
                    'booking_provider_identifier' => $canonicalBoat->booking_provider_identifier ?? $variantBoat->booking_provider_identifier,
                ]);
            }

            $matchingRawBoatNames = TripReport::query()
                ->whereNull('boat_id')
                ->whereNotNull('raw_boat_name')
                ->distinct()
                ->pluck('raw_boat_name')
                ->filter(fn (string $rawBoatName): bool => BoatNameNormalizer::normalize($rawBoatName) === $normalizedAlias)
                ->values();

            $reports = TripReport::query()
                ->where(function (Builder $query) use ($matchingRawBoatNames, $variantBoat): void {
                    $query->whereKey([]);

                    if ($matchingRawBoatNames->isNotEmpty()) {
                        $query->orWhere(function (Builder $query) use ($matchingRawBoatNames): void {
                            $query->whereNull('boat_id')->whereIn('raw_boat_name', $matchingRawBoatNames);
                        });
                    }

                    if ($variantBoat !== null) {
                        $query->orWhere('boat_id', $variantBoat->id);
                    }
                });

            $affectedDates = (clone $reports)
                ->select('trip_date')
                ->distinct()
                ->pluck('trip_date');

            (clone $reports)
                ->select(['id', 'trip_date', 'raw_trip_type', 'anglers'])
                ->chunkById(500, function (Collection $tripReports) use ($canonicalBoat): void {
                    $tripReports
                        ->groupBy(fn (TripReport $tripReport): string => $this->tripReportNormalizer->dedupeKey(
                            $tripReport->trip_date->toDateString(),
                            $canonicalBoat->name,
                            $tripReport->raw_trip_type,
                            $tripReport->anglers,
                        ))
                        ->each(fn (Collection $reports, string $dedupeKey) => TripReport::query()
                            ->whereKey($reports->modelKeys())
                            ->update(['dedupe_key' => $dedupeKey]));
                });
            (clone $reports)->update(['boat_id' => $canonicalBoat->id]);

            if ($variantBoat !== null) {
                $variantBoat->aliases()->update(['boat_id' => $canonicalBoat->id]);

                AlertRule::query()
                    ->whereHas('boats', fn ($query) => $query->whereKey($variantBoat->id))
                    ->each(function (AlertRule $alertRule) use ($canonicalBoat, $variantBoat): void {
                        $alertRule->boats()->syncWithoutDetaching([$canonicalBoat->id]);
                        $alertRule->boats()->detach($variantBoat->id);
                    });

                $variantBoat->update(['is_active' => false]);
            }

            $boatAlias->update(['boat_id' => $canonicalBoat->id]);

            $parserErrorIds = ParserError::query()
                ->whereNull('resolved_at')
                ->where('error_type', 'unknown_boat_alias')
                ->where('raw_field', 'boat')
                ->whereNotNull('raw_value')
                ->get(['id', 'raw_value'])
                ->filter(fn (ParserError $parserError): bool => BoatNameNormalizer::normalize($parserError->raw_value) === $normalizedAlias)
                ->modelKeys();

            ParserError::query()->whereKey($parserErrorIds)->update([
                'resolved_at' => now(),
                'resolved_by_user_id' => $resolvedByUserId,
                'resolution_type' => ParserErrorResolutionType::Alias->value,
            ]);

            $this->tripReportNormalizer->refreshPrimaryReportsForDates(
                $affectedDates->map(fn ($date): string => $date->toDateString())->all(),
            );

            return $boatAlias;
        }, attempts: 3);
    }
}
