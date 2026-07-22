<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Parsing\RetryParserReparseRun;
use App\Actions\Parsing\StartParserReparseRun;
use App\Enums\ParserReparseRunStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RetryParserReparseRunRequest;
use App\Http\Requests\Admin\StartParserReparseRunRequest;
use App\Models\ParserError;
use App\Models\ParserReparseRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ParserReparseRunController extends Controller
{
    public function store(StartParserReparseRunRequest $request, StartParserReparseRun $startRun): RedirectResponse
    {
        $result = $startRun->handle($request->user());

        return redirect()
            ->route('admin.parser-errors.index')
            ->with('status', $result->created
                ? "Parser reparse run #{$result->run->id} was queued."
                : "Parser reparse run #{$result->run->id} is already active.");
    }

    public function poll(ParserReparseRun $parserReparseRun): JsonResponse
    {
        Gate::authorize('view', $parserReparseRun);
        $parserReparseRun->load('requester');
        $showReparseRun = $parserReparseRun->status !== ParserReparseRunStatus::Succeeded;

        return response()->json([
            'html' => $showReparseRun
                ? view('admin.parser-errors._reparse-run', ['latestReparseRun' => $parserReparseRun])->render()
                : '',
            'has_active_reparse' => $parserReparseRun->status->isActive(),
            'show_reparse_run' => $showReparseRun,
            'can_start_reparse' => ParserError::query()->open()->whereNotNull('raw_scrape_payload_id')->exists(),
        ]);
    }

    public function retry(
        RetryParserReparseRunRequest $request,
        ParserReparseRun $parserReparseRun,
        RetryParserReparseRun $retryRun,
    ): RedirectResponse {
        $failedItems = $parserReparseRun->failed_items;
        $run = $retryRun->handle($parserReparseRun);

        return redirect()
            ->route('admin.parser-errors.index')
            ->with('status', match (true) {
                $failedItems === 0 => "Parser reparse run #{$run->id} has no failed items to retry.",
                $run->status->isActive() => "Retrying failed items from parser reparse run #{$run->id}.",
                default => 'Another parser reparse run is active. Retry this run after it finishes.',
            });
    }
}
