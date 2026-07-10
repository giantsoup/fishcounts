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

            foreach ($this->speciesAliases() as $speciesSlug => $aliases) {
                $speciesId = DB::table('species')->where('slug', $speciesSlug)->value('id');

                if ($speciesId === null) {
                    if ($hasSpeciesReferenceData) {
                        throw new RuntimeException("Missing canonical species [{$speciesSlug}].");
                    }

                    continue;
                }

                foreach ($aliases as $alias) {
                    $this->insertSpeciesAlias((int) $speciesId, $alias, $timestamp);
                }
            }

            foreach ($this->tripTypeAliases() as $tripTypeName => $aliases) {
                $tripTypeId = DB::table('trip_types')->where('name', $tripTypeName)->value('id');

                if ($tripTypeId === null) {
                    if ($hasTripTypeReferenceData) {
                        throw new RuntimeException("Missing canonical trip type [{$tripTypeName}].");
                    }

                    continue;
                }

                foreach ($aliases as $alias) {
                    $this->insertTripTypeAlias((int) $tripTypeId, $alias, $timestamp);
                }
            }
        });
    }

    /**
     * These reference-data corrections are intentionally forward-only so a
     * rollback cannot delete aliases that may have since been used or edited.
     */
    public function down(): void {}

    /** @return array<string, list<string>> */
    private function speciesAliases(): array
    {
        return [
            'yellowtail' => ['Yelowtail'],
            'bluefin-tuna' => ['Bleufin Tuna'],
            'bonito' => ['Bontio'],
            'barracuda' => ['Baracuda'],
            'cabezon' => ['Cabazon'],
            'rockfish' => ['Assorted Rockfish'],
            'lingcod' => ['Lings'],
            'white-seabass' => ['White Sea Bass'],
        ];
    }

    /** @return array<string, list<string>> */
    private function tripTypeAliases(): array
    {
        return [
            '3/4 Day' => ['3/4 Day Local'],
            'Full Day' => ['Full Day Local'],
        ];
    }

    private function insertSpeciesAlias(int $speciesId, string $alias, Carbon $timestamp): void
    {
        $normalizedAlias = $this->normalize($alias);
        $existingAlias = DB::table('species_aliases')
            ->where('normalized_alias', $normalizedAlias)
            ->first(['id', 'species_id']);

        if ($existingAlias === null) {
            DB::table('species_aliases')->insert([
                'species_id' => $speciesId,
                'alias' => $alias,
                'normalized_alias' => $normalizedAlias,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            return;
        }

        DB::table('species_aliases')->where('id', $existingAlias->id)->update([
            'species_id' => $speciesId,
            'alias' => $alias,
            'updated_at' => $timestamp,
        ]);
    }

    private function insertTripTypeAlias(int $tripTypeId, string $alias, Carbon $timestamp): void
    {
        $normalizedAlias = Str::of($alias)->lower()->squish()->toString();

        $existingAliasId = DB::table('trip_type_aliases')
            ->where('normalized_alias', $normalizedAlias)
            ->value('id');

        if ($existingAliasId !== null) {
            DB::table('trip_type_aliases')->where('id', $existingAliasId)->update([
                'trip_type_id' => $tripTypeId,
                'alias' => $alias,
                'updated_at' => $timestamp,
            ]);

            return;
        }

        DB::table('trip_type_aliases')->insert([
            'trip_type_id' => $tripTypeId,
            'alias' => $alias,
            'normalized_alias' => $normalizedAlias,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    private function normalize(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }
};
