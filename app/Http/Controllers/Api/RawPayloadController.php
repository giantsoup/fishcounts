<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RawPayloadResource;
use App\Models\RawScrapePayload;

class RawPayloadController extends Controller
{
    public function show(RawScrapePayload $rawScrapePayload): RawPayloadResource
    {
        return RawPayloadResource::make($rawScrapePayload->load(['scrapeSource', 'authoritativeParserExecution']));
    }
}
