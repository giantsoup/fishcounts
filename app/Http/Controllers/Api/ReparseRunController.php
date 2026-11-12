<?php

namespace App\Http\Controllers\Api;

use App\Actions\Parsing\StartParserReparseRun;
use App\Enums\ParserReparseItemStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PreviewReparseRunRequest;
use App\Http\Requests\Api\StartReparseRunRequest;
use App\Http\Resources\ReparsePreviewResource;
use App\Http\Resources\ReparseRunResource;
use App\Models\ParserReparseRun;
use App\Services\Parsing\ParserReparsePlanner;
use App\Support\ApiAudit;
use Illuminate\Http\JsonResponse;

class ReparseRunController extends Controller
{
    /** The most failed items a run's JSON lists. */
    public const MAX_LISTED_FAILURES = 50;

    public function preview(PreviewReparseRunRequest $request, ParserReparsePlanner $planner): ReparsePreviewResource
    {
        [$source, $from, $to] = $request->scope();

        return ReparsePreviewResource::make([
            'source' => $source,
            'from' => $from,
            'to' => $to,
            'plan' => $planner->preview($source, $from, $to),
        ]);
    }

    /**
     * Start a run for the previewed scope. When another run is already active, that run is returned
     * with `meta.created` false and status 200 instead of starting a second one.
     */
    public function store(StartReparseRunRequest $request, StartParserReparseRun $startRun): JsonResponse
    {
        [$source, $from, $to] = $request->scope();
        $result = $startRun->handle($request->user(), $source, $from, $to, $request->validated('fingerprint'));

        if ($result->created) {
            ApiAudit::write($request, 'reparse-run.start', $result->run->id);
        }

        return $this->runResource($result->run)
            ->additional(['meta' => ['created' => $result->created]])
            ->response()
            ->setStatusCode($result->created ? 201 : 200);
    }

    private function runResource(ParserReparseRun $run): ReparseRunResource
    {
        return ReparseRunResource::make($run->load(['items' => fn ($query) => $query
            ->where('status', ParserReparseItemStatus::Failed)
            ->orderBy('sequence')
            ->limit(self::MAX_LISTED_FAILURES)]));
    }
}
