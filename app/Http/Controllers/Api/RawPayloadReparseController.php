<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\QueuedPayloadReparseResource;
use App\Jobs\ParseRawPayloadJob;
use App\Models\RawScrapePayload;
use App\Support\ApiAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;

class RawPayloadReparseController extends Controller
{
    /**
     * Queue one payload for parsing with its source's engine, the same job the admin UI's reparse button queues.
     */
    public function __invoke(Request $request, RawScrapePayload $rawScrapePayload): JsonResponse
    {
        $rawScrapePayload->loadMissing('scrapeSource');
        ParseRawPayloadJob::dispatch(
            rawScrapePayloadId: $rawScrapePayload->id,
            parserEngine: $rawScrapePayload->scrapeSource->parser_engine,
        );
        ApiAudit::write($request, 'raw-payload.reparse', $rawScrapePayload->id);

        return QueuedPayloadReparseResource::make($rawScrapePayload)->response()->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
