<?php

namespace App\Services\Environmental;

use App\DTOs\EnvironmentalFetchResult;
use App\Models\EnvironmentalPayload;
use App\Models\EnvironmentalSource;
use Carbon\CarbonImmutable;

class EnvironmentalPayloadStore
{
    public function store(EnvironmentalSource $source, CarbonImmutable $date, EnvironmentalFetchResult $result): EnvironmentalPayload
    {
        $payloadHash = hash('sha256', $result->body);
        $payload = EnvironmentalPayload::query()
            ->where('environmental_source_id', $source->id)
            ->whereDate('observed_date', $date->toDateString())
            ->where('payload_hash', $payloadHash)
            ->first();

        if ($payload !== null) {
            return $payload;
        }

        return EnvironmentalPayload::query()->create([
            'environmental_source_id' => $source->id,
            'location_profile' => $source->location_profile,
            'observed_date' => $date->toDateString(),
            'payload_hash' => $payloadHash,
            'url' => $result->url,
            'http_status' => $result->statusCode,
            'content_type' => $result->contentType,
            'payload' => $result->body,
            'fetched_at' => $result->fetchedAt,
            'metadata' => $result->metadata,
        ]);
    }
}
