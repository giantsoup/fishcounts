<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Parsing\ApproveParserReportOverride;
use App\Actions\Parsing\DisableParserReportOverride;
use App\Actions\Parsing\ProposeParserReportOverride;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ActOnParserReportOverrideRequest;
use App\Http\Requests\Admin\StoreParserReportOverrideRequest;
use App\Models\ParserDiagnosticReview;
use App\Models\ParserError;
use App\Models\ParserReportOverride;
use Illuminate\Http\RedirectResponse;

class ParserReportOverrideController extends Controller
{
    public function store(
        StoreParserReportOverrideRequest $request,
        ParserError $parserError,
        ParserDiagnosticReview $review,
        ProposeParserReportOverride $action,
    ): RedirectResponse {
        $override = $action->handle($parserError, $review, $request->user());

        return back()->with('status', $override->wasRecentlyCreated
            ? 'The report override proposal was created. Review the affected scope and corrected parse before approval.'
            : 'This report override proposal already exists.');
    }

    public function approve(
        ActOnParserReportOverrideRequest $request,
        ParserReportOverride $parserReportOverride,
        ApproveParserReportOverride $action,
    ): RedirectResponse {
        $action->handle($parserReportOverride, $request->user(), $request->validated('review_notes'));

        return back()->with('status', 'The report override was approved and the affected payload/date was reparsed and deduplicated.');
    }

    public function disable(
        ActOnParserReportOverrideRequest $request,
        ParserReportOverride $parserReportOverride,
        DisableParserReportOverride $action,
    ): RedirectResponse {
        $action->handle($parserReportOverride, $request->user(), $request->validated('disable_reason'));

        return back()->with('status', 'The report override was disabled and historical data was restored through the normal parser pipeline.');
    }
}
