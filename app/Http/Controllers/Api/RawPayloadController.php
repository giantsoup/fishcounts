<?php

namespace App\Http\Controllers\Api;

use App\Actions\Parsing\EvaluateRawPayload;
use App\Http\Controllers\Controller;
use App\Http\Resources\RawPayloadBodyResource;
use App\Http\Resources\RawPayloadEvaluationResource;
use App\Http\Resources\RawPayloadResource;
use App\Models\RawScrapePayload;
use App\Services\Parsing\DiagnosticContextFactory;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class RawPayloadController extends Controller
{
    public function show(RawScrapePayload $rawScrapePayload): RawPayloadResource
    {
        return RawPayloadResource::make($rawScrapePayload->load(['scrapeSource', 'authoritativeParserExecution']));
    }

    public function body(RawScrapePayload $rawScrapePayload): RawPayloadBodyResource
    {
        return RawPayloadBodyResource::make($rawScrapePayload);
    }

    /**
     * Dry-run deterministic parse. Needs only the read ability because it writes nothing.
     */
    public function evaluate(
        RawScrapePayload $rawScrapePayload,
        EvaluateRawPayload $evaluateRawPayload,
        DiagnosticContextFactory $redactor,
    ): RawPayloadEvaluationResource|JsonResponse {
        try {
            $evaluation = $evaluateRawPayload->handle($rawScrapePayload->id);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $redactor->redactSecrets($exception->getMessage())], 422);
        }

        return RawPayloadEvaluationResource::make($evaluation);
    }
}
