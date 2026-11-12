<?php

namespace App\Actions\Boats;

use App\Enums\CanonicalEntityType;
use App\Enums\ParserErrorResolutionType;
use App\Exceptions\BoatAliasWouldMergeBoat;
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
use Illuminate\Validation\ValidationException;

class ConsolidateBoatAlias
{
    public function __construct(private readonly TripReportNormalizer $tripReportNormalizer) {}

    /**
     * @param  bool  $allowBoatMerge  When false, an alias that would merge in another boat is refused with
     *                                BoatAliasWouldMergeBoat instead, so merging has to be asked for explicitly.
     *
     * @throws BoatAliasWouldMergeBoat when merging is not allowed and the alias already names another boat.
     */
    public function handle(
        Boat $canonicalBoat,
        string $alias,
        string $normalizedAlias,
        ?int $resolvedByUserId,
        ParserErrorResolutionType $resolutionType = ParserErrorResolutionType::Alias,
        bool $consolidateExistingBoats = true,
        bool $allowBoatMerge = true,
    ): BoatAlias {
        return DB::transaction(function () use ($canonicalBoat, $alias, $normalizedAlias, $resolvedByUserId, $resolutionType, $consolidateExistingBoats, $allowBoatMerge): BoatAlias {
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

            if (! $consolidateExistingBoats && ($boatAlias !== null || $variantBoat !== null)) {
                throw ValidationException::withMessages(['alias' => 'This boat alias requires manual consolidation.']);
            }

            if (! $allowBoatMerge && $variantBoat !== null) {
                throw new BoatAliasWouldMergeBoat($variantBoat);
            }

            return $this->consolidate($canonicalBoat, $boatAlias, $variantBoat, $alias, $normalizedAlias, $resolvedByUserId, $resolutionType);
        }, attempts: 3);
    }

    /**
     * Merge a duplicate boat into the canonical one: its trip reports, aliases, and alert rules move
     * over, its name becomes an alias of the canonical boat, and it is deactivated.
     *
     * @throws ValidationException when the duplicate's name is already an alias of a third boat.
     */
    public function merge(
        Boat $canonicalBoat,
        Boat $duplicateBoat,
        ?int $resolvedByUserId,
        ParserErrorResolutionType $resolutionType = ParserErrorResolutionType::Alias,
    ): Boat {
        return DB::transaction(function () use ($canonicalBoat, $duplicateBoat, $resolvedByUserId, $resolutionType): Boat {
            $canonicalBoat = Boat::query()->lockForUpdate()->findOrFail($canonicalBoat->id);
            $duplicateBoat = Boat::query()->lockForUpdate()->findOrFail($duplicateBoat->id);
            $normalizedAlias = BoatNameNormalizer::normalize($duplicateBoat->name);
            $boatAlias = BoatAlias::query()
                ->where('normalized_alias', $normalizedAlias)
                ->lockForUpdate()
                ->first();

            if ($boatAlias !== null && ! in_array($boatAlias->boat_id, [$canonicalBoat->id, $duplicateBoat->id], true)) {
                throw ValidationException::withMessages([
                    'duplicate_boat_id' => "The duplicate boat's name is already an alias of a different boat. Fix that alias first.",
                ]);
            }

            $this->consolidate($canonicalBoat, $boatAlias, $duplicateBoat, $duplicateBoat->name, $normalizedAlias, $resolvedByUserId, $resolutionType);

            return $duplicateBoat->refresh();
        }, attempts: 3);
    }

    /**
     * Point the alias at the canonical boat, re-point matching unassigned trip reports, fold in the
     * variant boat when there is one, and resolve the matching open alias errors. Runs inside the
     * caller's transaction with the boats and alias already locked.
     */
    private function consolidate(
        Boat $canonicalBoat,
        ?BoatAlias $boatAlias,
        ?Boat $variantBoat,
        string $alias,
        string $normalizedAlias,
        ?int $resolvedByUserId,
        ParserErrorResolutionType $resolutionType,
    ): BoatAlias {
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
            ->where('error_type', CanonicalEntityType::Boat->aliasErrorType())
            ->where('raw_field', 'boat')
            ->whereNotNull('raw_value')
            ->get(['id', 'raw_value'])
            ->filter(fn (ParserError $parserError): bool => BoatNameNormalizer::normalize($parserError->raw_value) === $normalizedAlias)
            ->modelKeys();

        ParserError::query()->whereKey($parserErrorIds)->update([
            'resolved_at' => now(),
            'resolved_by_user_id' => $resolvedByUserId,
            'resolution_type' => $resolutionType->value,
        ]);

        $this->tripReportNormalizer->refreshPrimaryReportsForDates(
            $affectedDates->map(fn ($date): string => $date->toDateString())->all(),
        );

        return $boatAlias;
    }
}
