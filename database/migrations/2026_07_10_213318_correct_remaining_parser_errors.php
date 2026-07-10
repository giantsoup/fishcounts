<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $timestamp = now();
            $hasSpeciesReferenceData = DB::table('species')->exists();
            $hasTripTypeReferenceData = DB::table('trip_types')->exists();

            foreach (['Pacific Mackerel', 'Halfmoon'] as $speciesName) {
                $this->ensureSpeciesExists($speciesName, $timestamp);
            }

            $this->consolidateTripType('3.5 Day', 'Long Range', $timestamp);

            foreach ([
                'bonito' => ['Bonita'],
            ] as $speciesSlug => $aliases) {
                $speciesId = DB::table('species')->where('slug', $speciesSlug)->value('id');

                if ($speciesId === null) {
                    if ($hasSpeciesReferenceData) {
                        throw new RuntimeException("Missing canonical species [{$speciesSlug}].");
                    }

                    continue;
                }

                foreach ($aliases as $alias) {
                    $this->upsertAlias('species_aliases', 'species_id', (int) $speciesId, $alias, $timestamp);
                }
            }

            foreach ([
                '3/4 Day' => ['6 Hour'],
                'Long Range' => ['3.5 Day', '4 Day'],
            ] as $tripTypeName => $aliases) {
                $tripTypeId = DB::table('trip_types')->where('name', $tripTypeName)->value('id');

                if ($tripTypeId === null) {
                    if ($hasTripTypeReferenceData) {
                        throw new RuntimeException("Missing canonical trip type [{$tripTypeName}].");
                    }

                    continue;
                }

                foreach ($aliases as $alias) {
                    $this->upsertAlias('trip_type_aliases', 'trip_type_id', (int) $tripTypeId, $alias, $timestamp);
                }
            }
        }, attempts: 3);
    }

    /**
     * These confirmed reference-data corrections are intentionally forward-only
     * because reparsed reports may depend on the species and aliases.
     */
    public function down(): void {}

    private function ensureSpeciesExists(string $name, Carbon $timestamp): void
    {
        $slug = Str::slug($name);
        $speciesId = DB::table('species')->where('slug', $slug)->value('id');

        if ($speciesId === null) {
            if (DB::table('species')->where('name', $name)->exists()) {
                throw new RuntimeException("Canonical species [{$name}] already exists under a different slug.");
            }

            DB::table('species')->insert([
                'name' => $name,
                'slug' => $slug,
                'is_active' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            return;
        }

        DB::table('species')->where('id', $speciesId)->update([
            'name' => $name,
            'is_active' => true,
            'updated_at' => $timestamp,
        ]);
    }

    private function upsertAlias(string $table, string $foreignKey, int $foreignId, string $alias, Carbon $timestamp): void
    {
        $normalizedAlias = Str::of($alias)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
        $existingAliases = DB::table($table)
            ->whereIn('normalized_alias', [
                $normalizedAlias,
                Str::of($alias)->lower()->squish()->toString(),
            ])
            ->orderBy('id')
            ->get(['id', 'normalized_alias']);
        $aliasId = $existingAliases->firstWhere('normalized_alias', $normalizedAlias)?->id
            ?? $existingAliases->first()?->id;

        if ($aliasId === null) {
            DB::table($table)->insert([
                $foreignKey => $foreignId,
                'alias' => $alias,
                'normalized_alias' => $normalizedAlias,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            return;
        }

        DB::table($table)
            ->whereIn('id', $existingAliases->pluck('id')->reject(fn (int $id): bool => $id === $aliasId))
            ->delete();
        DB::table($table)->where('id', $aliasId)->update([
            $foreignKey => $foreignId,
            'alias' => $alias,
            'normalized_alias' => $normalizedAlias,
            'updated_at' => $timestamp,
        ]);

    }

    private function consolidateTripType(string $legacyName, string $canonicalName, Carbon $timestamp): void
    {
        $legacyTripTypeId = DB::table('trip_types')->where('name', $legacyName)->value('id');

        if ($legacyTripTypeId === null) {
            return;
        }

        $canonicalTripTypeId = DB::table('trip_types')->where('name', $canonicalName)->value('id');

        if ($canonicalTripTypeId === null) {
            throw new RuntimeException("Missing canonical trip type [{$canonicalName}].");
        }

        DB::table('trip_reports')->where('trip_type_id', $legacyTripTypeId)->update(['trip_type_id' => $canonicalTripTypeId]);
        DB::table('trip_type_aliases')->where('trip_type_id', $legacyTripTypeId)->update(['trip_type_id' => $canonicalTripTypeId]);

        foreach (DB::table('alert_rule_trip_type')->where('trip_type_id', $legacyTripTypeId)->pluck('alert_rule_id') as $alertRuleId) {
            DB::table('alert_rule_trip_type')->updateOrInsert([
                'alert_rule_id' => $alertRuleId,
                'trip_type_id' => $canonicalTripTypeId,
            ]);
        }

        DB::table('alert_rule_trip_type')->where('trip_type_id', $legacyTripTypeId)->delete();
        DB::table('trip_types')->where('id', $legacyTripTypeId)->update([
            'is_active' => false,
            'updated_at' => $timestamp,
        ]);
    }
};
