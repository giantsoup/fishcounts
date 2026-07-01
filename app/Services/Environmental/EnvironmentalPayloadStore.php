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
        $identity = [
            'environmental_source_id' => $source->id,
            'observed_date' => $date->startOfDay(),
            'payload_hash' => $payloadHash,
        ];

        return EnvironmentalPayload::query()->createOrFirst($identity, [
            'location_profile' => $source->location_profile,
            'location_type' => $source->location_type->value,
            'url' => $result->url,
            'http_status' => $result->statusCode,
            'content_type' => $result->contentType,
            'payload' => $result->body,
            'fetched_at' => $result->fetchedAt,
            'metadata' => $result->metadata,
        ]);
    }
}
