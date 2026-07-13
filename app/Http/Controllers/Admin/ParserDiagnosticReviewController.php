<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Parsing\ActOnParserDiagnosticReview;
use App\Actions\Parsing\QueueParserDiagnosticReview;
use App\Actions\Parsing\ReverseAutomatedParserDiagnosticReview;
use App\Enums\QueueParserDiagnosticReviewResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ActOnParserDiagnosticReviewRequest;
use App\Http\Requests\Admin\QueueParserDiagnosticReviewRequest;
use App\Http\Requests\Admin\ReverseAutomatedParserDiagnosticReviewRequest;
use App\Models\ParserDiagnosticReview;
use App\Models\ParserDiagnosticReviewAction;
use App\Models\ParserError;
use Illuminate\Http\RedirectResponse;

class ParserDiagnosticReviewController extends Controller
{
    public function store(
        QueueParserDiagnosticReviewRequest $request,
        ParserError $parserError,
        QueueParserDiagnosticReview $action,
    ): RedirectResponse {
        $result = $action->handle($parserError, $request->user());

        return back()->with('status', match ($result) {
            QueueParserDiagnosticReviewResult::ExistingReview => 'An AI review is already available for this parser error.',
            QueueParserDiagnosticReviewResult::AlreadyQueued => 'An AI review is already queued or running for this payload.',
            QueueParserDiagnosticReviewResult::ReparseQueued => 'Payload queued for reparsing. AI review will run automatically if the diagnostic is still present.',
            QueueParserDiagnosticReviewResult::ReviewQueued => 'AI review queued.',
        });
    }

    public function accept(
        ActOnParserDiagnosticReviewRequest $request,
        ParserError $parserError,
        ParserDiagnosticReview $review,
        ActOnParserDiagnosticReview $action,
    ): RedirectResponse {
        $action->accept($parserError, $review, $request->user());

        return back()->with('status', 'AI recommendation accepted. The payload was reparsed and deduplicated.');
    }

    public function reject(
        ActOnParserDiagnosticReviewRequest $request,
        ParserError $parserError,
        ParserDiagnosticReview $review,
        ActOnParserDiagnosticReview $action,
    ): RedirectResponse {
        $recorded = $action->reject($parserError, $review, $request->user());

        return back()->with('status', $recorded ? 'AI recommendation rejected. The parser error remains open.' : 'This recommendation was already rejected.');
    }

    public function dismiss(
        ActOnParserDiagnosticReviewRequest $request,
        ParserError $parserError,
        ParserDiagnosticReview $review,
        ActOnParserDiagnosticReview $action,
    ): RedirectResponse {
        $recorded = $action->dismiss($parserError, $review, $request->user());

        return back()->with('status', $recorded ? 'Parser error dismissed.' : 'This parser error was already dismissed.');
    }

    public function retry(
        ActOnParserDiagnosticReviewRequest $request,
        ParserError $parserError,
        ParserDiagnosticReview $review,
        ActOnParserDiagnosticReview $action,
    ): RedirectResponse {
        $recorded = $action->retry($parserError, $review, $request->user());

        return back()->with('status', $recorded ? 'A fresh AI review was queued.' : 'A fresh review was already queued.');
    }

    public function leaveOpen(
        ActOnParserDiagnosticReviewRequest $request,
        ParserError $parserError,
        ParserDiagnosticReview $review,
        ActOnParserDiagnosticReview $action,
    ): RedirectResponse {
        $recorded = $action->leaveOpen($parserError, $review, $request->user());

        return back()->with('status', $recorded ? 'Decision recorded. The parser error remains open.' : 'This leave-open decision was already recorded.');
    }

    public function reverseAutomation(
        ReverseAutomatedParserDiagnosticReviewRequest $request,
        ParserError $parserError,
        ParserDiagnosticReview $review,
        ParserDiagnosticReviewAction $automaticAction,
        ReverseAutomatedParserDiagnosticReview $action,
    ): RedirectResponse {
        $action->handle($parserError, $review, $automaticAction, $request->user());

        return back()->with('status', 'The automatic alias resolution was reversed. Affected payloads were reparsed and deduplicated.');
    }
}
