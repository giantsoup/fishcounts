<?php

namespace App\Services\Parsing;

use App\DTOs\ParsedFishCountCollection;
use App\DTOs\ParsedReportValidationData;
use App\DTOs\ParsedSpeciesCountData;
use App\DTOs\ParsedTripReportData;
use App\DTOs\ParserReportOverrideData;
use App\DTOs\RawPayloadData;
use App\Enums\CanonicalEntityType;
use App\Enums\ParserCorrectionField;
use App\Enums\ParserCorrectionOperation;
use App\Enums\ParserReportOverrideStatus;
use App\Models\Boat;
use App\Models\ParserReportOverride;
use App\Models\RawScrapePayload;
use App\Models\Species;
use App\Models\SpeciesAlias;
use App\Models\TripType;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ParserReportOverrideApplier
{
    private bool $isApplying = false;

    public function __construct(
        private readonly DiagnosticContextFactory $contextFactory,
        private readonly DiagnosticFingerprintFactory $fingerprintFactory,
        private readonly ParserReportOverrideValidator $validator,
    ) {}

    public function apply(
        RawScrapePayload $payload,
        RawPayloadData $rawPayload,
        ParsedFishCountCollection $parsed,
    ): ParsedFishCountCollection {
        if (! (bool) config('fish.parsing.overrides.enabled', false)
            || ! in_array($payload->scrapeSource->slug, config('fish.parsing.overrides.allowed_source_slugs', []), true)
            || $this->isApplying) {
            return $parsed;
        }

        $overrides = ParserReportOverride::query()
            ->whereBelongsTo($payload)
            ->where('status', ParserReportOverrideStatus::Active)
            ->orderBy('id')
            ->get();

        if ($overrides->isEmpty()) {
            return $parsed;
        }

        $this->isApplying = true;

        try {
            foreach ($overrides as $override) {
                try {
                    $identity = $this->identity($payload, $rawPayload, $parsed, $override->report_index);
                    $invalidationReason = $this->invalidationReason($override, $identity);

                    if ($invalidationReason !== null) {
                        $this->invalidate($override, $invalidationReason);

                        continue;
                    }

                    $corrections = $this->validator->validate($override->corrections, $override->report_index);
                    $parsed = $this->applyCorrections($parsed, $corrections);
                    $override->forceFill([
                        'first_applied_at' => $override->first_applied_at ?? now(),
                        'last_applied_at' => now(),
                    ])->save();
                } catch (ValidationException) {
                    $this->invalidate($override, 'correction_no_longer_valid');
                }
            }

            return $parsed;
        } finally {
            $this->isApplying = false;
        }
    }

    /**
     * @return array{report_index: int, report_fingerprint: string, paragraph_fingerprint: string, parser_version: string, correction_schema_version: string}
     */
    public function identity(
        RawScrapePayload $payload,
        RawPayloadData $rawPayload,
        ParsedFishCountCollection $parsed,
        int $reportIndex,
    ): array {
        /** @var ParsedTripReportData|null $report */
        $report = $parsed->tripReports->get($reportIndex);

        if ($report === null) {
            throw ValidationException::withMessages(['override' => 'The referenced parsed report no longer exists.']);
        }

        $parserVersion = (string) ($report->metadata['parser'] ?? $parsed->parserVersion ?? 'unknown');
        $format = (string) ($report->metadata['format'] ?? $parsed->format ?? 'unknown');
        $paragraph = $this->contextFactory->paragraphForReport($rawPayload, $report, $reportIndex);
        $data = new ParsedReportValidationData(
            payload: $rawPayload,
            parsed: $parsed,
            report: $report,
            reportIndex: $reportIndex,
            parserVersion: $parserVersion,
            format: $format,
            sourceIdentifier: isset($report->metadata['source_trip_identifier']) ? (string) $report->metadata['source_trip_identifier'] : null,
            sanitizedParagraph: $paragraph,
        );

        return [
            'report_index' => $reportIndex,
            'report_fingerprint' => $this->fingerprintFactory->report($data, $payload->payload_hash),
            'paragraph_fingerprint' => hash('sha256', $paragraph),
            'parser_version' => $parserVersion,
            'correction_schema_version' => (string) config('fish.parsing.overrides.schema_version', 'v1'),
        ];
    }

    /** @param list<ParserReportOverrideData> $corrections */
    public function applyCorrections(ParsedFishCountCollection $parsed, array $corrections): ParsedFishCountCollection
    {
        $reports = new Collection($parsed->tripReports->all());

        foreach ($corrections as $correction) {
            /** @var ParsedTripReportData|null $report */
            $report = $reports->get($correction->reportIndex);

            if ($report === null) {
                throw ValidationException::withMessages(['override' => 'The referenced parsed report no longer exists.']);
            }

            $reports = $reports->put($correction->reportIndex, $this->correctReport($report, $correction));
        }

        return new ParsedFishCountCollection(
            tripReports: $reports,
            parserVersion: $parsed->parserVersion,
            format: $parsed->format,
        );
    }

    /** @return array<string, mixed> */
    public function snapshot(ParsedTripReportData $report): array
    {
        return [
            'source' => $report->sourceKey,
            'date' => $report->tripDate->toDateString(),
            'boat' => $report->boatName,
            'boat_id' => $report->canonicalBoatId,
            'trip_type' => $report->tripTypeName,
            'trip_type_id' => $report->canonicalTripTypeId,
            'anglers' => $report->anglers,
            'species_counts' => collect($report->speciesCounts)->map(fn (ParsedSpeciesCountData $count): array => [
                'species' => $count->speciesName,
                'species_id' => $count->canonicalSpeciesId,
                'retained' => $count->count,
                'released' => $count->releasedCount,
            ])->values()->all(),
        ];
    }

    /** @param array<string, mixed> $identity */
    private function invalidationReason(ParserReportOverride $override, array $identity): ?string
    {
        return match (true) {
            $override->correction_schema_version !== $identity['correction_schema_version'] => 'correction_schema_changed',
            $override->parser_version !== $identity['parser_version'] => 'parser_version_changed',
            $override->paragraph_fingerprint !== $identity['paragraph_fingerprint'] => 'source_paragraph_changed',
            $override->report_fingerprint !== $identity['report_fingerprint'] => 'report_fingerprint_changed',
            default => null,
        };
    }

    private function invalidate(ParserReportOverride $override, string $reason): void
    {
        $override->forceFill([
            'status' => ParserReportOverrideStatus::Invalidated,
            'invalidated_at' => now(),
            'invalidation_reason' => $reason,
        ])->save();
    }

    private function correctReport(ParsedTripReportData $report, ParserReportOverrideData $correction): ParsedTripReportData
    {
        $boatName = $report->boatName;
        $tripTypeName = $report->tripTypeName;
        $anglers = $report->anglers;
        $speciesCounts = $report->speciesCounts;
        $canonicalBoatId = $report->canonicalBoatId;
        $canonicalTripTypeId = $report->canonicalTripTypeId;

        if (in_array($correction->operation, [ParserCorrectionOperation::MapAlias, ParserCorrectionOperation::ReplaceEntity], true)) {
            $targetName = $this->activeTargetName($correction->canonicalType, $correction->canonicalId);

            match ($correction->field) {
                ParserCorrectionField::Boat => [$boatName, $canonicalBoatId] = [$targetName, $correction->canonicalId],
                ParserCorrectionField::TripType => [$tripTypeName, $canonicalTripTypeId] = [$targetName, $correction->canonicalId],
                ParserCorrectionField::Species => $speciesCounts = $this->replaceSpeciesSelection($speciesCounts, $correction, $targetName),
                default => throw ValidationException::withMessages(['override' => 'The entity correction field is not supported.']),
            };
        } elseif ($correction->operation === ParserCorrectionOperation::SetAnglerCount) {
            $anglers = $correction->value;
        } elseif ($correction->operation === ParserCorrectionOperation::SetSpeciesCount) {
            $speciesCounts = $this->replaceSpeciesCount($speciesCounts, $correction);
        }

        return new ParsedTripReportData(
            sourceKey: $report->sourceKey,
            tripDate: $report->tripDate,
            regionName: $report->regionName,
            landingName: $report->landingName,
            boatName: $boatName,
            tripTypeName: $tripTypeName,
            anglers: $anglers,
            rawFishCountText: $report->rawFishCountText,
            speciesCounts: $speciesCounts,
            metadata: array_merge($report->metadata, ['report_override_applied' => true]),
            canonicalBoatId: $canonicalBoatId,
            canonicalTripTypeId: $canonicalTripTypeId,
        );
    }

    /**
     * @param  array<int, ParsedSpeciesCountData>  $speciesCounts
     * @return array<int, ParsedSpeciesCountData>
     */
    private function replaceSpeciesSelection(array $speciesCounts, ParserReportOverrideData $correction, string $targetName): array
    {
        $matched = false;
        $normalizedMatch = $this->normalize($correction->matchValue ?? '');
        $corrected = collect($speciesCounts)->map(function (ParsedSpeciesCountData $count) use (&$matched, $normalizedMatch, $targetName, $correction): ParsedSpeciesCountData {
            if ($this->normalize($count->speciesName) !== $normalizedMatch) {
                return $count;
            }

            $matched = true;

            return new ParsedSpeciesCountData(
                $targetName,
                $count->count,
                $count->releasedCount,
                $count->rawText,
                $correction->canonicalId,
            );
        })->all();

        if (! $matched) {
            throw ValidationException::withMessages(['override' => 'The existing species selection no longer matches this correction.']);
        }

        return $corrected;
    }

    /**
     * @param  array<int, ParsedSpeciesCountData>  $speciesCounts
     * @return array<int, ParsedSpeciesCountData>
     */
    private function replaceSpeciesCount(array $speciesCounts, ParserReportOverrideData $correction): array
    {
        $speciesId = $correction->canonicalId;
        $matched = false;
        $corrected = collect($speciesCounts)->map(function (ParsedSpeciesCountData $count) use (&$matched, $correction, $speciesId): ParsedSpeciesCountData {
            if (! $this->matchesSpecies($count->speciesName, $speciesId)) {
                return $count;
            }

            $matched = true;

            return new ParsedSpeciesCountData(
                $count->speciesName,
                $correction->retainedCount ?? 0,
                $correction->releasedCount ?? 0,
                $count->rawText,
                $correction->canonicalId,
            );
        })->all();

        if (! $matched) {
            throw ValidationException::withMessages(['override' => 'The count correction does not reference an existing species selection.']);
        }

        return $corrected;
    }

    private function activeTargetName(?CanonicalEntityType $type, ?int $id): string
    {
        if ($type === null || $id === null) {
            throw ValidationException::withMessages(['override' => 'The canonical target is missing.']);
        }

        $model = match ($type) {
            CanonicalEntityType::Boat => Boat::class,
            CanonicalEntityType::Species => Species::class,
            CanonicalEntityType::TripType => TripType::class,
        };
        $target = $model::query()->find($id);

        if ($target === null || ! $target->is_active) {
            throw ValidationException::withMessages(['override' => 'The canonical target is missing or inactive.']);
        }

        return $target->name;
    }

    private function matchesSpecies(string $name, ?int $speciesId): bool
    {
        if ($speciesId === null) {
            return false;
        }

        $normalized = $this->normalize($name);

        return Species::query()->whereKey($speciesId)->where('is_active', true)
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])->exists()
            || SpeciesAlias::query()->where('species_id', $speciesId)->where('normalized_alias', $normalized)->exists();
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }
}
