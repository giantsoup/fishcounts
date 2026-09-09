<?php

namespace App\Services\Parsing;

use App\Enums\ParserReparseItemMode;
use App\Models\ParserError;
use App\Models\RawScrapePayload;
use App\Models\TripReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

class ParserReparsePlanner
{
    /**
     * @return array{open_errors: int, alias_errors: int, payloads: int, dates: int, trips: int, related_trips: int, skipped_errors: int, items: Collection<int, array{raw_scrape_payload_id: int, scrape_source_id: int, target_date: string, mode: ParserReparseItemMode, sequence: int}>, fingerprint: string}
     */
    public function preview(?int $sourceId = null, ?string $from = null, ?string $to = null): array
    {
        $errors = ParserError::query()->open()
            ->when($sourceId !== null, fn (Builder $query) => $query->where('scrape_source_id', $sourceId))
            ->when($from !== null, fn (Builder $query) => $query->whereDate('target_date', '>=', $from))
            ->when($to !== null, fn (Builder $query) => $query->whereDate('target_date', '<=', $to))
            ->orderBy('id')->get(['id', 'raw_scrape_payload_id', 'error_type', 'diagnostic_fingerprint', 'updated_at']);
        $payloads = RawScrapePayload::query()
            ->whereKey($errors->pluck('raw_scrape_payload_id')->filter()->unique())
            ->orderBy('target_date')->orderBy('scrape_source_id')->orderBy('fetched_at')->orderBy('id')
            ->get(['id', 'scrape_source_id', 'target_date', 'fetched_at', 'payload_hash']);
        $items = collect();
        $hashes = [];
        foreach ($payloads->groupBy(fn (RawScrapePayload $payload): string => $payload->scrape_source_id.'|'.$payload->target_date->toDateString()) as $group) {
            $affected = $group->last();
            $newest = RawScrapePayload::query()
                ->where('scrape_source_id', $affected->scrape_source_id)
                ->whereDate('target_date', $affected->target_date)
                ->latest('fetched_at')->latest('id')
                ->firstOrFail(['id', 'scrape_source_id', 'target_date', 'payload_hash']);
            foreach ($group->where('id', '!=', $newest->id)->push($newest) as $payload) {
                $items->push([
                    'raw_scrape_payload_id' => $payload->id,
                    'scrape_source_id' => $payload->scrape_source_id,
                    'target_date' => $payload->target_date->toDateString(),
                    'mode' => $payload->id === $newest->id ? ParserReparseItemMode::Authoritative : ParserReparseItemMode::DiagnosticsOnly,
                    'sequence' => $items->count() + 1,
                ]);
                $hashes[$payload->id] = $payload->payload_hash;
            }
        }
        $tripIds = TripReport::query()->whereExists(function (QueryBuilder $query) use ($payloads): void {
            $query->selectRaw('1')->from('raw_scrape_payloads')
                ->whereIn('raw_scrape_payloads.id', $payloads->modelKeys())
                ->whereColumn('raw_scrape_payloads.scrape_source_id', 'trip_reports.source_id')
                ->whereColumn('raw_scrape_payloads.target_date', 'trip_reports.trip_date');
        })->orderBy('id')->pluck('id');

        $relatedTripIds = TripReport::query()->whereExists(function (QueryBuilder $query) use ($payloads): void {
            $query->selectRaw('1')->from('raw_scrape_payloads')
                ->whereIn('raw_scrape_payloads.id', $payloads->modelKeys())
                ->whereColumn('raw_scrape_payloads.target_date', 'trip_reports.trip_date');
        })->whereNotIn('id', $tripIds)->orderBy('id')->pluck('id');
        $processableErrors = $errors->whereIn('raw_scrape_payload_id', $payloads->modelKeys());

        return [
            'skipped_errors' => $errors->count() - $processableErrors->count(),
            'related_trips' => $relatedTripIds->count(),
            'open_errors' => $processableErrors->count(),
            'alias_errors' => $processableErrors->filter(fn (ParserError $error): bool => in_array($error->error_type, ['unknown_boat_alias', 'unknown_species_alias', 'unknown_trip_type_alias'], true))->count(),
            'payloads' => $payloads->count(),
            'dates' => $items->pluck('target_date')->unique()->count(),
            'trips' => $tripIds->count(),
            'items' => $items,
            'fingerprint' => hash('sha256', json_encode([$sourceId, $from, $to, $errors->toArray(), $items->all(), $hashes, $tripIds->all(), $relatedTripIds->all()], JSON_THROW_ON_ERROR)),
        ];
    }
}
