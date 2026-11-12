<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PreviewReparseRunRequest;
use App\Http\Resources\ReparsePreviewResource;
use App\Services\Parsing\ParserReparsePlanner;

class ReparseRunController extends Controller
{
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
}
