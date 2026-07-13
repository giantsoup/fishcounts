<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Parsing\ActOnParserBugReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ActOnParserBugReportRequest;
use App\Models\ParserBugReport;
use App\Models\ParserDiagnosticReview;
use App\Models\ParserError;
use Illuminate\Http\RedirectResponse;

class ParserBugReportController extends Controller
{
    public function prepare(
        ActOnParserBugReportRequest $request,
        ParserError $parserError,
        ParserDiagnosticReview $review,
        ActOnParserBugReport $action,
    ): RedirectResponse {
        $action->prepare($parserError, $review);

        return back()->with('status', 'The parser-bug issue candidate was queued for preview generation.');
    }

    public function approve(
        ActOnParserBugReportRequest $request,
        ParserBugReport $parserBugReport,
        ActOnParserBugReport $action,
    ): RedirectResponse {
        $approved = $action->approve($parserBugReport, $request->user());

        return back()->with('status', $approved
            ? 'The parser-bug report was approved and queued. GitHub publication remains controlled by the write flag.'
            : 'This parser-bug report already has a GitHub issue.');
    }
}
